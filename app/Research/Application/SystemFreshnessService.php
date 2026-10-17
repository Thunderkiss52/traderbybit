<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Infrastructure\Persistence\JournalEventRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

final class SystemFreshnessService
{
    /**
     * Inspect system health, candle freshness, queue lag, and connection latencies.
     *
     * @return array<string, mixed>
     */
    public function check(): array
    {
        $now = now('UTC');
        $candleHealth = [];
        $alerts = [];
        $hasMissingIntervals = false;

        $symbols = (array) config('ui.symbols', ['BTCUSDT', 'ETHUSDT']);
        $intervals = ['5', '15', '60'];

        foreach ($symbols as $symbol) {
            foreach ($intervals as $interval) {
                $latest = CandleRecord::query()
                    ->where('symbol', $symbol)
                    ->where('interval', $interval)
                    ->where('is_closed', true)
                    ->latest('open_time')
                    ->first(['open_time', 'updated_at']);

                $intervalMinutes = (int) $interval;
                $expectedClose = $latest?->open_time?->addMinutes($intervalMinutes);
                $lagSeconds = $expectedClose ? max(0, $now->getTimestamp() - $expectedClose->getTimestamp()) : null;

                // Threshold: >= 2 consecutive intervals missing
                $missingThresholdSeconds = $intervalMinutes * 60 * 2;
                $isStale = $lagSeconds !== null && $lagSeconds > $missingThresholdSeconds;

                if ($isStale && $interval === '15') {
                    $hasMissingIntervals = true;
                    $alerts[] = [
                        'code' => 'MISSING_CANDLE_INTERVALS',
                        'severity' => 'WARNING',
                        'symbol' => $symbol,
                        'interval' => $interval,
                        'lag_seconds' => $lagSeconds,
                        'message' => "{$symbol} {$interval}m is lagging by {$lagSeconds}s (>= 2 consecutive intervals).",
                    ];
                }

                $candleHealth[] = [
                    'symbol' => $symbol,
                    'interval' => $interval,
                    'last_closed_open_time' => $latest?->open_time?->toIso8601String(),
                    'expected_close_time' => $expectedClose?->toIso8601String(),
                    'lag_seconds' => $lagSeconds,
                    'is_stale' => $isStale,
                ];
            }
        }

        // Check Redis queues
        $queues = ['default', 'audit', 'backtests', 'research'];
        $queueHealth = [];
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
            $queueHealth = ['error' => 'Redis connection failed: '.$e->getMessage()];
        }

        // Database connection ping
        $dbStart = microtime(true);
        try {
            DB::select('SELECT 1');
            $dbLatencyMs = round((microtime(true) - $dbStart) * 1000, 2);
            $dbStatus = 'HEALTHY';
        } catch (\Throwable $e) {
            $dbLatencyMs = null;
            $dbStatus = 'UNAVAILABLE';
        }

        // Execution failures in last 24h
        $executionFailures24h = JournalEventRecord::query()
            ->where('event_type', 'EXECUTION_FAILED')
            ->where('occurred_at', '>=', $now->subDay())
            ->count();

        $overallStatus = ($hasMissingIntervals || $dbStatus !== 'HEALTHY') ? 'WARNING' : 'HEALTHY';
        if ($executionFailures24h > 5) {
            $overallStatus = 'CRITICAL';
        }

        return [
            'status' => $overallStatus,
            'timestamp' => $now->toIso8601String(),
            'database' => [
                'status' => $dbStatus,
                'latency_ms' => $dbLatencyMs,
            ],
            'candle_freshness' => $candleHealth,
            'queues' => $queueHealth,
            'execution_failures_24h' => $executionFailures24h,
            'alerts' => $alerts,
        ];
    }
}
