<?php

declare(strict_types=1);

namespace App\Trading\Jobs;

use App\Exchanges\Binance\BinanceContextCapture;
use App\Strategy\Application\MarketContextCollector;
use App\Strategy\Application\MarketScannerService;
use App\Strategy\Application\StrategyObservationService;
use App\Strategy\Contracts\MarketContextRepository;
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
                ->expireAfter(120),
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

        if ($eventRecord->status === TradingPipelineEventRecord::STATUS_COMPLETED) {
            return; // Already completed (idempotent).
        }

        $closeAt = new DateTimeImmutable($this->candleCloseAtIso);

        // Strict 300-second freshness guard: never execute trades on stale events.
        if (! $pipelineService->isFresh($closeAt)) {
            $eventRecord->update([
                'status' => TradingPipelineEventRecord::STATUS_STALE,
                'last_error' => 'Candle close exceeds freshness threshold (300s). Processing rejected.',
            ]);
            Log::info("Closed candle event {$eventRecord->event_key} rejected as STALE (exceeded freshness window).");

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
            $eventRecord->update([
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
        // 1. Ensure required market context is captured for the exchange.
        $this->ensureMarketContext($closeAt);

        // 2. Generate signals for all 13 strategies for this exchange and symbol.
        $signalsCount = 0;
        if ($this->exchange === 'bybit') {
            $scanner = app(MarketScannerService::class);
            $scanResult = $scanner->scanSymbol($this->symbol, $closeAt, 'bybit', 'linear');
            $signalsCount = (int) ($scanResult['evaluations_count'] ?? 0);
        } elseif ($this->exchange === 'binance') {
            $observations = app(StrategyObservationService::class)->observe($this->symbol, $closeAt, 'binance', 'linear');
            $signalsCount = count($observations);
        }

        // 3. Record SIGNALS_EVALUATED event and dispatch paper processing.
        $pipelineService->recordSignalsEvaluated($this->exchange, $this->symbol, $closeAt, $signalsCount);

        // 4. Mark candle event COMPLETED.
        $eventRecord->update([
            'status' => TradingPipelineEventRecord::STATUS_COMPLETED,
            'completed_at' => CarbonImmutable::now('UTC'),
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

            $eventRecord->update([
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
                if ($this->exchange === 'bybit') {
                    app(MarketContextCollector::class)->collect($this->symbol);
                } elseif ($this->exchange === 'binance') {
                    app(BinanceContextCapture::class)->capture($this->symbol);
                }
            }
        } catch (Throwable $e) {
            Log::warning("Market context capture non-fatal failure for {$this->exchange}:{$this->symbol}: ".$e->getMessage());
        }
    }
}
