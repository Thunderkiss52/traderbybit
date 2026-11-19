<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\Exchanges\ExchangeRegistry;
use App\MarketData\Application\MarketCandleConflictService;
use App\MarketData\Domain\CandleIntegrityUnavailable;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Infrastructure\Persistence\JournalEventRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

final class SystemFreshnessService
{
    private MarketCandleConflictService $conflictService;

    public function __construct(
        ?MarketCandleConflictService $conflictService = null,
    ) {
        $this->conflictService = $conflictService ?? app(MarketCandleConflictService::class);
    }

    /**
     * Inspect system health, candle freshness, queue lag, and connection latencies.
     *
     * @return array<string, mixed>
     */
    public function check(?string $exchangeFilter = null, bool $alertOnMissing = false): array
    {
        $now = CarbonImmutable::now('UTC');
        $candleHealth = [];
        $alerts = [];
        $hasConflicts = false;
        $integrityUnavailable = false;
        $hasMissingOrStale = false;

        $symbols = (array) config('ui.symbols', ['BTCUSDT', 'ETHUSDT']);
        $intervals = (array) config('market_data.intervals', ['5', '15', '30', '60', '240']);
        $registered = app(ExchangeRegistry::class)->registeredIds();
        $exchanges = $exchangeFilter !== null ? [$exchangeFilter] : $registered;

        $scopes = [];
        foreach ($exchanges as $exchange) {
            foreach ($symbols as $symbol) {
                foreach ($intervals as $interval) {
                    $scopes[] = compact('exchange', 'symbol', 'interval');
                }
            }
        }
        // Reuse one independent primary session; connection failures become UNAVAILABLE per scope.
        $reports = $this->conflictService->integrityReports($scopes);
        $reportIndex = 0;

        $dbConnectionFailed = false;
        foreach ($exchanges as $exchange) {
            foreach ($symbols as $symbol) {
                foreach ($intervals as $interval) {
                    $candleReadFailed = $dbConnectionFailed;
                    $latest = null;
                    if (! $dbConnectionFailed) {
                        try {
                            $latest = CandleRecord::query()
                                ->where('exchange', $exchange)
                                ->where('symbol', $symbol)
                                ->where('interval', $interval)
                                ->where('is_closed', true)
                                ->latest('open_time')
                                ->first(['open_time', 'updated_at']);

                        } catch (\Throwable) {
                            $latest = null;
                            $candleReadFailed = true;
                            $dbConnectionFailed = true;
                        }
                    }

                    $intervalMinutes = (int) $interval;
                    $expectedClose = null;
                    $lagSeconds = null;
                    $isStale = false;

                    if ($latest?->open_time !== null) {
                        $openTime = CarbonImmutable::instance($latest->open_time);
                        $expectedClose = $openTime->addMinutes($intervalMinutes);
                        $lagSeconds = max(0, $now->getTimestamp() - $expectedClose->getTimestamp());
                        $missingThresholdSeconds = $intervalMinutes * 60 * 2;
                        $isStale = $lagSeconds > $missingThresholdSeconds;
                    }

                    $report = $reports[$reportIndex++];
                    $integrityReport = $candleReadFailed ? ['status' => 'UNAVAILABLE', 'reason_code' => 'CANDLE_STORAGE_UNAVAILABLE'] : $report;
                    $integrity = $integrityReport['status'];
                    $hasConflict = $integrity === 'UNAVAILABLE' ? null : $integrity === 'CONFLICTED';

                    if ($integrity === 'UNAVAILABLE') {
                        $status = 'UNAVAILABLE';
                        $integrityUnavailable = true;
                        $alerts[] = [
                            'code' => 'CANDLE_INTEGRITY_UNAVAILABLE', 'severity' => 'ERROR',
                            'diagnostic_code' => $integrityReport['reason_code'],
                            'exchange' => $exchange, 'symbol' => $symbol, 'interval' => $interval,
                            'message' => "Candle integrity unavailable for {$exchange} {$symbol} {$interval}m.",
                        ];
                    } elseif ($hasConflict) {
                        $status = 'CONFLICTED';
                        $hasConflicts = true;
                        $alerts[] = [
                            'code' => 'CANDLE_INTEGRITY_CONFLICT',
                            'severity' => 'ERROR',
                            'exchange' => $exchange,
                            'symbol' => $symbol,
                            'interval' => $interval,
                            'message' => "Unresolved immutable candle conflict detected for {$exchange} {$symbol} {$interval}m.",
                        ];
                    } elseif ($latest === null) {
                        $status = 'MISSING';
                        if ($alertOnMissing) {
                            $hasMissingOrStale = true;
                            $alerts[] = [
                                'code' => 'MISSING_CANDLE_INTERVALS',
                                'severity' => 'WARNING',
                                'exchange' => $exchange,
                                'symbol' => $symbol,
                                'interval' => $interval,
                                'message' => "{$exchange} {$symbol} {$interval}m candle data is missing.",
                            ];
                        }
                    } elseif ($isStale) {
                        $status = 'STALE';
                        $hasMissingOrStale = true;
                        $alerts[] = [
                            'code' => 'MISSING_CANDLE_INTERVALS',
                            'severity' => 'WARNING',
                            'exchange' => $exchange,
                            'symbol' => $symbol,
                            'interval' => $interval,
                            'lag_seconds' => $lagSeconds,
                            'message' => "{$exchange} {$symbol} {$interval}m is lagging by {$lagSeconds}s (>= 2 consecutive intervals).",
                        ];
                    } else {
                        $status = 'FRESH';
                    }

                    $candleHealth[] = [
                        'exchange' => $exchange,
                        'symbol' => $symbol,
                        'interval' => $interval,
                        'status' => $status,
                        'last_closed_open_time' => $latest?->open_time?->toIso8601String(),
                        'expected_close_time' => $expectedClose?->toIso8601String(),
                        'lag_seconds' => $lagSeconds,
                        'is_stale' => $isStale,
                        'has_conflict' => $hasConflict,
                        'integrity_status' => $integrity,
                        'integrity_reason_code' => $integrityReport['reason_code'],
                    ];
                }
            }
        }

        // Check Redis queues
        $queues = ['default', 'audit', 'backtests', 'research'];
        $queueHealth = [];
        $queuesAvailable = true;
        try {
            $redis = Redis::connection();
            foreach ($queues as $q) {
                $len = (int) $redis->llen("queues:{$q}");
                $queueHealth[$q] = [
                    'pending_jobs' => $len,
                    'status' => $len > 100 ? 'CONGESTED' : 'HEALTHY',
                ];
            }
        } catch (\Throwable $e) {
            $queuesAvailable = false;
            $queueHealth = ['status' => 'UNAVAILABLE', 'error' => 'Queue health is unavailable.'];
            Log::warning('Research queue health unavailable.', [
                'code' => 'QUEUE_HEALTH_UNAVAILABLE',
                'exception' => $e::class,
                'request_id' => request()?->attributes?->get('request_id'),
            ]);
        }

        // Database connection ping
        $dbStart = microtime(true);
        try {
            DB::select('SELECT 1');
            $dbLatencyMs = round((microtime(true) - $dbStart) * 1000, 2);
            $dbStatus = 'HEALTHY';
        } catch (\Throwable) {
            $dbLatencyMs = null;
            $dbStatus = 'UNAVAILABLE';
        }

        // Execution failures in last 24h: window is exactly [now - 24h, now]
        // Uses CarbonImmutable to prevent in-place mutation of the current timestamp.
        $since24h = $now->subHours(24);
        try {
            $executionFailures24h = JournalEventRecord::query()
                ->where('event_type', 'EXECUTION_FAILED')
                ->where('occurred_at', '>=', $since24h)
                ->where('occurred_at', '<=', $now)
                ->count();

        } catch (\Throwable) {
            $executionFailures24h = null;
            $integrityUnavailable = true;
        }
        try {
            $conflicts = $this->conflictService->unresolvedConflicts($exchangeFilter);
            $conflictsCount = $this->conflictService->countUnresolved($exchangeFilter);
        } catch (CandleIntegrityUnavailable) {
            $conflicts = null;
            $conflictsCount = null;
            $integrityUnavailable = true;
        }

        $overallStatus = ($hasMissingOrStale || $dbStatus !== 'HEALTHY' || ! $queuesAvailable) ? 'WARNING' : 'HEALTHY';
        if ($hasConflicts || $integrityUnavailable || $executionFailures24h > 5) {
            $overallStatus = 'CRITICAL';
        }

        return [
            'status' => $overallStatus,
            'timestamp' => $now->toIso8601String(),
            'checked_at' => $now->toIso8601String(),
            'database' => [
                'status' => $dbStatus,
                'latency_ms' => $dbLatencyMs,
            ],
            'candle_freshness' => $candleHealth,
            'queues' => $queueHealth,
            'execution_failures_24h' => $executionFailures24h,
            'alerts' => $alerts,
            'integrity_status' => $integrityUnavailable ? 'UNAVAILABLE' : ($hasConflicts ? 'CONFLICTED' : 'VALID'),
            'conflicts' => $conflicts,
            'unresolved_conflicts_count' => $conflictsCount,
        ];
    }
}
