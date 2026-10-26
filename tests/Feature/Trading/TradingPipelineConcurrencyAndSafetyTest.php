<?php

declare(strict_types=1);

namespace Tests\Feature\Trading;

use App\Exchanges\Binance\BinanceDeploymentInitializer;
use App\Exchanges\Binance\ExchangePaperReconciliation;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Application\PaperProcessingService;
use App\PaperTrading\Domain\PositionStatus;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use App\Trading\Application\TradingPipelineEventService;
use App\Trading\Infrastructure\Persistence\TradingPipelineEventRecord;
use App\Trading\Jobs\ProcessClosedCandleEventJob;
use App\Trading\Jobs\ProcessPaperPipelineJob;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class TradingPipelineConcurrencyAndSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'trading.pipeline_mode' => 'EVENT_DRIVEN',
            'exchanges.binance.paper_taker_rate' => '0.0005',
            'exchanges.binance.paper_slippage_rate' => '0.0002',
            'exchanges.binance.paper_execution_approved' => true,
            'exchanges.binance.research_cycle_enabled' => true,
        ]);
    }

    public function test_concurrent_workers_processing_same_event_are_fenced_and_idempotent(): void
    {
        $service = app(TradingPipelineEventService::class);
        $decisionTime = new DateTimeImmutable('now');

        $record = $service->recordCandleClosed('bybit', 'BTCUSDT', '15', $decisionTime);
        self::assertNotNull($record);

        // Simulate Worker 1 running the job
        $job1 = new ProcessClosedCandleEventJob($record->id, 'bybit', 'BTCUSDT', '15', $decisionTime->format(DATE_ATOM));
        // Mark record completed by Worker 1
        $record->update(['status' => TradingPipelineEventRecord::STATUS_COMPLETED]);

        // Worker 2 attempts to run the exact same job concurrently
        $job2 = new ProcessClosedCandleEventJob($record->id, 'bybit', 'BTCUSDT', '15', $decisionTime->format(DATE_ATOM));
        $job2->handle($service);

        // Must remain completed with exactly 1 completion record
        self::assertSame(TradingPipelineEventRecord::STATUS_COMPLETED, $record->fresh()->status);
        self::assertSame(1, TradingPipelineEventRecord::query()->where('event_key', $record->event_key)->count());
    }

    public function test_duplicate_paper_jobs_do_not_produce_duplicate_positions_or_financial_writes(): void
    {
        $accountService = app(PaperAccountService::class);
        $strategy = app(StrategyRegistry::class)->get('baseline');
        $account = $accountService->strategyForward('baseline', $strategy->version(), $strategy->parameters());

        $processTime = new DateTimeImmutable('now');
        $decisionTime = $processTime->modify('-5 minutes');
        $account->update(['created_at' => $decisionTime->modify('-10 minutes')]);
        $this->seedMarketContext('bybit', 'BTCUSDT', $decisionTime);

        // Pre-create a signal
        $signalId = Uuid::uuid4()->toString();
        $now = CarbonImmutable::now('UTC');
        SignalRecord::query()->create([
            'id' => $signalId,
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15',
            'strategy_id' => 'baseline',
            'strategy_version' => $strategy->version(),
            'schema_version' => '1.0.0',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 85,
            'model_risk_percent' => '0.50',
            'entry_low' => '100.0',
            'entry_high' => '101.0',
            'stop_loss' => '95.0',
            'take_profit_1' => '108.0',
            'take_profit_2' => '115.0',
            'risk_reward_1' => '1.5',
            'risk_reward_2' => '2.5',
            'decision_time' => $decisionTime,
            'candle_close_time' => $decisionTime,
            'maximum_holding_minutes' => 240,
            'expires_at' => $decisionTime->modify('+30 minutes'),
            'reasons' => ['trend' => true],
            'risks' => [],
            'indicator_snapshot' => [
                'strategy_id' => 'baseline',
                '15m' => ['atr_14' => '2.0', 'close' => '100.0'],
            ],
            'contract' => ['version' => '1.0.0'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Seed 5m execution bar matching the entry zone
        CandleRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '5',
            'open_time' => $decisionTime,
            'open' => '100.5',
            'high' => '101.2',
            'low' => '100.2',
            'close' => '100.8',
            'volume' => '100.0',
            'turnover' => '10000.0',
            'is_closed' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $pipelineService = app(TradingPipelineEventService::class);
        $paperService = app(PaperProcessingService::class);
        $processTime = $decisionTime->modify('+5 minutes');

        // Run paper job 1
        $job1 = new ProcessPaperPipelineJob('bybit', 'BTCUSDT', $processTime->format(DATE_ATOM));
        $job1->handle($pipelineService, $paperService);

        $initialPositionsCount = VirtualPositionRecord::query()->where('paper_account_id', $account->id)->count();
        self::assertSame(1, $initialPositionsCount);

        // Run duplicate paper job 2 for same timestamp
        $job2 = new ProcessPaperPipelineJob('bybit', 'BTCUSDT', $processTime->format(DATE_ATOM));
        $job2->handle($pipelineService, $paperService);

        // Position count must NOT increase
        self::assertSame(1, VirtualPositionRecord::query()->where('paper_account_id', $account->id)->count());
    }

    public function test_temporal_causality_guards_prevent_retroactive_fills(): void
    {
        $accountService = app(PaperAccountService::class);
        $strategy = app(StrategyRegistry::class)->get('baseline');
        $account = $accountService->strategyForward('baseline', $strategy->version(), $strategy->parameters());

        // Decision at 10:15
        $decisionTime = new DateTimeImmutable('2026-10-26T10:15:00Z');
        $this->seedMarketContext('bybit', 'BTCUSDT', $decisionTime);

        // A bar from BEFORE the decision (e.g. 10:05 to 10:10) cannot fill an order issued at 10:15!
        $pastBarTime = new DateTimeImmutable('2026-10-26T10:05:00Z');
        CandleRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '5',
            'open_time' => $pastBarTime,
            'open' => '100.5',
            'high' => '101.0',
            'low' => '100.0',
            'close' => '100.5',
            'volume' => '100.0',
            'turnover' => '10000.0',
            'is_closed' => true,
            'created_at' => CarbonImmutable::now('UTC'),
            'updated_at' => CarbonImmutable::now('UTC'),
        ]);

        $signalId = Uuid::uuid4()->toString();
        SignalRecord::query()->create([
            'id' => $signalId,
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15',
            'strategy_id' => 'baseline',
            'strategy_version' => $strategy->version(),
            'schema_version' => '1.0.0',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 85,
            'model_risk_percent' => '0.50',
            'entry_low' => '100.0',
            'entry_high' => '101.0',
            'stop_loss' => '95.0',
            'take_profit_1' => '108.0',
            'take_profit_2' => '115.0',
            'decision_time' => $decisionTime,
            'candle_close_time' => $decisionTime,
            'maximum_holding_minutes' => 240,
            'expires_at' => $decisionTime->modify('+30 minutes'),
            'reasons' => ['trend' => true],
            'risks' => [],
            'indicator_snapshot' => [
                'strategy_id' => 'baseline',
                '15m' => ['atr_14' => '2.0', 'close' => '100.0'],
            ],
            'contract' => ['version' => '1.0.0'],
            'created_at' => CarbonImmutable::now('UTC'),
            'updated_at' => CarbonImmutable::now('UTC'),
        ]);

        $paperService = app(PaperProcessingService::class);
        $paperService->process($account->id, 'BTCUSDT', $decisionTime);

        // Position cannot be opened by the past candle
        $position = VirtualPositionRecord::query()->where('paper_account_id', $account->id)->first();
        self::assertNull($position, 'Past candle from before signal decision must never trigger a retroactive fill.');
    }

    public function test_existing_open_positions_continue_lifecycle_even_when_new_entries_are_blocked(): void
    {
        $accountService = app(PaperAccountService::class);
        $strategy = app(StrategyRegistry::class)->get('baseline');
        $account = $accountService->strategyForward('baseline', $strategy->version(), $strategy->parameters());

        $decisionTime = new DateTimeImmutable('2026-10-26T10:15:00Z');
        $this->seedMarketContext('bybit', 'BTCUSDT', $decisionTime);

        $now = CarbonImmutable::now('UTC');
        $signalId = Uuid::uuid4()->toString();
        SignalRecord::query()->create([
            'id' => $signalId,
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15',
            'strategy_id' => 'baseline',
            'strategy_version' => $strategy->version(),
            'schema_version' => '1.0.0',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 85,
            'model_risk_percent' => '0.50',
            'entry_low' => '100.0',
            'entry_high' => '101.0',
            'stop_loss' => '95.0',
            'take_profit_1' => '108.0',
            'take_profit_2' => '115.0',
            'decision_time' => $decisionTime,
            'candle_close_time' => $decisionTime,
            'maximum_holding_minutes' => 240,
            'expires_at' => $decisionTime->modify('+30 minutes'),
            'reasons' => ['trend' => true],
            'risks' => [],
            'indicator_snapshot' => [
                'strategy_id' => 'baseline',
                '15m' => ['atr_14' => '2.0', 'close' => '100.0'],
            ],
            'contract' => ['version' => '1.0.0'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Entry candle at 10:15..10:20
        CandleRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '5',
            'open_time' => $decisionTime,
            'open' => '100.5',
            'high' => '101.0',
            'low' => '100.0',
            'close' => '100.8',
            'volume' => '100.0',
            'turnover' => '10000.0',
            'is_closed' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $paperService = app(PaperProcessingService::class);
        $paperService->process($account->id, 'BTCUSDT', $decisionTime->modify('+5 minutes'), allowNewEntries: true);

        $position = VirtualPositionRecord::query()->where('paper_account_id', $account->id)->first();
        self::assertNotNull($position);
        self::assertSame('OPEN', $position->status);

        // Next bar at 10:20..10:25 reaches TAKE PROFIT 1 (108.0)
        CandleRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '5',
            'open_time' => $decisionTime->modify('+5 minutes'),
            'open' => '101.0',
            'high' => '109.0', // Hits TP1 (108.0)
            'low' => '100.5',
            'close' => '108.5',
            'volume' => '100.0',
            'turnover' => '10000.0',
            'is_closed' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Even if new entries are now BLOCKED (allowNewEntries: false), active position must be managed!
        $paperService->process($account->id, 'BTCUSDT', $decisionTime->modify('+10 minutes'), allowNewEntries: false);

        $refreshed = $position->fresh();
        self::assertSame(PositionStatus::PartiallyClosed->value, $refreshed->status);
    }

    public function test_paper_execution_ownership_preserves_ledger_integrity_and_reconciliation(): void
    {
        $initializer = app(BinanceDeploymentInitializer::class);
        $initializer->initialize('10000', 'OWNER-VECTOR-20261023');

        $reconciliation = app(ExchangePaperReconciliation::class);
        $report = $reconciliation->inspect();

        self::assertSame('LEDGER_PASS_ONLY', $report['status']);
        self::assertSame([], $report['issues']);
    }

    private function seedMarketContext(string $exchange, string $symbol, DateTimeImmutable $at): void
    {
        MarketContextRecord::query()->create([
            'exchange' => $exchange,
            'category' => 'linear',
            'symbol' => $symbol,
            'observed_at' => $at,
            'funding_rate' => '0.0001',
            'open_interest' => '50000',
            'open_interest_change' => '0.01',
            'turnover_24h' => '100000000',
            'tick_size' => '0.1',
            'bid' => '100.0',
            'ask' => '100.1',
            'qty_step' => '0.001',
            'min_order_qty' => '0.001',
            'max_order_qty' => '100.0',
            'min_notional_value' => '5.0',
            'created_at' => CarbonImmutable::now('UTC'),
            'updated_at' => CarbonImmutable::now('UTC'),
        ]);
    }
}
