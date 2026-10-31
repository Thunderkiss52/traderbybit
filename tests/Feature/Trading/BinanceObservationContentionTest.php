<?php

declare(strict_types=1);

namespace Tests\Feature\Trading;

use App\Strategy\Application\StrategyObservationService;
use App\Strategy\Exceptions\StrategyObservationActiveException;
use App\Strategy\Jobs\ObserveStrategiesJob;
use App\Trading\Application\TradingPipelineEventService;
use App\Trading\Application\TradingPipelineRecoveryService;
use App\Trading\Infrastructure\Persistence\TradingPipelineEventRecord;
use App\Trading\Jobs\ProcessClosedCandleEventJob;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class BinanceObservationContentionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow(null);
        parent::tearDown();
    }

    public function test_contended_binance_decision_remains_pending_and_is_redispatched_while_fresh(): void
    {
        CarbonImmutable::setTestNow('2026-10-31T09:00:01Z');
        $at = new DateTimeImmutable('2026-10-31T09:00:00Z');
        Queue::fake();
        Http::preventStrayRequests();
        $events = app(TradingPipelineEventService::class);
        $record = $events->recordCandleClosed('binance', 'BTCUSDT', '15', $at);
        self::assertNotNull($record);
        $lock = Cache::lock('strategy-observations:binance:linear:BTCUSDT:'.$at->format('U.u'), 660);
        self::assertTrue($lock->get());
        try {
            (new ProcessClosedCandleEventJob($record->id, 'binance', 'BTCUSDT', '15', $at->format(DATE_ATOM)))->handle($events);
            self::assertSame(TradingPipelineEventRecord::STATUS_PENDING, $record->fresh()->status);
            self::assertSame(1, $record->fresh()->attempts);
            self::assertSame(0, DB::table('signals')->count());
            self::assertSame(0, DB::table('virtual_executions')->count());
            self::assertSame(0, DB::table('trading_pipeline_events')->where('stage', TradingPipelineEventRecord::STAGE_SIGNALS_EVALUATED)->count());

            CarbonImmutable::setTestNow('2026-10-31T09:00:30Z');
            $report = app(TradingPipelineRecoveryService::class)->recover();
            self::assertGreaterThanOrEqual(1, $report['recovered_events']);
            self::assertSame(TradingPipelineEventRecord::STATUS_DISPATCHED, $record->fresh()->status);
            Queue::assertPushed(ProcessClosedCandleEventJob::class, 2);
        } finally {
            $lock->release();
        }
    }

    public function test_explicit_observation_job_surfaces_lock_contention_for_bounded_queue_retry(): void
    {
        $at = new DateTimeImmutable('2026-10-31T09:00:00Z');
        $lock = Cache::lock('strategy-observations:bybit:linear:BTCUSDT:'.$at->format('U.u'), 660);
        self::assertTrue($lock->get());
        try {
            $this->expectException(StrategyObservationActiveException::class);
            (new ObserveStrategiesJob('BTCUSDT', $at->format(DATE_ATOM)))->handle(app(StrategyObservationService::class));
        } finally {
            $lock->release();
        }
    }

    public function test_delayed_duplicate_cannot_downgrade_terminal_pipeline_status(): void
    {
        CarbonImmutable::setTestNow('2026-10-31T09:00:01Z');
        Queue::fake();
        $events = app(TradingPipelineEventService::class);
        foreach ([
            TradingPipelineEventRecord::STATUS_COMPLETED,
            TradingPipelineEventRecord::STATUS_PARTIAL,
            TradingPipelineEventRecord::STATUS_STALE,
        ] as $offset => $status) {
            $at = new DateTimeImmutable('2026-10-31T09:00:00Z')->modify("-{$offset} seconds");
            $record = $events->recordCandleClosed('binance', 'BTCUSDT', '15', $at);
            self::assertNotNull($record);
            $record->update(['status' => $status]);
            (new ProcessClosedCandleEventJob($record->id, 'binance', 'BTCUSDT', '15', $at->format(DATE_ATOM)))->handle($events);
            self::assertSame($status, $record->fresh()->status);
            self::assertSame(0, $record->fresh()->attempts);
        }
        $old = new DateTimeImmutable('2026-10-30T09:00:00Z');
        $failed = $events->recordCandleClosed('binance', 'ETHUSDT', '15', $old);
        self::assertNotNull($failed);
        $failed->update(['status' => TradingPipelineEventRecord::STATUS_FAILED, 'last_error' => 'original computation error']);
        (new ProcessClosedCandleEventJob($failed->id, 'binance', 'ETHUSDT', '15', $old->format(DATE_ATOM)))->handle($events);
        self::assertSame(TradingPipelineEventRecord::STATUS_FAILED, $failed->fresh()->status);
        self::assertSame('original computation error', $failed->fresh()->last_error);
        self::assertSame(0, DB::table('virtual_executions')->count());
    }
}
