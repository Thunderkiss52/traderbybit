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
            $closeMs = (int) $candleCloseAt->format('U') * 1000 + (int) $candleCloseAt->format('v');
            $nowMs = (int) $now->format('U') * 1000 + (int) $now->format('v');
            $candleCloseLatencyMs = max(0, $nowMs - $closeMs);

            $eventPayload = array_merge($payload ?? [], [
                'candle_close_to_event_ms' => $candleCloseLatencyMs,
            ]);

            $inserted = DB::table('trading_pipeline_events')->insertOrIgnore([
                'event_key' => $eventKey,
                'exchange' => $exchange,
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'stage' => TradingPipelineEventRecord::STAGE_CANDLE_CLOSED,
                'candle_close_at' => $candleCloseAt,
                'status' => TradingPipelineEventRecord::STATUS_PENDING,
                'payload' => json_encode($eventPayload, JSON_THROW_ON_ERROR),
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $record = TradingPipelineEventRecord::query()->where('event_key', $eventKey)->first();

            if ($this->isEventDriven() && $record !== null && $record->status === TradingPipelineEventRecord::STATUS_PENDING) {
                $queue = TradingQueueResolver::forExchange($exchange);
                ProcessClosedCandleEventJob::dispatch($record->id, $exchange, $symbol, $timeframe, $closeIso)
                    ->onQueue($queue);

                TradingPipelineEventRecord::query()->whereKey($record->id)->where('status', TradingPipelineEventRecord::STATUS_PENDING)->update([
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

        return $record?->refresh();
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
        $closeMs = (int) $candleCloseAt->format('U') * 1000 + (int) $candleCloseAt->format('v');
        $nowMs = (int) $now->format('U') * 1000 + (int) $now->format('v');
        $evalLatencyMs = max(0, $nowMs - $closeMs);

        $data = [
            'signals_count' => $signalsCount,
            'eval_latency_from_close_ms' => $evalLatencyMs,
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
            $queue = TradingQueueResolver::forExchange($exchange);
            ProcessPaperPipelineJob::dispatch($exchange, $symbol, $closeIso)
                ->onQueue($queue);

            TradingPipelineEventRecord::query()->whereKey($record->id)->where('status', TradingPipelineEventRecord::STATUS_PENDING)->update([
                'status' => TradingPipelineEventRecord::STATUS_DISPATCHED,
                'dispatched_at' => $now,
            ]);
        }

        return $record?->refresh();
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

        $failuresCount = (int) ($payload['failures_count'] ?? 0);
        $eligibleAccounts = (int) ($payload['eligible_accounts'] ?? 0);
        $processedAccounts = (int) ($payload['processed_accounts'] ?? 0);

        $status = TradingPipelineEventRecord::STATUS_COMPLETED;
        if ($failuresCount > 0 && $eligibleAccounts > 0 && $processedAccounts === 0) {
            $status = TradingPipelineEventRecord::STATUS_FAILED;
        } elseif ($failuresCount > 0 || ($eligibleAccounts > 0 && $processedAccounts < $eligibleAccounts)) {
            $status = TradingPipelineEventRecord::STATUS_PARTIAL;
        }

        $existing = TradingPipelineEventRecord::query()->where('event_key', $eventKey)->first();
        if ($existing !== null) {
            $existing->update([
                'status' => $status,
                'payload' => $data,
                'attempts' => $existing->attempts + 1,
                'completed_at' => $now,
                'updated_at' => $now,
            ]);

            $record = $existing->fresh();
        } else {
            DB::table('trading_pipeline_events')->insertOrIgnore([
                'event_key' => $eventKey,
                'exchange' => $exchange,
                'symbol' => $symbol,
                'timeframe' => '5',
                'stage' => TradingPipelineEventRecord::STAGE_PAPER_PROCESSED,
                'candle_close_at' => $candleCloseAt,
                'status' => $status,
                'payload' => json_encode($data, JSON_THROW_ON_ERROR),
                'attempts' => 1,
                'completed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            /** @var TradingPipelineEventRecord $record */
            $record = TradingPipelineEventRecord::query()->where('event_key', $eventKey)->firstOrFail();
        }

        // Terminalize upstream SIGNALS_EVALUATED and CANDLE_CLOSED events for this cycle
        $this->acknowledgeUpstreamEvents($exchange, $symbol, $candleCloseAt, $status, $now);

        return $record?->refresh();
    }

    /**
     * Acknowledge and terminalize upstream pipeline events waiting on downstream paper execution.
     */
    public function acknowledgeUpstreamEvents(
        string $exchange,
        string $symbol,
        DateTimeImmutable $candleCloseAt,
        string $status,
        ?CarbonImmutable $now = null
    ): int {
        $now ??= CarbonImmutable::now('UTC');

        return TradingPipelineEventRecord::query()
            ->where('exchange', strtolower($exchange))
            ->where('symbol', strtoupper($symbol))
            ->where('candle_close_at', $candleCloseAt)
            ->whereIn('stage', [
                TradingPipelineEventRecord::STAGE_SIGNALS_EVALUATED,
                TradingPipelineEventRecord::STAGE_CANDLE_CLOSED,
            ])
            ->whereIn('status', [TradingPipelineEventRecord::STATUS_PENDING, TradingPipelineEventRecord::STATUS_DISPATCHED, TradingPipelineEventRecord::STATUS_PARTIAL, TradingPipelineEventRecord::STATUS_FAILED])
            ->update([
                'status' => $status,
                'completed_at' => $now,
                'updated_at' => $now,
            ]);
    }
}
