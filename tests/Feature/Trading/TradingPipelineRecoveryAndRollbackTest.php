<?php

declare(strict_types=1);

namespace Tests\Feature\Trading;

use App\Strategy\Infrastructure\Persistence\SignalRecord;
use App\Strategy\Jobs\GenerateSignalJob;
use App\Trading\Application\TradingPipelineEventService;
use App\Trading\Application\TradingPipelineRecoveryService;
use App\Trading\Infrastructure\Persistence\TradingPipelineEventRecord;
use App\Trading\Jobs\ProcessClosedCandleEventJob;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class TradingPipelineRecoveryAndRollbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_recovery_redispatches_fresh_uncompleted_events(): void
    {
        Queue::fake();
        config(['trading.pipeline_mode' => 'EVENT_DRIVEN']);

        $service = app(TradingPipelineEventService::class);
        $recovery = app(TradingPipelineRecoveryService::class);

        // Event from 45 seconds ago (within 300s window)
        $closeAt = (new DateTimeImmutable('now'))->modify('-45 seconds');
        $event = $service->recordCandleClosed('bybit', 'BTCUSDT', '15', $closeAt);

        self::assertNotNull($event);
        // Simulate stuck in PENDING (e.g. Redis connection hiccup)
        $event->update([
            'status' => TradingPipelineEventRecord::STATUS_PENDING,
            'created_at' => CarbonImmutable::now('UTC')->subSeconds(25),
        ]);

        $report = $recovery->recover();

        self::assertGreaterThanOrEqual(1, $report['recovered_events']);
        Queue::assertPushedOn('trading-bybit', ProcessClosedCandleEventJob::class);
    }

    public function test_recovery_marks_expired_events_as_stale_without_dispatching(): void
    {
        Queue::fake();
        config(['trading.pipeline_mode' => 'EVENT_DRIVEN']);

        $service = app(TradingPipelineEventService::class);
        $recovery = app(TradingPipelineRecoveryService::class);

        // Event from 400 seconds ago (exceeding 300s freshness window)
        $closeAt = (new DateTimeImmutable('now'))->modify('-400 seconds');
        $event = $service->recordCandleClosed('binance', 'BTCUSDT', '15', $closeAt);

        self::assertNotNull($event);
        $event->update([
            'status' => TradingPipelineEventRecord::STATUS_PENDING,
            'created_at' => CarbonImmutable::now('UTC')->subSeconds(30),
        ]);

        // Fake queue fresh before running recovery
        Queue::fake();

        $report = $recovery->recover();

        self::assertGreaterThanOrEqual(1, $report['staled_events']);
        self::assertSame(TradingPipelineEventRecord::STATUS_STALE, $event->fresh()->status);
        self::assertStringContainsString('300s', (string) $event->fresh()->last_error);

        // Expired event must not be dispatched
        Queue::assertNothingPushed();
    }

    public function test_scheduled_job_skips_when_event_driven_pipeline_already_serviced_decision(): void
    {
        Queue::fake();
        config(['trading.pipeline_mode' => 'EVENT_DRIVEN']);

        $nowTs = time();
        $alignedTs = $nowTs - ($nowTs % 900);
        $decisionTime = (new DateTimeImmutable("@{$alignedTs}"))->setTimezone(new \DateTimeZone('UTC'));

        $service = app(TradingPipelineEventService::class);
        // Mark signals already evaluated by pipeline
        $service->recordSignalsEvaluated('bybit', 'BTCUSDT', $decisionTime, 13);

        $recovery = app(TradingPipelineRecoveryService::class);
        self::assertTrue($recovery->isDecisionAlreadyServiced('bybit', 'BTCUSDT', $decisionTime));

        // When GenerateSignalJob runs for this decision time, it skips scan
        $signalCountBefore = SignalRecord::query()->count();

        $job = new GenerateSignalJob('BTCUSDT', $decisionTime->format(DATE_ATOM));
        $job->handle();

        // Count must not increase because it skipped
        self::assertSame($signalCountBefore, SignalRecord::query()->count());
    }

    public function test_feature_flag_rollback_switches_cleanly_without_duplicate_writers(): void
    {
        // 1. In EVENT_DRIVEN mode
        config(['trading.pipeline_mode' => 'EVENT_DRIVEN']);
        $service = app(TradingPipelineEventService::class);
        self::assertTrue($service->isEventDriven());

        // 2. Rollback to SCHEDULED mode
        config(['trading.pipeline_mode' => 'SCHEDULED']);
        self::assertFalse($service->isEventDriven());

        Queue::fake();
        $closeAt = new DateTimeImmutable('now');
        $record = $service->recordCandleClosed('bybit', 'BTCUSDT', '15', $closeAt);

        self::assertNotNull($record);
        self::assertSame(TradingPipelineEventRecord::STATUS_PENDING, $record->status);
        Queue::assertNothingPushed();

        // 3. Switch back to EVENT_DRIVEN
        config(['trading.pipeline_mode' => 'EVENT_DRIVEN']);
        self::assertTrue($service->isEventDriven());
    }
}
