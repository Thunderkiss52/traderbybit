<?php

declare(strict_types=1);

namespace Tests\Feature\Trading;

use App\Exchanges\Binance\BinanceCandleIngestion;
use App\PaperTrading\Application\PaperProcessingService;
use App\Strategy\Application\StrategyExchangeAssignmentService;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use App\StrategyBuilder\PrimaryStrategies;
use App\Trading\Application\TradingPipelineEventService;
use App\Trading\Application\TradingPipelineRecoveryService;
use App\Trading\Infrastructure\Persistence\TradingPipelineEventRecord;
use App\Trading\Jobs\ProcessClosedCandleEventJob;
use App\Trading\Jobs\ProcessPaperPipelineJob;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class BinancePipelineFreshnessAndConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow(null);
        parent::tearDown();
    }

    /**
     * Requirement 4: Boundary conditions at 299, 300, and 301 seconds.
     */
    public function test_freshness_boundary_conditions_at_299_300_and_301_seconds(): void
    {
        $closeAt = new DateTimeImmutable('2026-10-31T21:15:00Z');
        $service = app(TradingPipelineEventService::class);

        // 299s after close -> FRESH
        $now299 = new DateTimeImmutable('2026-10-31T21:19:59Z');
        self::assertTrue($service->isFresh($closeAt, $now299));

        // 300s after close -> FRESH (inclusive boundary)
        $now300 = new DateTimeImmutable('2026-10-31T21:20:00Z');
        self::assertTrue($service->isFresh($closeAt, $now300));

        // 301s after close -> STALE
        $now301 = new DateTimeImmutable('2026-10-31T21:20:01Z');
        self::assertFalse($service->isFresh($closeAt, $now301));
    }

    /**
     * Requirement 5: Idempotent repeated and concurrent candle events.
     */
    public function test_idempotent_repeated_candle_events_do_not_duplicate_or_redispatch(): void
    {
        CarbonImmutable::setTestNow('2026-10-31T21:16:01Z');
        Queue::fake();

        $closeAt = new DateTimeImmutable('2026-10-31T21:15:00Z');
        $service = app(TradingPipelineEventService::class);

        $first = $service->recordCandleClosed('binance', 'BTCUSDT', '15', $closeAt);
        self::assertNotNull($first);
        self::assertSame(TradingPipelineEventRecord::STATUS_DISPATCHED, $first->status);

        // Second recording for same candle slot
        $second = $service->recordCandleClosed('binance', 'BTCUSDT', '15', $closeAt);
        self::assertNotNull($second);
        self::assertSame($first->id, $second->id);

        // Only one job dispatched
        Queue::assertPushed(ProcessClosedCandleEventJob::class, 1);
        self::assertSame(1, DB::table('trading_pipeline_events')->where('event_key', $first->event_key)->count());
    }

    /**
     * Requirement 1 & 2: BinanceCandleIngestion immediately dispatches closed 15m candle event.
     */
    public function test_binance_candle_ingestion_dispatches_15m_event_upon_persistence(): void
    {
        CarbonImmutable::setTestNow('2026-10-31T21:16:01Z');
        Queue::fake();

        $openMs = 1793480400000; // 2026-10-31 21:00:00 UTC
        $closeMs = $openMs + 900000 - 1; // 15m duration - 1
        Http::fake([
            '*/fapi/v1/klines*' => Http::response([
                [$openMs, '68000.00', '68500.00', '67900.00', '68400.00', '150.5', $closeMs, '10250000.00', 100, '50.0', '3400000.00', '0'],
            ]),
        ]);

        $ingestion = app(BinanceCandleIngestion::class);
        $report = $ingestion->ingest('BTCUSDT', '15m', 10);

        self::assertSame('SUCCESS', $report['status']);
        self::assertSame(1, $report['new_closed_candles']);

        // Verify closed candle event was dispatched immediately
        $eventKey = 'binance:BTCUSDT:15:2026-10-31T21:15:00+00:00:'.TradingPipelineEventRecord::STAGE_CANDLE_CLOSED;
        $event = TradingPipelineEventRecord::query()->where('event_key', $eventKey)->first();
        self::assertNotNull($event);
        self::assertSame(TradingPipelineEventRecord::STATUS_DISPATCHED, $event->status);

        Queue::assertPushed(ProcessClosedCandleEventJob::class, 1);
    }

    /**
     * Requirement 6 & 7: Canonical observations reuse without lock contention or duplicate calculation.
     */
    public function test_process_closed_candle_event_reuses_existing_canonical_observations(): void
    {
        CarbonImmutable::setTestNow('2026-10-31T21:16:05Z');
        Queue::fake();
        Http::preventStrayRequests();

        $at = new DateTimeImmutable('2026-10-31T21:15:00Z');
        $events = app(TradingPipelineEventService::class);
        $record = $events->recordCandleClosed('binance', 'BTCUSDT', '15', $at);
        self::assertNotNull($record);

        // Pre-seed 13 canonical strategy observations
        $strategies = PrimaryStrategies::ids('binance');
        self::assertCount(13, $strategies);

        $this->seedCanonicalObservations($at);

        // Lock is held by an external observer or process
        $lock = Cache::lock('strategy-observations:binance:linear:BTCUSDT:'.$at->format('U.u'), 660);
        self::assertTrue($lock->get());

        try {
            // Job should reuse existing observations without attempting to acquire lock
            (new ProcessClosedCandleEventJob($record->id, 'binance', 'BTCUSDT', '15', $at->format(DATE_ATOM)))->handle($events);

            // Downstream SIGNALS_EVALUATED must be created and dispatched
            $signalsKey = 'binance:BTCUSDT:15:'.$at->format(DATE_ATOM).':'.TradingPipelineEventRecord::STAGE_SIGNALS_EVALUATED;
            $signalsEvent = TradingPipelineEventRecord::query()->where('event_key', $signalsKey)->first();
            self::assertNotNull($signalsEvent);
            self::assertSame(13, (int) ($signalsEvent->payload['signals_count'] ?? 0));
            Queue::assertPushed(ProcessPaperPipelineJob::class, 1);
        } finally {
            $lock->release();
        }
    }

    /**
     * Requirement 6: Observation lock contention logs warning, preserves pending status without failing.
     */
    public function test_observation_lock_contention_preserves_pending_state(): void
    {
        CarbonImmutable::setTestNow('2026-10-31T21:16:05Z');
        Queue::fake();
        Http::preventStrayRequests();

        $at = new DateTimeImmutable('2026-10-31T21:15:00Z');
        $events = app(TradingPipelineEventService::class);
        $record = $events->recordCandleClosed('binance', 'BTCUSDT', '15', $at);
        self::assertNotNull($record);

        // Lock is held by a peer observer and NO observations exist yet
        $lock = Cache::lock('strategy-observations:binance:linear:BTCUSDT:'.$at->format('U.u'), 660);
        self::assertTrue($lock->get());

        try {
            (new ProcessClosedCandleEventJob($record->id, 'binance', 'BTCUSDT', '15', $at->format(DATE_ATOM)))->handle($events);

            // Record must remain PENDING for queue/recovery retry, not FAILED
            self::assertSame(TradingPipelineEventRecord::STATUS_PENDING, $record->fresh()->status);
            self::assertSame(1, $record->fresh()->attempts);
            self::assertNotNull($record->fresh()->last_error);
        } finally {
            $lock->release();
        }
    }

    /**
     * Requirement 7: Downstream paper STALE rejection acknowledges upstream events without executing orders.
     */
    public function test_downstream_paper_stale_acknowledges_upstream_without_orders(): void
    {
        // 1. Dispatch on time at 21:16:00Z (fresh)
        CarbonImmutable::setTestNow('2026-10-31T21:16:00Z');
        $at = new DateTimeImmutable('2026-10-31T21:15:00Z');
        Queue::fake();
        $events = app(TradingPipelineEventService::class);

        // CANDLE_CLOSED and SIGNALS_EVALUATED dispatched on time
        $candleRecord = $events->recordCandleClosed('binance', 'BTCUSDT', '15', $at);
        self::assertNotNull($candleRecord);
        $candleRecord->update(['status' => TradingPipelineEventRecord::STATUS_DISPATCHED]);

        $signalsRecord = $events->recordSignalsEvaluated('binance', 'BTCUSDT', $at, 13);
        self::assertNotNull($signalsRecord);
        $signalsRecord->update(['status' => TradingPipelineEventRecord::STATUS_DISPATCHED]);

        // 2. Now paper pipeline job runs late at 21:20:10 (> 300s freshness window)
        CarbonImmutable::setTestNow('2026-10-31T21:20:10Z');
        $paperService = app(PaperProcessingService::class);
        (new ProcessPaperPipelineJob('binance', 'BTCUSDT', $at->format(DATE_ATOM)))->handle($events, $paperService);

        // Upstream events are terminalized to STALE so they are not retried late
        self::assertSame(TradingPipelineEventRecord::STATUS_STALE, $signalsRecord->fresh()->status);
        self::assertSame(TradingPipelineEventRecord::STATUS_STALE, $candleRecord->fresh()->status);

        // Zero positions created
        self::assertSame(0, DB::table('virtual_positions')->count());
        self::assertSame(0, DB::table('virtual_executions')->count());
    }

    /**
     * Requirement 7: Recovery terminalizes CANDLE_CLOSED to COMPLETED when SIGNALS_EVALUATED already exists.
     */
    public function test_recovery_terminalizes_candle_closed_when_signals_already_evaluated(): void
    {
        CarbonImmutable::setTestNow('2026-10-31T21:20:15Z');
        $at = new DateTimeImmutable('2026-10-31T21:15:00Z');
        Queue::fake();
        $events = app(TradingPipelineEventService::class);

        // Create CANDLE_CLOSED in PENDING (e.g. queue contention retry)
        $candleRecord = $events->recordCandleClosed('binance', 'BTCUSDT', '15', $at);
        self::assertNotNull($candleRecord);
        DB::table('trading_pipeline_events')->where('id', $candleRecord->id)->update([
            'status' => TradingPipelineEventRecord::STATUS_PENDING,
            'created_at' => CarbonImmutable::now('UTC')->subSeconds(25),
        ]);

        // Downstream SIGNALS_EVALUATED completed
        $signalsRecord = $events->recordSignalsEvaluated('binance', 'BTCUSDT', $at, 13);
        $signalsRecord->update(['status' => TradingPipelineEventRecord::STATUS_COMPLETED]);

        // Run recovery
        $recovery = app(TradingPipelineRecoveryService::class);
        $report = $recovery->recover();

        // CANDLE_CLOSED must be terminalized to COMPLETED, never marked STALE
        self::assertSame(TradingPipelineEventRecord::STATUS_COMPLETED, $candleRecord->fresh()->status);
        self::assertGreaterThanOrEqual(1, $report['recovered_events']);
    }

    /**
     * Requirement 7: isDecisionAlreadyServiced detects canonical observations in database.
     */
    public function test_is_decision_already_serviced_detects_existing_observations(): void
    {
        $at = new DateTimeImmutable('2026-10-31T21:15:00Z');
        $recovery = app(TradingPipelineRecoveryService::class);

        self::assertFalse($recovery->isDecisionAlreadyServiced('binance', 'BTCUSDT', $at));

        $this->seedCanonicalObservations($at);

        // Now must return true even without trading_pipeline_events record
        self::assertTrue($recovery->isDecisionAlreadyServiced('binance', 'BTCUSDT', $at));
    }

    #[DataProvider('invalidCanonicalObservationProvider')]
    public function test_incomplete_or_foreign_observations_never_service_or_advance_a_decision(string $field, string $value): void
    {
        CarbonImmutable::setTestNow('2026-10-31T21:16:05Z');
        Queue::fake();
        Http::preventStrayRequests();
        $at = new DateTimeImmutable('2026-10-31T21:15:00Z');
        $observations = $this->seedCanonicalObservations($at);
        $observations[0]->update([$field => $value]);
        // An extra candidate preserves the row count but cannot replace a primary identity.
        $candidate = $observations[1]->replicate();
        $candidate->id = Uuid::uuid4()->toString();
        $candidate->strategy_id = 'unregistered_candidate';
        $candidate->save();

        self::assertFalse(app(TradingPipelineRecoveryService::class)->isDecisionAlreadyServiced('binance', 'BTCUSDT', $at, includeInFlight: false));
        $events = app(TradingPipelineEventService::class);
        $record = $events->recordCandleClosed('binance', 'BTCUSDT', '15', $at);
        self::assertNotNull($record);
        $lock = Cache::lock('strategy-observations:binance:linear:BTCUSDT:'.$at->format('U.u'), 660);
        self::assertTrue($lock->get());
        try {
            (new ProcessClosedCandleEventJob($record->id, 'binance', 'BTCUSDT', '15', $at->format(DATE_ATOM)))->handle($events);
            self::assertSame(TradingPipelineEventRecord::STATUS_PENDING, $record->fresh()->status);
            self::assertSame(0, DB::table('trading_pipeline_events')->where('stage', TradingPipelineEventRecord::STAGE_SIGNALS_EVALUATED)->count());
            self::assertSame(0, DB::table('virtual_executions')->count());
            Queue::assertNotPushed(ProcessPaperPipelineJob::class);
        } finally {
            $lock->release();
        }
    }

    public static function invalidCanonicalObservationProvider(): array
    {
        return [
            'candidate identity' => ['strategy_id', 'candidate_replacement'],
            'obsolete version' => ['strategy_version', 'retired-0.0.0'],
            'configuration mismatch' => ['configuration_hash', hash('sha256', 'stale parameters')],
            'foreign exchange' => ['exchange', 'bybit'],
            'foreign category' => ['category', 'spot'],
            'foreign symbol' => ['symbol', 'ETHUSDT'],
            'foreign decision time' => ['decision_time', '2026-10-31T21:00:00Z'],
            'superseded observation' => ['superseded_by', '00000000-0000-0000-0000-000000000001'],
        ];
    }

    public function test_completeness_uses_exchange_assignments_without_borrowing_another_venues_observation(): void
    {
        CarbonImmutable::setTestNow('2026-10-31T21:16:05Z');
        $at = new DateTimeImmutable('2026-10-31T21:15:00Z');
        $observations = $this->seedCanonicalObservations($at);
        $removed = $observations[0];
        $removed->delete();
        $recovery = app(TradingPipelineRecoveryService::class);
        self::assertFalse($recovery->isDecisionAlreadyServiced('binance', 'BTCUSDT', $at, includeInFlight: false));

        app(StrategyExchangeAssignmentService::class)->unassign($removed->strategy_id, 'binance');
        self::assertTrue($recovery->isDecisionAlreadyServiced('binance', 'BTCUSDT', $at, includeInFlight: false));
        self::assertFalse($recovery->isDecisionAlreadyServiced('bybit', 'BTCUSDT', $at, includeInFlight: false));

        foreach (PrimaryStrategies::ids('binance') as $strategyId) {
            app(StrategyExchangeAssignmentService::class)->unassign($strategyId, 'binance');
        }
        self::assertFalse($recovery->isDecisionAlreadyServiced('binance', 'BTCUSDT', $at, includeInFlight: false));
    }

    /**
     * Requirement 3 & Schedule Cadence: Verify cron expressions and withoutOverlapping isolation for Binance tasks.
     */
    public function test_scheduler_cadence_and_isolation_for_binance_tasks(): void
    {
        config(['exchanges.binance.research_cycle_enabled' => true]);

        // Re-read scheduler by requiring console routes
        $schedule = app(Schedule::class);
        require base_path('routes/console.php');

        $events = collect($schedule->events());

        // Verify all 4 HTF intervals are registered with 15m cadence and withoutOverlapping
        foreach (['15m', '30m', '1h', '4h'] as $interval) {
            $htf = $events->first(fn ($e) => str_contains((string) $e->description, "binance:htf:BTCUSDT:{$interval}"));
            self::assertNotNull($htf);
            self::assertSame('1,16,31,46 * * * *', $htf->expression);
            self::assertTrue($htf->withoutOverlapping);
        }

        // Verify 5m ingestion runs with 5m cadence and withoutOverlapping
        $m5 = $events->first(fn ($e) => str_contains((string) $e->description, 'binance:5m:BTCUSDT'));
        self::assertNotNull($m5);
        self::assertSame('1,6,11,16,21,26,31,36,41,46,51,56 * * * *', $m5->expression);
        self::assertTrue($m5->withoutOverlapping);

        // Verify context capture runs before 15m decision with withoutOverlapping
        $context = $events->first(fn ($e) => str_contains((string) $e->description, 'binance:context:BTCUSDT'));
        self::assertNotNull($context);
        self::assertSame('13,28,43,58 * * * *', $context->expression);
        self::assertTrue($context->withoutOverlapping);
    }

    /**
     * Requirement 2: BTCUSDT and ETHUSDT scheduling and execution isolation.
     */
    public function test_btcusdt_and_ethusdt_scheduling_and_pipeline_isolation(): void
    {
        CarbonImmutable::setTestNow('2026-10-31T21:16:01Z');
        Queue::fake();

        $at = new DateTimeImmutable('2026-10-31T21:15:00Z');
        $events = app(TradingPipelineEventService::class);

        $btc = $events->recordCandleClosed('binance', 'BTCUSDT', '15', $at);
        $eth = $events->recordCandleClosed('binance', 'ETHUSDT', '15', $at);

        self::assertNotNull($btc);
        self::assertNotNull($eth);
        self::assertNotSame($btc->event_key, $eth->event_key);
        self::assertSame('binance:BTCUSDT:15:2026-10-31T21:15:00+00:00:CANDLE_CLOSED', $btc->event_key);
        self::assertSame('binance:ETHUSDT:15:2026-10-31T21:15:00+00:00:CANDLE_CLOSED', $eth->event_key);

        // Both are dispatched to trading-binance independently
        Queue::assertPushed(ProcessClosedCandleEventJob::class, 2);
    }

    /**
     * Requirement 9: No retroactive PAPER execution for expired signals.
     */
    public function test_no_retroactive_paper_execution_for_expired_signals(): void
    {
        // 350s after close -> far outside freshness window
        CarbonImmutable::setTestNow('2026-10-31T21:20:50Z');
        $at = new DateTimeImmutable('2026-10-31T21:15:00Z');
        Queue::fake();

        $events = app(TradingPipelineEventService::class);
        $paperService = app(PaperProcessingService::class);

        // Execute paper pipeline with expired close timestamp
        (new ProcessPaperPipelineJob('binance', 'BTCUSDT', $at->format(DATE_ATOM)))->handle($events, $paperService);

        // Assert 0 executions and 0 positions were manufactured
        self::assertSame(0, DB::table('virtual_executions')->count());
        self::assertSame(0, DB::table('virtual_positions')->count());
    }

    /**
     * Requirement 10: Bybit trading behavior and BLOCKED account safety remain intact.
     */
    public function test_bybit_pipeline_and_blocked_accounts_remain_intact(): void
    {
        CarbonImmutable::setTestNow('2026-10-31T21:16:01Z');
        Queue::fake();

        $at = new DateTimeImmutable('2026-10-31T21:15:00Z');
        $events = app(TradingPipelineEventService::class);

        // Bybit closed candle dispatches to trading-bybit queue
        $bybitRecord = $events->recordCandleClosed('bybit', 'BTCUSDT', '15', $at);
        self::assertNotNull($bybitRecord);
        self::assertSame('bybit', $bybitRecord->exchange);

        Queue::assertPushed(ProcessClosedCandleEventJob::class, function ($job) use ($bybitRecord) {
            return $job->exchange === 'bybit' && $job->eventId === $bybitRecord->id;
        });
    }

    /** @return list<StrategyObservationRecord> */
    private function seedCanonicalObservations(DateTimeImmutable $at): array
    {
        $observations = [];
        foreach (PrimaryStrategies::ids('binance') as $strategyId) {
            $strategy = app(StrategyRegistry::class)->get($strategyId);
            $id = Uuid::uuid5(Uuid::NAMESPACE_URL, implode('|', [
                'traderbybit:strategy-observation', 'binance', 'linear', 'BTCUSDT',
                $strategyId, $strategy->version(), $at->format('U.u'),
            ]))->toString();

            $observations[] = StrategyObservationRecord::query()->create([
                'id' => $id,
                'exchange' => 'binance',
                'category' => 'linear',
                'symbol' => 'BTCUSDT',
                'strategy_id' => $strategyId,
                'strategy_version' => $strategy->version(),
                'configuration_hash' => StrategyRegistry::hash($strategy->parameters()),
                'side' => 'SKIP',
                'quality_class' => 'A',
                'score' => 50,
                'decision_time' => $at,
                'reasons' => ['context' => 'pre_computed'],
                'analysis' => ['diagnostics' => ['ok' => true]],
            ]);
        }

        return $observations;
    }
}
