<?php

declare(strict_types=1);

namespace Tests\Feature\Trading;

use App\Trading\Application\TradingPipelineEventService;
use App\Trading\Infrastructure\Persistence\TradingPipelineEventRecord;
use App\Trading\Jobs\ProcessClosedCandleEventJob;
use App\Trading\Jobs\ProcessPaperPipelineJob;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class TradingPipelineEventContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_candle_closed_dispatches_to_isolated_bybit_queue(): void
    {
        Queue::fake();
        config(['trading.pipeline_mode' => 'EVENT_DRIVEN']);

        $service = app(TradingPipelineEventService::class);
        $closeAt = new DateTimeImmutable('now');

        $record = $service->recordCandleClosed('bybit', 'BTCUSDT', '15', $closeAt);

        self::assertNotNull($record);
        self::assertSame('bybit', $record->exchange);
        self::assertSame('BTCUSDT', $record->symbol);
        self::assertSame('15', $record->timeframe);
        self::assertSame(TradingPipelineEventRecord::STAGE_CANDLE_CLOSED, $record->stage);
        self::assertSame(TradingPipelineEventRecord::STATUS_DISPATCHED, $record->status);

        Queue::assertPushedOn('trading-bybit', ProcessClosedCandleEventJob::class);
    }

    public function test_record_candle_closed_dispatches_to_isolated_binance_queue(): void
    {
        Queue::fake();
        config(['trading.pipeline_mode' => 'EVENT_DRIVEN']);

        $service = app(TradingPipelineEventService::class);
        $closeAt = new DateTimeImmutable('now');

        $record = $service->recordCandleClosed('binance', 'ETHUSDT', '15', $closeAt);

        self::assertNotNull($record);
        self::assertSame('binance', $record->exchange);
        self::assertSame('ETHUSDT', $record->symbol);
        self::assertSame('15', $record->timeframe);
        self::assertSame(TradingPipelineEventRecord::STAGE_CANDLE_CLOSED, $record->stage);

        Queue::assertPushedOn('trading-binance', ProcessClosedCandleEventJob::class);
        Queue::assertNotPushed(ProcessClosedCandleEventJob::class, fn ($job) => $job->queue === 'trading-bybit');
    }

    public function test_duplicate_candle_closure_is_strictly_idempotent(): void
    {
        Queue::fake();
        config(['trading.pipeline_mode' => 'EVENT_DRIVEN']);

        $service = app(TradingPipelineEventService::class);
        $closeAt = new DateTimeImmutable('2026-10-26T12:00:00Z');

        $first = $service->recordCandleClosed('bybit', 'BTCUSDT', '15', $closeAt);
        $second = $service->recordCandleClosed('bybit', 'BTCUSDT', '15', $closeAt);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame($first->id, $second->id);
        self::assertSame(1, TradingPipelineEventRecord::query()->where('event_key', $first->event_key)->count());

        // Queue must have only 1 job pushed, not 2
        Queue::assertPushed(ProcessClosedCandleEventJob::class, 1);
    }

    public function test_scheduled_mode_suppresses_queue_dispatch_and_keeps_event_pending(): void
    {
        Queue::fake();
        config(['trading.pipeline_mode' => 'SCHEDULED']);

        $service = app(TradingPipelineEventService::class);
        $closeAt = new DateTimeImmutable('now');

        $record = $service->recordCandleClosed('bybit', 'BTCUSDT', '15', $closeAt);

        self::assertNotNull($record);
        self::assertSame(TradingPipelineEventRecord::STATUS_PENDING, $record->status);

        Queue::assertNothingPushed();
    }

    public function test_stale_candle_closure_is_rejected_and_marked_stale(): void
    {
        config(['trading.pipeline_mode' => 'EVENT_DRIVEN']);
        $service = app(TradingPipelineEventService::class);

        // Candle closed 350 seconds ago (exceeding 300s limit)
        $staleCloseAt = (new DateTimeImmutable('now'))->modify('-350 seconds');

        $record = $service->recordCandleClosed('bybit', 'BTCUSDT', '15', $staleCloseAt);
        self::assertNotNull($record);

        $job = new ProcessClosedCandleEventJob(
            $record->id,
            'bybit',
            'BTCUSDT',
            '15',
            $staleCloseAt->format(DATE_ATOM)
        );

        $job->handle($service);

        $freshRecord = $record->fresh();
        self::assertSame(TradingPipelineEventRecord::STATUS_STALE, $freshRecord->status);
        self::assertStringContainsString('300s', (string) $freshRecord->last_error);
    }

    public function test_signals_evaluated_dispatches_paper_trading_on_isolated_queue(): void
    {
        Queue::fake();
        config(['trading.pipeline_mode' => 'EVENT_DRIVEN']);

        $service = app(TradingPipelineEventService::class);
        $closeAt = new DateTimeImmutable('now');

        $service->recordSignalsEvaluated('binance', 'BTCUSDT', $closeAt, 13);

        Queue::assertPushedOn('trading-binance', ProcessPaperPipelineJob::class);
    }
}
