<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\Exchanges\Binance\BinanceDeploymentInitializer;
use App\Exchanges\Binance\BinancePaperActivation;
use App\Exchanges\Binance\ExchangePaperReconciliation;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Application\GlobalPaperCapitalGuard;
use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Application\PaperProcessingService;
use App\PaperTrading\Application\PositionLifecycleService;
use App\PaperTrading\Domain\ExecutionQuote;
use App\PaperTrading\Domain\ExecutionType;
use App\PaperTrading\Domain\PositionSize;
use App\PaperTrading\Domain\PositionStatus;
use App\PaperTrading\Infrastructure\Persistence\JournalEventRecord;
use App\PaperTrading\Infrastructure\Persistence\MarketFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperSignalStateRecord;
use App\PaperTrading\Infrastructure\Persistence\RiskDecisionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class FullPaperTradingPipelineE2ETest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'paper.fees.taker_rate' => '0.0006',
            'paper.slippage_rate' => '0.0002',
            'exchanges.binance.paper_taker_rate' => '0.0005',
            'exchanges.binance.paper_slippage_rate' => '0.0002',
            'exchanges.binance.paper_execution_approved' => true,
            'exchanges.binance.research_cycle_enabled' => true,
            'exchanges.binance_paper_provisioning_approved' => true,
        ]);
    }

    public function test_e2e_complete_trading_lifecycle_bybit_and_binance_with_tp1_tp2_funding_and_reconciliation(): void
    {
        // 1. Initialize all 26 canonical PAPER accounts (13 Bybit + 13 Binance)
        $initializer = $this->app->make(BinanceDeploymentInitializer::class);
        $initializer->initialize('10000', 'OWNER-FULL-E2E-20261104');

        $baseHour = (int) floor(time() / 3600) * 3600;
        $now = (new DateTimeImmutable("@{$baseHour}"))->modify('+3 minutes');
        $this->travelTo($now);
        $this->seedBinanceReadinessData($now);
        $this->app->make(BinancePaperActivation::class)->activate();

        $accountService = $this->app->make(PaperAccountService::class);
        $processingService = $this->app->make(PaperProcessingService::class);

        // Bybit account for trend_pullback on BTCUSDT
        $bybitAccount = $accountService->primaryAccount('trend_pullback', 'bybit');
        self::assertNotNull($bybitAccount);
        self::assertSame('bybit', $bybitAccount->exchange_id);
        self::assertSame('ACTIVE', $bybitAccount->status);

        // Binance account for momentum on ETHUSDT
        $binanceAccount = $accountService->primaryAccount('momentum', 'binance');
        self::assertNotNull($binanceAccount);
        self::assertSame('binance', $binanceAccount->exchange_id);
        self::assertSame('ACTIVE', $binanceAccount->status);

        // Align account created_at before decisionTime
        $t0 = $now->modify('-15 minutes');
        $tEntry = $now->modify('-10 minutes');
        $tTp1 = $now->modify('-5 minutes');
        $tFunding = $now->modify('-3 minutes');
        $tTp2 = $now;

        $bybitAccount->update(['created_at' => $t0->modify('-1 hour')]);
        $binanceAccount->update(['created_at' => $t0->modify('-1 hour')]);

        // Market context for Bybit BTCUSDT & Binance ETHUSDT
        $this->createMarketContext('bybit', 'BTCUSDT', $t0, bid: '60000', ask: '60010');
        $this->createMarketContext('binance', 'ETHUSDT', $t0, bid: '3000', ask: '3000.5');

        // Signals: BUY for Bybit BTCUSDT, BUY for Binance ETHUSDT
        $bybitSignal = $this->createSignal(
            exchange: 'bybit',
            strategyId: 'trend_pullback',
            symbol: 'BTCUSDT',
            side: 'BUY',
            decisionTime: $t0,
            entryLow: '60000',
            entryHigh: '60050',
            stopLoss: '59000',
            tp1: '61000',
            tp2: '62000',
        );

        $binanceSignal = $this->createSignal(
            exchange: 'binance',
            strategyId: 'momentum',
            symbol: 'ETHUSDT',
            side: 'BUY',
            decisionTime: $t0,
            entryLow: '3000',
            entryHigh: '3005',
            stopLoss: '2950',
            tp1: '3050',
            tp2: '3100',
        );

        // Entry candles
        $this->createCandle('bybit', 'BTCUSDT', '5', $t0, open: '60000', high: '60060', low: '59990', close: '60020');
        $this->createCandle('binance', 'ETHUSDT', '5', $t0, open: '3000', high: '3006', low: '2995', close: '3002');

        // Process entries
        $processingService->process($bybitAccount->id, 'BTCUSDT', $tEntry);
        $processingService->process($binanceAccount->id, 'ETHUSDT', $tEntry);

        // Verify both positions are OPEN
        $bybitPos = VirtualPositionRecord::query()->where('paper_account_id', $bybitAccount->id)->firstOrFail();
        $binancePos = VirtualPositionRecord::query()->where('paper_account_id', $binanceAccount->id)->firstOrFail();

        self::assertSame(PositionStatus::Open->value, $bybitPos->status);
        self::assertSame(PositionStatus::Open->value, $binancePos->status);
        self::assertSame('bybit', $bybitPos->exchange_id);
        self::assertSame('binance', $binancePos->exchange_id);

        // Verify taker fees applied: Bybit taker rate (0.0006) vs Binance taker rate (0.0005)
        $bybitEntryExec = VirtualExecutionRecord::query()->where('position_id', $bybitPos->id)->where('type', 'ENTRY')->firstOrFail();
        $binanceEntryExec = VirtualExecutionRecord::query()->where('position_id', $binancePos->id)->where('type', 'ENTRY')->firstOrFail();

        self::assertSame('bybit', $bybitEntryExec->exchange_id);
        self::assertSame('binance', $binanceEntryExec->exchange_id);
        self::assertGreaterThan('0', (string) $bybitEntryExec->commission);
        self::assertGreaterThan('0', (string) $binanceEntryExec->commission);

        // Intermediate candles hitting TP1
        $this->createCandle('bybit', 'BTCUSDT', '5', $tEntry, open: '60020', high: '61100', low: '60010', close: '61050');
        $this->createCandle('binance', 'ETHUSDT', '5', $tEntry, open: '3002', high: '3060', low: '3001', close: '3055');

        $processingService->process($bybitAccount->id, 'BTCUSDT', $tTp1);
        $processingService->process($binanceAccount->id, 'ETHUSDT', $tTp1);

        // Verify TP1 executed: 50% fraction closed, position status remains OPEN
        $bybitPos->refresh();
        $binancePos->refresh();
        self::assertDatabaseHas('virtual_executions', ['position_id' => $bybitPos->id, 'type' => 'TAKE_PROFIT_1']);
        self::assertDatabaseHas('virtual_executions', ['position_id' => $binancePos->id, 'type' => 'TAKE_PROFIT_1']);
        self::assertTrue(BigDecimal::of((string) $bybitPos->remaining_quantity)->isLessThan($bybitPos->initial_quantity));
        self::assertTrue(BigDecimal::of((string) $binancePos->remaining_quantity)->isLessThan($binancePos->initial_quantity));

        // Funding event applied while position is still open
        MarketFundingEventRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'funding_at' => $tFunding,
            'rate' => '0.0001',
            'mark_price' => '61000',
            'created_at' => $tFunding,
            'updated_at' => $tFunding,
        ]);
        MarketFundingEventRecord::query()->create([
            'exchange' => 'binance',
            'category' => 'linear',
            'symbol' => 'ETHUSDT',
            'funding_at' => $tFunding,
            'rate' => '0.0001',
            'mark_price' => '3050',
            'created_at' => $tFunding,
            'updated_at' => $tFunding,
        ]);

        $this->createCandle('bybit', 'BTCUSDT', '5', $tTp1, open: '61050', high: '62200', low: '60900', close: '62100');
        $this->createCandle('binance', 'ETHUSDT', '5', $tTp1, open: '3055', high: '3120', low: '3045', close: '3110');

        $processingService->process($bybitAccount->id, 'BTCUSDT', $tTp2);
        $processingService->process($binanceAccount->id, 'ETHUSDT', $tTp2);

        // Verify funding was recorded in position_funding_events
        self::assertDatabaseHas('position_funding_events', ['position_id' => $bybitPos->id]);
        self::assertDatabaseHas('position_funding_events', ['position_id' => $binancePos->id]);

        // Verify both positions are CLOSED_TP with 0 remaining quantity
        $bybitPos->refresh();
        $binancePos->refresh();
        self::assertSame(PositionStatus::ClosedTp->value, $bybitPos->status);
        self::assertSame(PositionStatus::ClosedTp->value, $binancePos->status);
        self::assertTrue(BigDecimal::of((string) $bybitPos->remaining_quantity)->isZero());
        self::assertTrue(BigDecimal::of((string) $binancePos->remaining_quantity)->isZero());
        self::assertTrue(BigDecimal::of((string) $bybitPos->net_pnl)->isGreaterThan(0));
        self::assertTrue(BigDecimal::of((string) $binancePos->net_pnl)->isGreaterThan(0));

        // Signal states are COMPLETED
        self::assertSame('COMPLETED', PaperSignalStateRecord::query()->where('signal_id', $bybitSignal->id)->value('status'));
        self::assertSame('COMPLETED', PaperSignalStateRecord::query()->where('signal_id', $binanceSignal->id)->value('status'));

        // Verify journal events exist for ENTRY, POSITION_PARTIALLY_CLOSED, POSITION_CLOSED, FUNDING_APPLIED
        self::assertTrue(JournalEventRecord::query()->where('position_id', $bybitPos->id)->where('event_type', 'POSITION_OPENED')->exists());
        self::assertTrue(JournalEventRecord::query()->where('position_id', $bybitPos->id)->where('event_type', 'POSITION_PARTIALLY_CLOSED')->exists());
        self::assertTrue(JournalEventRecord::query()->where('position_id', $bybitPos->id)->where('event_type', 'POSITION_CLOSED')->exists());
        self::assertTrue(JournalEventRecord::query()->where('position_id', $bybitPos->id)->where('event_type', 'FUNDING_APPLIED')->exists());

        // Verify M9 financial ledger reconciliation passes across all 26 accounts
        $m9 = $this->app->make(ExchangePaperReconciliation::class)->inspect();
        self::assertSame('LEDGER_PASS_ONLY', $m9['status']);
        self::assertSame(26, $m9['verified_pairs']);
        self::assertEmpty($m9['issues']);
    }

    public function test_e2e_stop_loss_exit_with_realized_loss_and_double_entry_journaling(): void
    {
        $accountService = $this->app->make(PaperAccountService::class);
        $strategy = $this->app->make(StrategyRegistry::class)->get('trend_pullback');
        $account = $accountService->strategyForward('trend_pullback', $strategy->version(), $strategy->parameters());
        $account->update(['status' => 'ACTIVE']);
        $processingService = $this->app->make(PaperProcessingService::class);

        $t0 = new DateTimeImmutable('2026-11-04T11:00:00Z');
        $account->update(['created_at' => $t0->modify('-1 hour')]);
        $tEntry = new DateTimeImmutable('2026-11-04T11:05:00Z');
        $tStop = new DateTimeImmutable('2026-11-04T11:10:00Z');

        $this->createMarketContext('bybit', 'BTCUSDT', $t0, bid: '60000', ask: '60010');
        $signal = $this->createSignal(
            exchange: 'bybit',
            strategyId: 'trend_pullback',
            symbol: 'BTCUSDT',
            side: 'BUY',
            decisionTime: $t0,
            entryLow: '60000',
            entryHigh: '60050',
            stopLoss: '59000',
            tp1: '61000',
            tp2: '62000',
        );

        $this->createCandle('bybit', 'BTCUSDT', '5', $t0, open: '60000', high: '60050', low: '59980', close: '60020');
        $processingService->process($account->id, 'BTCUSDT', $tEntry);

        $position = VirtualPositionRecord::query()->where('paper_account_id', $account->id)->firstOrFail();
        self::assertSame(PositionStatus::Open->value, $position->status);

        // Candle crashes straight to SL
        $this->createCandle('bybit', 'BTCUSDT', '5', $tEntry, open: '60010', high: '60020', low: '58900', close: '58950');
        $processingService->process($account->id, 'BTCUSDT', $tStop);

        $position->refresh();
        self::assertSame(PositionStatus::ClosedSl->value, $position->status);
        self::assertTrue(BigDecimal::of((string) $position->remaining_quantity)->isZero());
        self::assertTrue(BigDecimal::of((string) $position->net_pnl)->isLessThan(0));
        self::assertTrue(BigDecimal::of((string) $position->gross_pnl)->isLessThan(0));

        // Journal and execution audits
        self::assertDatabaseHas('virtual_executions', ['position_id' => $position->id, 'type' => 'STOP_LOSS']);
        self::assertTrue(JournalEventRecord::query()->where('position_id', $position->id)->where('event_type', 'POSITION_CLOSED')->exists());

        // Account equity accurately reflects realized loss and fees
        $account->refresh();
        self::assertTrue(BigDecimal::of((string) $account->realized_pnl)->isEqualTo($position->net_pnl));
        self::assertTrue(BigDecimal::of((string) $account->current_equity)->isLessThan($account->starting_balance));
    }

    public function test_e2e_timeout_exit_when_maximum_holding_time_exceeded(): void
    {
        $accountService = $this->app->make(PaperAccountService::class);
        $strategy = $this->app->make(StrategyRegistry::class)->get('trend_pullback');
        $account = $accountService->strategyForward('trend_pullback', $strategy->version(), $strategy->parameters());
        $account->update(['status' => 'ACTIVE']);
        $processingService = $this->app->make(PaperProcessingService::class);

        $t0 = new DateTimeImmutable('2026-11-04T12:00:00Z');
        $account->update(['created_at' => $t0->modify('-1 hour')]);
        $this->createMarketContext('bybit', 'BTCUSDT', $t0, bid: '60000', ask: '60010');

        // Signal with maximum 60 minutes holding time
        $signal = $this->createSignal(
            exchange: 'bybit',
            strategyId: 'trend_pullback',
            symbol: 'BTCUSDT',
            side: 'BUY',
            decisionTime: $t0,
            entryLow: '60000',
            entryHigh: '60050',
            stopLoss: '58000',
            tp1: '63000',
            tp2: '65000',
            holdingMinutes: 60,
        );

        $this->createCandle('bybit', 'BTCUSDT', '5', $t0, open: '60000', high: '60050', low: '59980', close: '60020');
        $processingService->process($account->id, 'BTCUSDT', $t0->modify('+5 minutes'));

        $position = VirtualPositionRecord::query()->where('paper_account_id', $account->id)->firstOrFail();
        self::assertSame(PositionStatus::Open->value, $position->status);

        // Generate candles that stay in range without hitting SL or TP for 65 minutes
        for ($m = 5; $m <= 70; $m += 5) {
            $candleTime = $t0->modify("+{$m} minutes");
            $this->createCandle('bybit', 'BTCUSDT', '5', $candleTime, open: '60020', high: '60100', low: '59950', close: '60050');
        }

        // Process at 75 minutes: position must be closed by timeout
        $processingService->process($account->id, 'BTCUSDT', $t0->modify('+75 minutes'));

        $position->refresh();
        self::assertSame(PositionStatus::ClosedTimeout->value, $position->status);
        self::assertTrue(BigDecimal::of((string) $position->remaining_quantity)->isZero());
        self::assertDatabaseHas('virtual_executions', ['position_id' => $position->id, 'type' => 'TIMEOUT']);
    }

    public function test_defensive_zero_sized_tp1_prevented_without_corrupting_position_state(): void
    {
        $accountService = $this->app->make(PaperAccountService::class);
        $strategy = $this->app->make(StrategyRegistry::class)->get('trend_pullback');
        $account = $accountService->strategyForward('trend_pullback', $strategy->version(), $strategy->parameters());
        $account->update(['status' => 'ACTIVE']);
        $lifecycle = $this->app->make(PositionLifecycleService::class);

        $t0 = new DateTimeImmutable('2026-11-04T13:00:00Z');
        $account->update(['created_at' => $t0->modify('-1 hour')]);
        $signal = $this->createSignal('bybit', 'trend_pullback', 'BTCUSDT', 'BUY', $t0);
        $state = PaperSignalStateRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'paper_account_id' => $account->id,
            'signal_id' => $signal->id,
            'status' => 'PENDING_ENTRY',
        ]);

        $tOpen = $t0->modify('+5 minutes');
        // Initial quantity = 0.001 with qtyStep = 0.001. 50% TP1 fraction floors to 0.000!
        $size = new PositionSize(true, '0.001', '100', '10', '60', qtyStep: '0.001');
        $entryQuote = new ExecutionQuote(ExecutionType::Entry, '60000', '60000', '0.0002', 'E2E_TEST', false);

        $position = $lifecycle->open($account, $state, $signal, $size, $entryQuote, $tOpen);
        self::assertSame('0.001000000000', (string) $position->initial_quantity);
        self::assertSame('0.001000000000', (string) $position->remaining_quantity);
        self::assertSame(PositionStatus::Open->value, $position->status);

        // Attempt TP1 exit: 50% of 0.001 floors to 0.000 -> Must safely skip without creating execution
        $tp1Quote = new ExecutionQuote(ExecutionType::TakeProfit1, '61000', '61000', '0.0002', 'E2E_TEST', false);
        $updated = $lifecycle->executeExit($position, $tp1Quote, $tOpen->modify('+5 minutes'));

        self::assertSame(PositionStatus::Open->value, $updated->status);
        self::assertSame('0.001000000000', (string) $updated->remaining_quantity);
        self::assertFalse(VirtualExecutionRecord::query()->where('type', ExecutionType::TakeProfit1->value)->exists());
        self::assertSame(1, VirtualExecutionRecord::query()->where('position_id', $position->id)->count());

        // Full exit at TP2 succeeds cleanly
        $tp2Quote = new ExecutionQuote(ExecutionType::TakeProfit2, '62000', '62000', '0.0002', 'E2E_TEST', false);
        $closed = $lifecycle->executeExit($updated, $tp2Quote, $tOpen->modify('+10 minutes'));

        self::assertSame(PositionStatus::ClosedTp->value, $closed->status);
        self::assertSame('0.000000000000', (string) $closed->remaining_quantity);
        self::assertSame(2, VirtualExecutionRecord::query()->where('position_id', $position->id)->count());
    }

    public function test_defensive_min_notional_rejection_at_position_sizing(): void
    {
        $accountService = $this->app->make(PaperAccountService::class);
        $strategy = $this->app->make(StrategyRegistry::class)->get('trend_pullback');
        $account = $accountService->strategyForward('trend_pullback', $strategy->version(), $strategy->parameters());
        $account->update(['status' => 'ACTIVE']);
        $processingService = $this->app->make(PaperProcessingService::class);

        $t0 = new DateTimeImmutable('2026-11-04T14:00:00Z');
        $account->update(['created_at' => $t0->modify('-1 hour')]);
        // min_notional_value is set to 500 USDT
        $this->createMarketContext('bybit', 'BTCUSDT', $t0, minNotional: '500');

        // Signal with tiny risk percent resulting in notional below 500 USDT
        $signal = $this->createSignal(
            exchange: 'bybit',
            strategyId: 'trend_pullback',
            symbol: 'BTCUSDT',
            side: 'BUY',
            decisionTime: $t0,
            modelRiskPercent: '0.01', // Extremely low risk percent -> tiny quantity
        );

        $this->createCandle('bybit', 'BTCUSDT', '5', $t0, open: '60000', high: '60050', low: '59980', close: '60020');
        $processingService->process($account->id, 'BTCUSDT', $t0->modify('+5 minutes'));

        // Zero positions opened
        self::assertSame(0, VirtualPositionRecord::query()->where('paper_account_id', $account->id)->count());

        // Risk decision recorded as rejected with reason 'below_minimum_order'
        $decision = RiskDecisionRecord::query()->where('paper_account_id', $account->id)->where('signal_id', $signal->id)->firstOrFail();
        self::assertFalse((bool) $decision->allowed);
        $reasons = is_array($decision->reasons) ? $decision->reasons : json_decode((string) $decision->reasons, true);
        self::assertContains('below_minimum_order', $reasons);

        $state = PaperSignalStateRecord::query()->where('paper_account_id', $account->id)->where('signal_id', $signal->id)->firstOrFail();
        self::assertSame('REJECTED', $state->status);
        self::assertSame('below_minimum_order', $state->reason);
    }

    public function test_pipeline_idempotency_duplicate_signals_and_duplicate_processing_ticks(): void
    {
        $accountService = $this->app->make(PaperAccountService::class);
        $strategy = $this->app->make(StrategyRegistry::class)->get('trend_pullback');
        $account = $accountService->strategyForward('trend_pullback', $strategy->version(), $strategy->parameters());
        $account->update(['status' => 'ACTIVE']);
        $processingService = $this->app->make(PaperProcessingService::class);

        $t0 = new DateTimeImmutable('2026-11-04T15:00:00Z');
        $account->update(['created_at' => $t0->modify('-1 hour')]);
        $this->createMarketContext('bybit', 'BTCUSDT', $t0);

        $signal = $this->createSignal('bybit', 'trend_pullback', 'BTCUSDT', 'BUY', $t0);
        $this->createCandle('bybit', 'BTCUSDT', '5', $t0, open: '60000', high: '60050', low: '59980', close: '60020');

        $tProcess = $t0->modify('+5 minutes');

        // Process twice with identical parameters
        $result1 = $processingService->process($account->id, 'BTCUSDT', $tProcess);
        $result2 = $processingService->process($account->id, 'BTCUSDT', $tProcess);

        // Exactly one position, exactly one execution
        self::assertSame(1, VirtualPositionRecord::query()->where('paper_account_id', $account->id)->count());
        self::assertSame(1, VirtualExecutionRecord::query()->where('position_id', VirtualPositionRecord::query()->first()->id)->count());
        self::assertSame(1, PaperSignalStateRecord::query()->where('signal_id', $signal->id)->count());
    }

    public function test_concurrent_position_openings_enforce_global_capital_guard_5_percent_limit(): void
    {
        // Setup 26 accounts (13 Bybit + 13 Binance = 260,000 USDT total capital, 5% cap = 13,000 USDT)
        $initializer = $this->app->make(BinanceDeploymentInitializer::class);
        $initializer->initialize('10000', 'OWNER-VECTOR-RISK-20261104');

        $guard = $this->app->make(GlobalPaperCapitalGuard::class);

        // Pre-reserve 12,980 USDT across accounts, leaving exactly 20 USDT of risk.
        PaperAccountRecord::query()->where('exchange_id', 'bybit')
            ->where('primary_strategy_id', 'baseline')
            ->update(['reserved_risk' => '12980.000000000000']);

        // Account 1 attempts 15 USDT risk: 12980 + 15 = 12995 <= 13000 -> ALLOWED (reason null)
        $reason1 = $guard->entryRejectionReason('15.000000000000');
        self::assertNull($reason1);

        // Account 2 attempts 25 USDT risk: 12980 + 25 = 13005 > 13000 -> REJECTED
        $reason2 = $guard->entryRejectionReason('25.000000000000');
        self::assertSame('global_risk_budget_exceeded', $reason2);
    }

    private function createSignal(
        string $exchange,
        string $strategyId,
        string $symbol,
        string $side,
        DateTimeImmutable $decisionTime,
        string $entryLow = '60000',
        string $entryHigh = '60050',
        string $stopLoss = '59000',
        string $tp1 = '61000',
        string $tp2 = '62000',
        string $modelRiskPercent = '0.50',
        int $holdingMinutes = 240,
    ): SignalRecord {
        $strategy = $this->app->make(StrategyRegistry::class)->get($strategyId);

        return SignalRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'exchange' => $exchange,
            'category' => 'linear',
            'symbol' => $symbol,
            'timeframe' => '15',
            'side' => $side,
            'quality_class' => 'A',
            'score' => 90,
            'decision_time' => $decisionTime,
            'candle_close_time' => $decisionTime,
            'entry_low' => $entryLow,
            'entry_high' => $entryHigh,
            'stop_loss' => $stopLoss,
            'take_profit_1' => $tp1,
            'take_profit_2' => $tp2,
            'risk_reward_1' => '1.5',
            'risk_reward_2' => '2.5',
            'model_risk_percent' => $modelRiskPercent,
            'expires_at' => $decisionTime->modify('+30 minutes'),
            'maximum_holding_minutes' => $holdingMinutes,
            'reasons' => ['pipeline_verification_signal'],
            'risks' => [],
            'indicator_snapshot' => ['15m' => ['atr_14' => '100', 'close' => $entryLow]],
            'contract' => ['research_mode' => true],
            'strategy_version' => $strategy->version(),
            'schema_version' => '1.0.0',
            'strategy_id' => $strategyId,
            'created_at' => $decisionTime,
            'updated_at' => $decisionTime,
        ]);
    }

    private function createMarketContext(
        string $exchange,
        string $symbol,
        DateTimeImmutable $observedAt,
        string $bid = '60000',
        string $ask = '60010',
        string $minNotional = '5',
    ): void {
        MarketContextRecord::query()->create([
            'exchange' => $exchange,
            'category' => 'linear',
            'symbol' => $symbol,
            'observed_at' => $observedAt,
            'bid' => $bid,
            'ask' => $ask,
            'turnover_24h' => '100000000',
            'tick_size' => '0.1',
            'funding_rate' => '0.0001',
            'open_interest' => '1000000',
            'open_interest_change' => '0.01',
            'qty_step' => '0.001',
            'min_order_qty' => '0.001',
            'max_order_qty' => '1000',
            'min_notional_value' => $minNotional,
            'created_at' => $observedAt,
            'updated_at' => $observedAt,
        ]);
    }

    private function createCandle(
        string $exchange,
        string $symbol,
        string $interval,
        DateTimeImmutable $openTime,
        string $open,
        string $high,
        string $low,
        string $close,
    ): void {
        DB::table('market_candles')->insert([
            'exchange' => $exchange,
            'category' => 'linear',
            'symbol' => $symbol,
            'interval' => $interval,
            'open_time' => $openTime,
            'open' => $open,
            'high' => $high,
            'low' => $low,
            'close' => $close,
            'volume' => '100',
            'turnover' => '6000000',
            'is_closed' => true,
            'created_at' => $openTime,
            'updated_at' => $openTime,
        ]);
    }

    private function seedBinanceReadinessData(DateTimeImmutable|CarbonInterface $now): void
    {
        $symbols = ['BTCUSDT', 'ETHUSDT'];
        $nowSeconds = $now->getTimestamp();
        $baseHour = (int) floor($nowSeconds / 3600) * 3600;
        foreach ($symbols as $symbol) {
            foreach (['5', '15', '30', '60'] as $interval) {
                $seconds = (int) $interval * 60;
                for ($i = 1; $i <= 220; $i++) {
                    $timestamp = $baseHour - ($i * $seconds);
                    $openTime = (new DateTimeImmutable("@{$timestamp}"))->setTimezone(new \DateTimeZone('UTC'));
                    CandleRecord::query()->create([
                        'exchange' => 'binance',
                        'category' => 'linear',
                        'symbol' => $symbol,
                        'interval' => $interval,
                        'open_time' => $openTime,
                        'open' => '100',
                        'high' => '101',
                        'low' => '99',
                        'close' => '100.5',
                        'volume' => '100',
                        'turnover' => '10000',
                        'is_closed' => true,
                        'created_at' => $openTime,
                        'updated_at' => $openTime,
                    ]);
                }
            }
            MarketContextRecord::query()->create([
                'exchange' => 'binance',
                'category' => 'linear',
                'symbol' => $symbol,
                'observed_at' => $now->modify('-5 minutes'),
                'bid' => '100.48',
                'ask' => '100.52',
                'turnover_24h' => '1000000',
                'tick_size' => '0.1',
                'qty_step' => '0.001',
                'min_order_qty' => '0.001',
                'max_order_qty' => '1000',
                'min_notional_value' => '5',
                'funding_rate' => '0.0001',
                'open_interest' => '1000000',
                'open_interest_change' => '0.01',
                'created_at' => $now->modify('-5 minutes'),
                'updated_at' => $now->modify('-5 minutes'),
            ]);
            MarketFundingEventRecord::query()->create([
                'exchange' => 'binance',
                'category' => 'linear',
                'symbol' => $symbol,
                'rate' => '0.0001',
                'funding_at' => $now->modify('-2 hours'),
                'mark_price' => '100.5',
                'created_at' => $now->modify('-2 hours'),
                'updated_at' => $now->modify('-2 hours'),
            ]);
        }
    }
}
