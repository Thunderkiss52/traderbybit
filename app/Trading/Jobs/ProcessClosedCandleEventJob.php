<?php

declare(strict_types=1);

namespace App\Trading\Jobs;

use App\Exchanges\Binance\BinanceCandleIngestion;
use App\Strategy\Application\MarketScannerService;
use App\Strategy\Application\StrategyObservationService;
use App\Strategy\Application\UniversalMarketContextCollector;
use App\Strategy\Contracts\MarketContextRepository;
use App\Strategy\Exceptions\StrategyObservationActiveException;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use App\StrategyBuilder\PrimaryStrategies;
use App\Trading\Application\TradingPipelineEventService;
use App\Trading\Infrastructure\Persistence\TradingPipelineEventRecord;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProcessClosedCandleEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [2, 10, 30];

    public function __construct(
        public readonly int $eventId,
        public readonly string $exchange,
        public readonly string $symbol,
        public readonly string $timeframe,
        public readonly string $candleCloseAtIso,
    ) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("pipeline:candle:{$this->exchange}:{$this->symbol}:{$this->timeframe}:{$this->candleCloseAtIso}"))
                ->expireAfter(150),
        ];
    }

    public function handle(TradingPipelineEventService $pipelineService): void
    {
        /** @var TradingPipelineEventRecord|null $eventRecord */
        $eventRecord = TradingPipelineEventRecord::query()->find($this->eventId);
        if ($eventRecord === null) {
            $eventKey = "{$this->exchange}:{$this->symbol}:{$this->timeframe}:{$this->candleCloseAtIso}:".TradingPipelineEventRecord::STAGE_CANDLE_CLOSED;
            $eventRecord = TradingPipelineEventRecord::query()->where('event_key', $eventKey)->first();
        }

        if ($eventRecord === null) {
            Log::warning("ProcessClosedCandleEventJob: Event record not found for {$this->eventId}");

            return;
        }

        if (in_array($eventRecord->status, [
            TradingPipelineEventRecord::STATUS_COMPLETED,
            TradingPipelineEventRecord::STATUS_PARTIAL,
            TradingPipelineEventRecord::STATUS_STALE,
        ], true)) {
            return; // Terminal stages must never be restarted or downgraded.
        }

        $closeAt = new DateTimeImmutable($this->candleCloseAtIso);
        if ($eventRecord->status === TradingPipelineEventRecord::STATUS_FAILED
            && TradingPipelineEventRecord::query()
                ->where('exchange', $this->exchange)->where('symbol', $this->symbol)
                ->where('candle_close_at', $closeAt)
                ->where('stage', TradingPipelineEventRecord::STAGE_PAPER_PROCESSED)
                ->whereIn('status', [
                    TradingPipelineEventRecord::STATUS_COMPLETED,
                    TradingPipelineEventRecord::STATUS_PARTIAL,
                    TradingPipelineEventRecord::STATUS_FAILED,
                ])->exists()) {
            return; // Only targeted paper recovery may retry a terminal failed batch.
        }

        // Strict 300-second freshness guard: never execute trades on stale events.
        if (! $pipelineService->isFresh($closeAt)) {
            // Check if downstream SIGNALS_EVALUATED or PAPER_PROCESSED already serviced this slot
            $downstreamServiced = TradingPipelineEventRecord::query()
                ->where('exchange', $this->exchange)
                ->where('symbol', $this->symbol)
                ->where('candle_close_at', $closeAt)
                ->whereIn('stage', [
                    TradingPipelineEventRecord::STAGE_SIGNALS_EVALUATED,
                    TradingPipelineEventRecord::STAGE_PAPER_PROCESSED,
                ])
                ->whereIn('status', [
                    TradingPipelineEventRecord::STATUS_COMPLETED,
                    TradingPipelineEventRecord::STATUS_PARTIAL,
                    TradingPipelineEventRecord::STATUS_DISPATCHED,
                ])
                ->exists();

            if ($downstreamServiced) {
                TradingPipelineEventRecord::query()->whereKey($eventRecord->id)
                    ->whereNotIn('status', [
                        TradingPipelineEventRecord::STATUS_COMPLETED,
                        TradingPipelineEventRecord::STATUS_PARTIAL,
                    ])
                    ->update([
                        'status' => TradingPipelineEventRecord::STATUS_COMPLETED,
                        'completed_at' => CarbonImmutable::now('UTC'),
                    ]);

                return;
            }

            if ($eventRecord->status !== TradingPipelineEventRecord::STATUS_FAILED) {
                TradingPipelineEventRecord::query()->whereKey($eventRecord->id)
                    ->whereIn('status', [TradingPipelineEventRecord::STATUS_PENDING, TradingPipelineEventRecord::STATUS_DISPATCHED])
                    ->update([
                        'status' => TradingPipelineEventRecord::STATUS_STALE,
                        'last_error' => 'Candle close exceeds freshness threshold (300s). Processing rejected.',
                    ]);
            }
            Log::info('Closed candle event rejected after freshness window.', [
                'event_key' => $eventRecord->event_key,
                'status' => $eventRecord->fresh()->status,
            ]);

            return;
        }

        $eventRecord->increment('attempts');

        try {
            if ($this->timeframe === '15') {
                $this->handleDecisionCandle($pipelineService, $eventRecord, $closeAt);
            } elseif ($this->timeframe === '5') {
                $this->handleExecutionCandle($pipelineService, $eventRecord, $closeAt);
            } else {
                // Non-decision/non-execution timeframe (e.g. 30m, 60m), mark completed.
                $eventRecord->update([
                    'status' => TradingPipelineEventRecord::STATUS_COMPLETED,
                    'completed_at' => CarbonImmutable::now('UTC'),
                ]);
            }
        } catch (Throwable $e) {
            TradingPipelineEventRecord::query()->whereKey($eventRecord->id)
                ->whereNotIn('status', [TradingPipelineEventRecord::STATUS_COMPLETED, TradingPipelineEventRecord::STATUS_PARTIAL, TradingPipelineEventRecord::STATUS_STALE])
                ->update([
                    'status' => TradingPipelineEventRecord::STATUS_FAILED,
                    'last_error' => $e->getMessage(),
                ]);
            Log::error("ProcessClosedCandleEventJob failed for {$eventRecord->event_key}: ".$e->getMessage(), [
                'exception' => $e::class,
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    private function handleDecisionCandle(
        TradingPipelineEventService $pipelineService,
        TradingPipelineEventRecord $eventRecord,
        DateTimeImmutable $closeAt
    ): void {
        $startMs = (int) floor(microtime(true) * 1000);
        $queueWaitMs = $eventRecord->dispatched_at !== null
            ? max(0, $startMs - ((int) $eventRecord->dispatched_at->format('U') * 1000 + (int) $eventRecord->dispatched_at->format('v')))
            : null;

        // 1. Ensure required market context is captured for the exchange.
        $this->ensureMarketContext($closeAt);
        $this->ensureBinanceHtfCandles($this->symbol, $closeAt);

        // 2. Generate signals for all 13 strategies for this exchange and symbol.
        $canonicalStrategies = PrimaryStrategies::ids();
        $existingObservations = StrategyObservationRecord::query()
            ->where('exchange', $this->exchange)
            ->where('category', 'linear')
            ->where('symbol', $this->symbol)
            ->where('decision_time', $closeAt)
            ->whereNull('superseded_by')
            ->get();

        $signalsCount = 0;
        if (! empty($canonicalStrategies) && $existingObservations->count() >= count($canonicalStrategies)) {
            // The canonical observations already exist from a peer worker. Reuse without lock contention.
            $signalsCount = $existingObservations->count();
        } else {
            try {
                if ($this->exchange === 'bybit') {
                    $scanner = app(MarketScannerService::class);
                    $scanResult = $scanner->scanSymbol($this->symbol, $closeAt, 'bybit', 'linear');
                    $signalsCount = (int) ($scanResult['evaluations_count'] ?? 0);
                } else {
                    $observations = app(StrategyObservationService::class)->observe($this->symbol, $closeAt, $this->exchange, 'linear');
                    $signalsCount = count($observations);
                }
            } catch (StrategyObservationActiveException $e) {
                // Check if canonical strategy observations were already generated by another worker
                $rechecked = StrategyObservationRecord::query()
                    ->where('exchange', $this->exchange)
                    ->where('category', 'linear')
                    ->where('symbol', $this->symbol)
                    ->where('decision_time', $closeAt)
                    ->whereNull('superseded_by')
                    ->get();

                if (! empty($canonicalStrategies) && $rechecked->count() >= count($canonicalStrategies)) {
                    // The competing worker completed canonical observations.
                    // Reuse existing observations to advance the pipeline without duplicate execution.
                    $signalsCount = $rechecked->count();
                } else {
                    Log::warning('Trading pipeline observation lock contention', [
                        'exchange' => $this->exchange,
                        'symbol' => $this->symbol,
                        'candle_close_at' => $closeAt->format(DATE_ATOM),
                        'reason' => 'STRATEGY_OBSERVATION_ACTIVE_IN_PEER_WORKER',
                        'error' => $e->getMessage(),
                    ]);

                    // Downstream check: if SIGNALS_EVALUATED or PAPER_PROCESSED already exist, do not downgrade
                    $alreadyServiced = TradingPipelineEventRecord::query()
                        ->where('exchange', $this->exchange)
                        ->where('symbol', $this->symbol)
                        ->where('candle_close_at', $closeAt)
                        ->whereIn('stage', [
                            TradingPipelineEventRecord::STAGE_SIGNALS_EVALUATED,
                            TradingPipelineEventRecord::STAGE_PAPER_PROCESSED,
                        ])
                        ->whereIn('status', [
                            TradingPipelineEventRecord::STATUS_DISPATCHED,
                            TradingPipelineEventRecord::STATUS_COMPLETED,
                            TradingPipelineEventRecord::STATUS_PARTIAL,
                        ])
                        ->exists();

                    if (! $alreadyServiced) {
                        TradingPipelineEventRecord::query()->whereKey($eventRecord->id)
                            ->whereNotIn('status', [
                                TradingPipelineEventRecord::STATUS_COMPLETED,
                                TradingPipelineEventRecord::STATUS_PARTIAL,
                                TradingPipelineEventRecord::STATUS_STALE,
                            ])
                            ->update([
                                'status' => TradingPipelineEventRecord::STATUS_PENDING,
                                'last_error' => $e->getMessage(),
                            ]);
                    }

                    return;
                }
            }
        }

        $durationMs = (int) floor(microtime(true) * 1000) - $startMs;
        Log::info('ProcessClosedCandleEventJob: decision stage evaluated', [
            'exchange' => $this->exchange,
            'symbol' => $this->symbol,
            'candle_close_at' => $closeAt->format(DATE_ATOM),
            'signals_count' => $signalsCount,
            'queue_wait_ms' => $queueWaitMs,
            'duration_ms' => $durationMs,
        ]);

        // 3. Record SIGNALS_EVALUATED event and dispatch paper processing.
        $pipelineService->recordSignalsEvaluated($this->exchange, $this->symbol, $closeAt, $signalsCount);

        // Paper processing owns terminal acknowledgement of the decision candle.
        // Dispatching downstream work is not a completed PAPER cycle.
        $eventDriven = $pipelineService->isEventDriven();
        TradingPipelineEventRecord::query()->whereKey($eventRecord->id)
            ->whereNotIn('status', [
                TradingPipelineEventRecord::STATUS_COMPLETED,
                TradingPipelineEventRecord::STATUS_PARTIAL,
                TradingPipelineEventRecord::STATUS_FAILED,
                TradingPipelineEventRecord::STATUS_STALE,
            ])->update([
                'status' => $eventDriven ? TradingPipelineEventRecord::STATUS_DISPATCHED : TradingPipelineEventRecord::STATUS_COMPLETED,
                'completed_at' => $eventDriven ? null : CarbonImmutable::now('UTC'),
            ]);
    }

    private function handleExecutionCandle(
        TradingPipelineEventService $pipelineService,
        TradingPipelineEventRecord $eventRecord,
        DateTimeImmutable $closeAt
    ): void {
        // 5m bars drive position lifecycle updates (stop loss, take profit, trailing, pending entry fills).
        if ($pipelineService->isEventDriven()) {
            $queue = (string) config("trading.queues.{$this->exchange}", $this->exchange === 'binance' ? 'trading-binance' : 'trading-bybit');
            ProcessPaperPipelineJob::dispatch($this->exchange, $this->symbol, $this->candleCloseAtIso)
                ->onQueue($queue);

            TradingPipelineEventRecord::query()->whereKey($eventRecord->id)
                ->whereNotIn('status', [
                    TradingPipelineEventRecord::STATUS_COMPLETED,
                    TradingPipelineEventRecord::STATUS_PARTIAL,
                    TradingPipelineEventRecord::STATUS_FAILED,
                    TradingPipelineEventRecord::STATUS_STALE,
                ])->update([
                    'status' => TradingPipelineEventRecord::STATUS_DISPATCHED,
                    'completed_at' => null,
                ]);
        } else {
            $eventRecord->update([
                'status' => TradingPipelineEventRecord::STATUS_COMPLETED,
                'completed_at' => CarbonImmutable::now('UTC'),
            ]);
        }
    }

    private function ensureMarketContext(DateTimeImmutable $closeAt): void
    {
        try {
            $repo = app(MarketContextRepository::class);
            $existing = $repo->latestAt($this->exchange, 'linear', $this->symbol, $closeAt);
            if ($existing === null) {
                app(UniversalMarketContextCollector::class)->collect($this->exchange, $this->symbol);
            }
        } catch (Throwable $e) {
            Log::warning("Market context capture non-fatal failure for {$this->exchange}:{$this->symbol}: ".$e->getMessage());
        }
    }

    private function ensureBinanceHtfCandles(string $symbol, DateTimeImmutable $closeAt): void
    {
        if ($this->exchange !== 'binance') {
            return;
        }

        try {
            $ingestion = app(BinanceCandleIngestion::class);
            $ts = $closeAt->getTimestamp();

            if ($ts % 1800 === 0) {
                $open30m = $closeAt->modify('-30 minutes');
                $has30m = DB::table('market_candles')
                    ->where('exchange', 'binance')->where('category', 'linear')
                    ->where('symbol', $symbol)->where('interval', '30')
                    ->where('open_time', $open30m)->where('is_closed', true)
                    ->exists();
                if (! $has30m) {
                    $ingestion->ingest($symbol, '30m', 5, quarantineConflicts: true);
                }
            }

            if ($ts % 3600 === 0) {
                $open60m = $closeAt->modify('-60 minutes');
                $has60m = DB::table('market_candles')
                    ->where('exchange', 'binance')->where('category', 'linear')
                    ->where('symbol', $symbol)->where('interval', '60')
                    ->where('open_time', $open60m)->where('is_closed', true)
                    ->exists();
                if (! $has60m) {
                    $ingestion->ingest($symbol, '1h', 5, quarantineConflicts: true);
                }
            }
        } catch (Throwable $e) {
            Log::warning("Binance HTF candle pre-synchronization non-fatal: {$e->getMessage()}");
        }
    }
}
