<?php

declare(strict_types=1);

namespace App\Trading\Application;

use App\Trading\Infrastructure\Persistence\TradingPipelineEventRecord;
use App\Trading\Jobs\ProcessClosedCandleEventJob;
use App\Trading\Jobs\ProcessPaperPipelineJob;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class TradingPipelineEventService
{
    public function __construct(
        private readonly ?int $freshnessSeconds = null,
        private readonly ?string $pipelineMode = null,
    ) {}

    public function pipelineMode(): string
    {
        return $this->pipelineMode ?? (string) config('trading.pipeline_mode', 'EVENT_DRIVEN');
    }

    public function isEventDriven(): bool
    {
        return strtoupper($this->pipelineMode()) === 'EVENT_DRIVEN';
    }

    public function freshnessSeconds(): int
    {
        return $this->freshnessSeconds ?? (int) config('trading.freshness_seconds', 300);
    }

    public function isFresh(DateTimeImmutable $candleCloseAt, ?DateTimeImmutable $now = null): bool
    {
        $nowTs = ($now ?? now('UTC')->toDateTimeImmutable())->getTimestamp();
        $diff = $nowTs - $candleCloseAt->getTimestamp();

        return $diff >= 0 && $diff <= $this->freshnessSeconds();
    }

    /**
     * Record a closed market candle and dispatch processing event if event-driven mode is active.
     */
    public function recordCandleClosed(
        string $exchange,
        string $symbol,
        string $timeframe,
        DateTimeImmutable $candleCloseAt,
        ?array $payload = null
    ): ?TradingPipelineEventRecord {
        $exchange = strtolower($exchange);
        $symbol = strtoupper($symbol);
        $timeframe = (string) $timeframe;
        $closeIso = $candleCloseAt->format(DATE_ATOM);

        $eventKey = "{$exchange}:{$symbol}:{$timeframe}:{$closeIso}:".TradingPipelineEventRecord::STAGE_CANDLE_CLOSED;

        $record = null;
        try {
            $now = CarbonImmutable::now('UTC');
            $inserted = DB::table('trading_pipeline_events')->insertOrIgnore([
                'event_key' => $eventKey,
                'exchange' => $exchange,
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'stage' => TradingPipelineEventRecord::STAGE_CANDLE_CLOSED,
                'candle_close_at' => $candleCloseAt,
                'status' => TradingPipelineEventRecord::STATUS_PENDING,
                'payload' => $payload !== null ? json_encode($payload, JSON_THROW_ON_ERROR) : null,
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $record = TradingPipelineEventRecord::query()->where('event_key', $eventKey)->first();

            if ($this->isEventDriven() && $record !== null && $record->status === TradingPipelineEventRecord::STATUS_PENDING) {
                $queue = (string) config("trading.queues.{$exchange}", $exchange === 'binance' ? 'trading-binance' : 'trading-bybit');
                ProcessClosedCandleEventJob::dispatch($record->id, $exchange, $symbol, $timeframe, $closeIso)
                    ->onQueue($queue);

                $record->update([
                    'status' => TradingPipelineEventRecord::STATUS_DISPATCHED,
                    'dispatched_at' => $now,
                ]);
            }
        } catch (Throwable $e) {
            Log::warning("Failed to record or dispatch closed candle event for {$eventKey}: ".$e->getMessage(), [
                'exception' => $e::class,
                'event_key' => $eventKey,
            ]);
        }

        return $record;
    }

    /**
     * Record completed signal evaluation and dispatch paper trading pipeline event.
     */
    public function recordSignalsEvaluated(
        string $exchange,
        string $symbol,
        DateTimeImmutable $candleCloseAt,
        int $signalsCount,
        ?array $payload = null
    ): TradingPipelineEventRecord {
        $exchange = strtolower($exchange);
        $symbol = strtoupper($symbol);
        $closeIso = $candleCloseAt->format(DATE_ATOM);

        $eventKey = "{$exchange}:{$symbol}:15:{$closeIso}:".TradingPipelineEventRecord::STAGE_SIGNALS_EVALUATED;
        $now = CarbonImmutable::now('UTC');

        $data = [
            'signals_count' => $signalsCount,
            ...($payload ?? []),
        ];

        DB::table('trading_pipeline_events')->insertOrIgnore([
            'event_key' => $eventKey,
            'exchange' => $exchange,
            'symbol' => $symbol,
            'timeframe' => '15',
            'stage' => TradingPipelineEventRecord::STAGE_SIGNALS_EVALUATED,
            'candle_close_at' => $candleCloseAt,
            'status' => TradingPipelineEventRecord::STATUS_PENDING,
            'payload' => json_encode($data, JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        /** @var TradingPipelineEventRecord $record */
        $record = TradingPipelineEventRecord::query()->where('event_key', $eventKey)->firstOrFail();

        if ($this->isEventDriven() && $record->status === TradingPipelineEventRecord::STATUS_PENDING) {
            $queue = (string) config("trading.queues.{$exchange}", $exchange === 'binance' ? 'trading-binance' : 'trading-bybit');
            ProcessPaperPipelineJob::dispatch($exchange, $symbol, $closeIso)
                ->onQueue($queue);

            $record->update([
                'status' => TradingPipelineEventRecord::STATUS_DISPATCHED,
                'dispatched_at' => $now,
            ]);
        }

        return $record;
    }

    /**
     * Record completed paper trading run.
     */
    public function recordPaperProcessed(
        string $exchange,
        string $symbol,
        DateTimeImmutable $candleCloseAt,
        int $eventsProcessed,
        ?array $payload = null
    ): TradingPipelineEventRecord {
        $exchange = strtolower($exchange);
        $symbol = strtoupper($symbol);
        $closeIso = $candleCloseAt->format(DATE_ATOM);

        $eventKey = "{$exchange}:{$symbol}:{$closeIso}:".TradingPipelineEventRecord::STAGE_PAPER_PROCESSED;
        $now = CarbonImmutable::now('UTC');

        $data = [
            'events_processed' => $eventsProcessed,
            ...($payload ?? []),
        ];

        DB::table('trading_pipeline_events')->insertOrIgnore([
            'event_key' => $eventKey,
            'exchange' => $exchange,
            'symbol' => $symbol,
            'timeframe' => '5',
            'stage' => TradingPipelineEventRecord::STAGE_PAPER_PROCESSED,
            'candle_close_at' => $candleCloseAt,
            'status' => TradingPipelineEventRecord::STATUS_COMPLETED,
            'payload' => json_encode($data, JSON_THROW_ON_ERROR),
            'attempts' => 1,
            'completed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        /** @var TradingPipelineEventRecord $record */
        $record = TradingPipelineEventRecord::query()->where('event_key', $eventKey)->firstOrFail();

        return $record;
    }
}
