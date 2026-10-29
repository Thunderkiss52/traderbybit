<?php

declare(strict_types=1);

namespace App\Trading\Application;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\Trading\Infrastructure\Persistence\TradingPipelineEventRecord;
use App\Trading\Jobs\ProcessClosedCandleEventJob;
use App\Trading\Jobs\ProcessPaperPipelineJob;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class TradingPipelineRecoveryService
{
    public function __construct(
        private readonly TradingPipelineEventService $eventService,
    ) {}

    /**
     * Check if a decision time has already been evaluated by the event-driven pipeline.
     */
    public function isDecisionAlreadyServiced(string $exchange, string $symbol, ?DateTimeImmutable $at = null): bool
    {
        $closeAt = $at ?? $this->latest15mClose($exchange, $symbol);
        if ($closeAt === null) {
            return false;
        }

        $closeIso = $closeAt->format(DATE_ATOM);

        return TradingPipelineEventRecord::query()
            ->where('exchange', strtolower($exchange))
            ->where('symbol', strtoupper($symbol))
            ->where('stage', TradingPipelineEventRecord::STAGE_SIGNALS_EVALUATED)
            ->where('candle_close_at', $closeAt)
            ->whereIn('status', [
                TradingPipelineEventRecord::STATUS_DISPATCHED,
                TradingPipelineEventRecord::STATUS_COMPLETED,
            ])
            ->exists();
    }

    /**
     * Check if paper trading has already been serviced for the given time.
     */
    public function isPaperAlreadyServiced(string $exchange, ?string $symbol, ?DateTimeImmutable $at = null): bool
    {
        $closeAt = $at ?? $this->latest5mClose($exchange, $symbol);
        if ($closeAt === null) {
            return false;
        }

        $query = TradingPipelineEventRecord::query()
            ->where('exchange', strtolower($exchange))
            ->where('stage', TradingPipelineEventRecord::STAGE_PAPER_PROCESSED)
            ->where('candle_close_at', $closeAt)
            ->where('status', TradingPipelineEventRecord::STATUS_COMPLETED);

        if ($symbol !== null) {
            $query->where('symbol', strtoupper($symbol));
        }

        return $query->exists();
    }

    /**
     * Scan and recover any pending or stale pipeline events.
     */
    public function recover(): array
    {
        $now = now('UTC')->toDateTimeImmutable();
        $freshnessWindow = $this->eventService->freshnessSeconds();

        $recovered = 0;
        $staled = 0;
        $scanned = 0;

        // 1. Process pending or uncompleted dispatched events older than 30s
        $events = TradingPipelineEventRecord::query()
            ->where(function ($query) use ($now, $freshnessWindow): void {
                $query->whereIn('status', [TradingPipelineEventRecord::STATUS_PENDING, TradingPipelineEventRecord::STATUS_DISPATCHED])
                    ->orWhere(function ($retry) use ($now, $freshnessWindow): void {
                        $retry->where('stage', TradingPipelineEventRecord::STAGE_PAPER_PROCESSED)
                            ->whereIn('status', [TradingPipelineEventRecord::STATUS_PARTIAL, TradingPipelineEventRecord::STATUS_FAILED])
                            ->where('candle_close_at', '>=', $now->modify("-{$freshnessWindow} seconds"));
                    });
            })
            ->where('created_at', '<=', CarbonImmutable::now('UTC')->subSeconds(20))
            ->limit(50)
            ->get();

        foreach ($events as $event) {
            $scanned++;
            $closeAt = $event->candle_close_at->toDateTimeImmutable();
            $closeIso = $closeAt->format(DATE_ATOM);

            // If downstream PAPER_PROCESSED has already completed, terminalize this upstream event immediately.
            if (in_array($event->stage, [
                TradingPipelineEventRecord::STAGE_SIGNALS_EVALUATED,
                TradingPipelineEventRecord::STAGE_CANDLE_CLOSED,
            ], true)) {
                $paperKey = "{$event->exchange}:{$event->symbol}:{$closeIso}:".TradingPipelineEventRecord::STAGE_PAPER_PROCESSED;
                $paperRecord = TradingPipelineEventRecord::query()->where('event_key', $paperKey)->first();

                if ($paperRecord !== null && in_array($paperRecord->status, [
                    TradingPipelineEventRecord::STATUS_COMPLETED,
                    TradingPipelineEventRecord::STATUS_PARTIAL,
                    TradingPipelineEventRecord::STATUS_FAILED,
                ], true)) {
                    $event->update([
                        'status' => $paperRecord->status,
                        'completed_at' => $paperRecord->completed_at ?? CarbonImmutable::now('UTC'),
                        'updated_at' => CarbonImmutable::now('UTC'),
                    ]);
                    $recovered++;
                    Log::info("Recovery: Terminalized upstream event {$event->event_key} to {$paperRecord->status} matching existing downstream PAPER_PROCESSED.");

                    continue;
                }
            }

            if (! $this->eventService->isFresh($closeAt, $now)) {
                if (in_array($event->status, [TradingPipelineEventRecord::STATUS_PARTIAL, TradingPipelineEventRecord::STATUS_FAILED], true)) {
                    continue; // Preserve historical failure evidence; never execute it late.
                }
                $event->update([
                    'completed_at' => CarbonImmutable::now('UTC'),
                    'status' => TradingPipelineEventRecord::STATUS_STALE,
                    'last_error' => "Expired outside {$freshnessWindow}s freshness window during recovery scan.",
                ]);
                $staled++;
                Log::info("Recovery: Marked event {$event->event_key} as STALE (exceeded freshness window).");

                continue;
            }

            // Re-dispatch fresh pending/uncompleted event
            $queue = (string) config("trading.queues.{$event->exchange}", $event->exchange === 'binance' ? 'trading-binance' : 'trading-bybit');

            try {
                if ($event->stage === TradingPipelineEventRecord::STAGE_CANDLE_CLOSED) {
                    ProcessClosedCandleEventJob::dispatch(
                        $event->id,
                        $event->exchange,
                        $event->symbol,
                        $event->timeframe,
                        $closeAt->format(DATE_ATOM)
                    )->onQueue($queue);
                    $recovered++;
                } elseif ($event->stage === TradingPipelineEventRecord::STAGE_PAPER_PROCESSED) {
                    $data = (array) $event->payload;
                    $failed = array_values(array_diff(array_unique([...array_column($data['failures'] ?? [], 'account_id'), ...($data['eligible_account_ids'] ?? [])]), $data['processed_account_ids'] ?? []));
                    if ($failed === [] && $event->status !== TradingPipelineEventRecord::STATUS_PENDING) {
                        continue; // Legacy aggregates cannot identify safe retry targets.
                    }
                    ProcessPaperPipelineJob::dispatch($event->exchange, $event->symbol, $closeIso, $failed ?: null)->onQueue($queue);
                    $recovered++;
                } elseif ($event->stage === TradingPipelineEventRecord::STAGE_SIGNALS_EVALUATED) {
                    ProcessPaperPipelineJob::dispatch(
                        $event->exchange,
                        $event->symbol,
                        $closeAt->format(DATE_ATOM)
                    )->onQueue($queue);
                    $recovered++;
                }

                $event->increment('attempts');
                $event->update([
                    'status' => TradingPipelineEventRecord::STATUS_DISPATCHED,
                    'dispatched_at' => CarbonImmutable::now('UTC'),
                ]);
            } catch (Throwable $e) {
                Log::warning("Recovery re-dispatch failed for {$event->event_key}: ".$e->getMessage());
            }
        }

        // 2. Discover unrecorded closed 15m candles from the last 5 minutes
        $symbols = (array) config('trading.symbols', ['BTCUSDT', 'ETHUSDT']);
        $exchanges = ['bybit', 'binance'];
        $candlesRecovered = 0;

        foreach ($exchanges as $exchange) {
            foreach ($symbols as $symbol) {
                $latest = CandleRecord::query()
                    ->where('exchange', $exchange)
                    ->where('category', 'linear')
                    ->where('symbol', $symbol)
                    ->where('interval', '15')
                    ->where('is_closed', true)
                    ->latest('open_time')
                    ->first();

                if ($latest !== null) {
                    $closeAt = $latest->open_time->toDateTimeImmutable()->modify('+15 minutes');
                    if ($this->eventService->isFresh($closeAt, $now)) {
                        $exists = TradingPipelineEventRecord::query()
                            ->where('exchange', $exchange)
                            ->where('symbol', $symbol)
                            ->where('timeframe', '15')
                            ->where('stage', TradingPipelineEventRecord::STAGE_CANDLE_CLOSED)
                            ->where('candle_close_at', $closeAt)
                            ->exists();

                        if (! $exists) {
                            $this->eventService->recordCandleClosed($exchange, $symbol, '15', $closeAt);
                            $candlesRecovered++;
                        }
                    }
                }
            }
        }

        return [
            'scanned' => $scanned,
            'recovered_events' => $recovered,
            'staled_events' => $staled,
            'unrecorded_candles_recovered' => $candlesRecovered,
            'timestamp' => $now->format(DATE_ATOM),
        ];
    }

    private function latest15mClose(string $exchange, string $symbol): ?DateTimeImmutable
    {
        $record = CandleRecord::query()
            ->where('exchange', strtolower($exchange))
            ->where('category', 'linear')
            ->where('symbol', strtoupper($symbol))
            ->where('interval', '15')
            ->where('is_closed', true)
            ->latest('open_time')
            ->first();

        return $record?->open_time->toDateTimeImmutable()->modify('+15 minutes');
    }

    private function latest5mClose(string $exchange, ?string $symbol): ?DateTimeImmutable
    {
        $record = CandleRecord::query()
            ->where('exchange', strtolower($exchange))
            ->where('category', 'linear')
            ->where('interval', '5')
            ->where('is_closed', true)
            ->when($symbol, fn ($q) => $q->where('symbol', strtoupper($symbol)))
            ->latest('open_time')
            ->first();

        return $record?->open_time->toDateTimeImmutable()->modify('+5 minutes');
    }
}
