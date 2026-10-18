<?php

declare(strict_types=1);

namespace Tests\Unit\Research;

use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Infrastructure\Persistence\MarketFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\PositionFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Research\Application\LedgerReconciliationService;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LedgerReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconciliation_passes_on_clean_account(): void
    {
        $accountService = $this->app->make(PaperAccountService::class);
        $account = $accountService->default();

        $service = $this->app->make(LedgerReconciliationService::class);
        $result = $service->reconcileAccount($account->id);

        self::assertSame('PASSED', $result['status']);
        self::assertSame(0, $result['discrepancy_count']);
        self::assertTrue(BigDecimal::of($result['starting_balance'])->isEqualTo('10000'));
        self::assertTrue(BigDecimal::of($result['recalculated_balance'])->isEqualTo('10000'));
        self::assertTrue(BigDecimal::of($result['recalculated_equity'])->isEqualTo('10000'));
    }

    public function test_reconciliation_detects_injected_discrepancies(): void
    {
        $accountService = $this->app->make(PaperAccountService::class);
        $account = $accountService->default();

        $signalId = (string) Str::uuid();
        SignalRecord::query()->create([
            'id' => $signalId,
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 80,
            'decision_time' => now('UTC'),
            'candle_close_time' => now('UTC'),
            'expires_at' => now('UTC')->addHour(),
            'maximum_holding_minutes' => 240,
            'reasons' => ['test'],
            'risks' => [],
            'indicator_snapshot' => [],
            'contract' => [],
            'strategy_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);

        // Inject overclosed position
        VirtualPositionRecord::query()->create([
            'id' => (string) Str::uuid(),
            'paper_account_id' => $account->id,
            'signal_id' => $signalId,
            'symbol' => 'BTCUSDT',
            'side' => 'BUY',
            'quality_class' => 'A',
            'status' => 'OPEN',
            'entry_price' => '65000.00',
            'stop_loss' => '64000.00',
            'take_profit_1' => '66000.00',
            'take_profit_2' => '67000.00',
            'initial_quantity' => '0.1',
            'remaining_quantity' => '-0.05', // Negative quantity = overclosed!
            'qty_step' => '0.001',
            'risk_capital' => '100.00',
            'unrealized_pnl' => '10.00',
            'gross_pnl' => '0.00',
            'net_pnl' => '0.00',
            'fees' => '0.00',
            'funding' => '0.00',
            'opened_at' => now('UTC'),
            'maximum_holding_until' => now('UTC')->addHours(4),
            'execution_model' => 'FORWARD_PAPER',
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
            'strategy_version' => '1.0.0',
            'risk_version' => '1.0.0',
        ]);

        $service = $this->app->make(LedgerReconciliationService::class);
        $result = $service->reconcileAccount($account->id);

        self::assertSame('DISCREPANCY_DETECTED', $result['status']);
        self::assertGreaterThanOrEqual(1, $result['discrepancy_count']);
        $codes = collect($result['discrepancies'])->pluck('code')->all();
        self::assertContains('OVERCLOSED_POSITION', $codes);
    }

    public function test_reconciliation_verifies_executions_with_partial_and_full_exits_and_funding(): void
    {
        $accountService = $this->app->make(PaperAccountService::class);
        $account = $accountService->default();

        $signalId = (string) Str::uuid();
        SignalRecord::query()->create([
            'id' => $signalId,
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 80,
            'decision_time' => now('UTC'),
            'candle_close_time' => now('UTC'),
            'expires_at' => now('UTC')->addHour(),
            'maximum_holding_minutes' => 240,
            'reasons' => ['test'],
            'risks' => [],
            'indicator_snapshot' => [],
            'contract' => [],
            'strategy_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);

        $positionId = (string) Str::uuid();
        VirtualPositionRecord::query()->create([
            'id' => $positionId,
            'paper_account_id' => $account->id,
            'signal_id' => $signalId,
            'symbol' => 'BTCUSDT',
            'side' => 'BUY',
            'quality_class' => 'A',
            'status' => 'CLOSED_TP',
            'entry_price' => '60000.00',
            'stop_loss' => '59000.00',
            'take_profit_1' => '62000.00',
            'take_profit_2' => '63000.00',
            'initial_quantity' => '0.1',
            'remaining_quantity' => '0.0',
            'qty_step' => '0.001',
            'risk_capital' => '100.00',
            'unrealized_pnl' => '0.00',
            'gross_pnl' => '250.00',
            'fees' => '4.80',
            'funding' => '2.50',
            'net_pnl' => '247.70',
            'opened_at' => now('UTC')->subHours(2),
            'maximum_holding_until' => now('UTC')->addHours(2),
            'closed_at' => now('UTC')->subHour(),
            'execution_model' => 'FORWARD_PAPER',
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
            'strategy_version' => '1.0.0',
            'risk_version' => '1.0.0',
        ]);

        // Entry execution: gross = 0, commission = 1.50
        VirtualExecutionRecord::query()->create([
            'id' => (string) Str::uuid(),
            'position_id' => $positionId,
            'type' => 'ENTRY',
            'side' => 'BUY',
            'quantity' => '0.10',
            'price' => '60000.00',
            'gross_pnl' => '0.00',
            'commission' => '1.50',
            'slippage' => '0.00',
            'market_event_at' => now('UTC')->subHours(2),
            'registered_at' => now('UTC')->subHours(2),
            'price_source' => 'NEXT_CANDLE_OPEN',
            'ambiguous_execution' => false,
            'idempotency_key' => 'exec:entry:'.$positionId,
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);

        // TP1 execution: gross = 100.00, commission = 1.60
        VirtualExecutionRecord::query()->create([
            'id' => (string) Str::uuid(),
            'position_id' => $positionId,
            'type' => 'TAKE_PROFIT_1',
            'side' => 'SELL',
            'quantity' => '0.05',
            'price' => '62000.00',
            'gross_pnl' => '100.00',
            'commission' => '1.60',
            'slippage' => '0.00',
            'market_event_at' => now('UTC')->subMinutes(90),
            'registered_at' => now('UTC')->subMinutes(90),
            'price_source' => 'LIMIT_ORDER',
            'ambiguous_execution' => false,
            'idempotency_key' => 'exec:tp1:'.$positionId,
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);

        // TP2 execution: gross = 150.00, commission = 1.70
        VirtualExecutionRecord::query()->create([
            'id' => (string) Str::uuid(),
            'position_id' => $positionId,
            'type' => 'TAKE_PROFIT_2',
            'side' => 'SELL',
            'quantity' => '0.05',
            'price' => '63000.00',
            'gross_pnl' => '150.00',
            'commission' => '1.70',
            'slippage' => '0.00',
            'market_event_at' => now('UTC')->subHour(),
            'registered_at' => now('UTC')->subHour(),
            'price_source' => 'LIMIT_ORDER',
            'ambiguous_execution' => false,
            'idempotency_key' => 'exec:tp2:'.$positionId,
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);

        // Funding event: 2.50
        $mktFunding = MarketFundingEventRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'funding_at' => now('UTC')->subMinutes(75),
            'rate' => '0.0001',
            'mark_price' => '62500.00',
        ]);
        PositionFundingEventRecord::query()->create([
            'id' => (string) Str::uuid(),
            'position_id' => $positionId,
            'market_funding_event_id' => $mktFunding->id,
            'quantity' => '0.05',
            'mark_price' => '62500.00',
            'rate' => '0.0001',
            'amount' => '2.50',
            'funding_at' => now('UTC')->subMinutes(75),
        ]);

        // Update account to reflect realized trades & funding: 250 - 4.80 + 2.50 = 247.70
        $account->update([
            'realized_pnl' => '247.70',
            'current_equity' => '10247.70',
            'peak_equity' => '10247.70',
        ]);

        $service = $this->app->make(LedgerReconciliationService::class);
        $result = $service->reconcileAccount($account->id);

        self::assertSame('PASSED', $result['status']);
        self::assertSame(0, $result['discrepancy_count']);
        self::assertTrue(BigDecimal::of($result['recalculated_balance'])->isEqualTo('10247.70'));
        self::assertTrue(BigDecimal::of($result['stored_balance'])->isEqualTo('10247.70'));
        self::assertTrue(BigDecimal::of($result['recalculated_equity'])->isEqualTo('10247.70'));
        self::assertTrue(BigDecimal::of($result['stored_equity'])->isEqualTo('10247.70'));
    }
}
