<?php

declare(strict_types=1);

namespace Tests\Feature\Trading;

use App\Exchanges\Binance\BinanceDeploymentInitializer;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Application\PaperProcessingService;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\Strategy\Application\StrategyObservationService;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Exceptions\StrategyObservationActiveException;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use App\Strategy\Jobs\ObserveStrategiesJob;
use App\StrategyBuilder\PrimaryStrategies;
use App\Trading\Application\TradingPipelineEventService;
use App\Trading\Application\TradingPipelineRecoveryService;
use App\Trading\Infrastructure\Persistence\TradingPipelineEventRecord;
use App\Trading\Jobs\ProcessClosedCandleEventJob;
use App\Trading\Jobs\ProcessPaperPipelineJob;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Ramsey\Uuid\Uuid;
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

    public function test_simultaneous_job_reuses_completed_canonical_observations_and_advances_pipeline(): void
    {
        CarbonImmutable::setTestNow('2026-10-31T09:00:01Z');
        $at = new DateTimeImmutable('2026-10-31T09:00:00Z');
        Queue::fake();
        Http::preventStrayRequests();

        $events = app(TradingPipelineEventService::class);
        $record = $events->recordCandleClosed('binance', 'BTCUSDT', '15', $at);
        self::assertNotNull($record);

        // Simulate Worker 1 having completed observations for all 13 canonical strategies
        $strategies = PrimaryStrategies::ids('binance');
        self::assertCount(13, $strategies);

        foreach ($strategies as $strategyId) {
            $strategy = app(StrategyRegistry::class)->get($strategyId);
            $id = Uuid::uuid5(Uuid::NAMESPACE_URL, implode('|', [
                'traderbybit:strategy-observation', 'binance', 'linear', 'BTCUSDT',
                $strategyId, $strategy->version(), $at->format('U.u'),
            ]))->toString();

            StrategyObservationRecord::query()->create([
                'id' => $id,
                'exchange' => 'binance',
                'category' => 'linear',
                'symbol' => 'BTCUSDT',
                'strategy_id' => $strategyId,
                'strategy_version' => $strategy->version(),
                'configuration_hash' => StrategyRegistry::hash($strategy->parameters()),
                'side' => 'BUY',
                'quality_class' => 'A',
                'score' => 80,
                'decision_time' => $at,
                'reasons' => ['trend' => true],
                'analysis' => ['diagnostics' => ['ok' => true]],
            ]);
        }

        // Simulate Worker 1 still holding the slot lock (or concurrent race)
        $lock = Cache::lock('strategy-observations:binance:linear:BTCUSDT:'.$at->format('U.u'), 660);
        self::assertTrue($lock->get());

        try {
            // Worker 2 runs ProcessClosedCandleEventJob: hits StrategyObservationActiveException
            // but detects that all 13 canonical strategy observations already exist in DB
            (new ProcessClosedCandleEventJob($record->id, 'binance', 'BTCUSDT', '15', $at->format(DATE_ATOM)))->handle($events);

            // Pipeline advances to DISPATCHED instead of remaining stuck or failing
            self::assertSame(TradingPipelineEventRecord::STATUS_DISPATCHED, $record->fresh()->status);

            // SIGNALS_EVALUATED event was recorded with count 13
            $evalEvent = TradingPipelineEventRecord::query()
                ->where('exchange', 'binance')
                ->where('symbol', 'BTCUSDT')
                ->where('stage', TradingPipelineEventRecord::STAGE_SIGNALS_EVALUATED)
                ->where('candle_close_at', $at)
                ->first();
            self::assertNotNull($evalEvent);
            self::assertSame(13, data_get($evalEvent->payload, 'signals_count'));

            // ProcessPaperPipelineJob was dispatched on trading-binance
            Queue::assertPushedOn('trading-binance', ProcessPaperPipelineJob::class);
        } finally {
            $lock->release();
        }
    }

    public function test_retry_exhaustion_past_freshness_window_marks_event_stale_without_trade_execution(): void
    {
        CarbonImmutable::setTestNow('2026-10-31T09:00:01Z');
        $at = new DateTimeImmutable('2026-10-31T09:00:00Z');
        Queue::fake();
        Http::preventStrayRequests();

        $events = app(TradingPipelineEventService::class);
        $record = $events->recordCandleClosed('binance', 'BTCUSDT', '15', $at);
        self::assertNotNull($record);

        // Lock held, event goes to PENDING
        $lock = Cache::lock('strategy-observations:binance:linear:BTCUSDT:'.$at->format('U.u'), 660);
        self::assertTrue($lock->get());
        try {
            (new ProcessClosedCandleEventJob($record->id, 'binance', 'BTCUSDT', '15', $at->format(DATE_ATOM)))->handle($events);
            self::assertSame(TradingPipelineEventRecord::STATUS_PENDING, $record->fresh()->status);

            // Time advances past 300s freshness window (e.g. 305s later)
            CarbonImmutable::setTestNow('2026-10-31T09:05:05Z');

            $recovery = app(TradingPipelineRecoveryService::class);
            $report = $recovery->recover();
            self::assertGreaterThanOrEqual(1, $report['staled_events']);

            // Event record is marked STATUS_STALE
            self::assertSame(TradingPipelineEventRecord::STATUS_STALE, $record->fresh()->status);
            self::assertStringContainsString('300s freshness window', (string) $record->fresh()->last_error);

            // Re-executing ProcessClosedCandleEventJob rejects the stale event
            (new ProcessClosedCandleEventJob($record->id, 'binance', 'BTCUSDT', '15', $at->format(DATE_ATOM)))->handle($events);
            self::assertSame(TradingPipelineEventRecord::STATUS_STALE, $record->fresh()->status);
            self::assertSame(0, DB::table('signals')->count());
            self::assertSame(0, DB::table('virtual_executions')->count());
        } finally {
            $lock->release();
        }
    }

    public function test_duplicate_candle_closed_registration_and_processing_is_strictly_idempotent(): void
    {
        CarbonImmutable::setTestNow('2026-10-31T09:00:01Z');
        $at = new DateTimeImmutable('2026-10-31T09:00:00Z');
        Queue::fake();

        $events = app(TradingPipelineEventService::class);

        // Initial candle closed registration
        $first = $events->recordCandleClosed('binance', 'BTCUSDT', '15', $at);
        self::assertNotNull($first);
        self::assertSame(TradingPipelineEventRecord::STATUS_DISPATCHED, $first->status);

        // Duplicate registration returns the exact same record and pushes no extra jobs
        $duplicate = $events->recordCandleClosed('binance', 'BTCUSDT', '15', $at);
        self::assertNotNull($duplicate);
        self::assertSame($first->id, $duplicate->id);
        Queue::assertPushed(ProcessClosedCandleEventJob::class, 1);

        // Transition to terminal COMPLETED
        $first->update(['status' => TradingPipelineEventRecord::STATUS_COMPLETED]);

        // Second job execution on already completed event is an immediate no-op
        (new ProcessClosedCandleEventJob($first->id, 'binance', 'BTCUSDT', '15', $at->format(DATE_ATOM)))->handle($events);
        self::assertSame(TradingPipelineEventRecord::STATUS_COMPLETED, $first->fresh()->status);
        self::assertSame(0, $first->fresh()->attempts);
    }

    public function test_paper_pipeline_per_account_failure_isolation_and_partial_upstream_terminalization(): void
    {
        CarbonImmutable::setTestNow('2026-10-31T09:00:01Z');
        $at = new DateTimeImmutable('2026-10-31T09:00:00Z');
        Queue::fake();

        config([
            'trading.pipeline_mode' => 'EVENT_DRIVEN',
            'exchanges.binance.paper_execution_approved' => false, // Servicing allowed, new entries blocked
            'exchanges.binance.research_cycle_enabled' => true,
        ]);

        // Provision the 13 canonical Binance paper accounts
        $initializer = app(BinanceDeploymentInitializer::class);
        $initializer->initialize('10000', 'REGRESSION-TEST-20261031');

        $accounts = PaperAccountRecord::query()->where('exchange_id', 'binance')->where('is_primary', true)->get();
        self::assertCount(13, $accounts);

        // Induce an intentional provenance defect on Account 1 only
        $failingAccount = $accounts->first();
        self::assertNotNull($failingAccount);
        $failingAccount->update(['primary_strategy_id' => 'mismatched_strategy']);

        $events = app(TradingPipelineEventService::class);
        $candleRecord = $events->recordCandleClosed('binance', 'BTCUSDT', '15', $at);
        $evalRecord = $events->recordSignalsEvaluated('binance', 'BTCUSDT', $at, 13);

        // Run paper pipeline
        $paperJob = new ProcessPaperPipelineJob('binance', 'BTCUSDT', $at->format(DATE_ATOM));
        $paperJob->handle($events, app(PaperProcessingService::class));

        // Paper processed batch must record STATUS_PARTIAL with exact failure attribution
        $paperBatch = TradingPipelineEventRecord::query()
            ->where('exchange', 'binance')
            ->where('symbol', 'BTCUSDT')
            ->where('stage', TradingPipelineEventRecord::STAGE_PAPER_PROCESSED)
            ->where('candle_close_at', $at)
            ->first();

        self::assertNotNull($paperBatch);
        self::assertSame(TradingPipelineEventRecord::STATUS_PARTIAL, $paperBatch->status);

        $payload = (array) $paperBatch->payload;
        self::assertSame(1, $payload['failures_count']);
        self::assertSame(12, $payload['processed_accounts']);
        self::assertSame($failingAccount->id, $payload['failures'][0]['account_id']);

        // Upstream events must be acknowledged to STATUS_PARTIAL (never false COMPLETED)
        self::assertSame(TradingPipelineEventRecord::STATUS_PARTIAL, $candleRecord->fresh()->status);
        self::assertSame(TradingPipelineEventRecord::STATUS_PARTIAL, $evalRecord->fresh()->status);

        // Recovery service identifies the partial batch and re-dispatches targeted retry for ONLY the failed account
        CarbonImmutable::setTestNow('2026-10-31T09:00:35Z');
        $recovery = app(TradingPipelineRecoveryService::class);
        $report = $recovery->recover();
        self::assertGreaterThanOrEqual(1, $report['recovered_events']);

        Queue::assertPushed(ProcessPaperPipelineJob::class, function (ProcessPaperPipelineJob $job) use ($failingAccount): bool {
            return $job->onlyAccountIds === [$failingAccount->id];
        });
    }

    public function test_scheduled_signals_cycle_skips_when_event_driven_pipeline_is_in_flight(): void
    {
        CarbonImmutable::setTestNow('2026-10-31T09:02:00Z');
        $at = new DateTimeImmutable('2026-10-31T09:00:00Z');
        Queue::fake();

        config(['trading.pipeline_mode' => 'EVENT_DRIVEN']);

        // Seed candle record
        CandleRecord::query()->create([
            'exchange' => 'binance',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '15',
            'open_time' => $at->modify('-15 minutes'),
            'open' => '100.0',
            'high' => '101.0',
            'low' => '99.0',
            'close' => '100.5',
            'volume' => '1000.0',
            'turnover' => '100000.0',
            'is_closed' => true,
        ]);

        $events = app(TradingPipelineEventService::class);
        $record = $events->recordCandleClosed('binance', 'BTCUSDT', '15', $at);
        self::assertNotNull($record);
        self::assertSame(TradingPipelineEventRecord::STATUS_DISPATCHED, $record->status);

        $recovery = app(TradingPipelineRecoveryService::class);

        // Decision is in flight by event-driven pipeline -> returns true to prevent competing scheduled run
        self::assertTrue($recovery->isDecisionAlreadyServiced('binance', 'BTCUSDT', $at));
        self::assertTrue($recovery->isDecisionAlreadyServiced('binance', 'BTCUSDT', null));
    }
}
