<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Domain\ExecutionType;
use App\PaperTrading\Domain\PositionStatus;
use App\PaperTrading\Infrastructure\Persistence\EquitySnapshotRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Research\Application\LedgerReconciliationService;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class ProductionDrawdownReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_bybit_baseline_production_case_with_legacy_unverified_snapshot_preserves_blocked_status_and_reconciles_without_false_discrepancy(): void
    {
        $accountService = app(PaperAccountService::class);
        $account = $accountService->primaryAccount('baseline', 'bybit');

        $now = CarbonImmutable::parse('2026-10-15 16:00:00', 'UTC');

        // Production evidence values:
        // Baseline account id: 94b97f13-107d-54a9-b4eb-1ca5a9c79743
        // Stored peak: 10000, Stored max drawdown: 5.38498549%, Current equity: 9461.501450960003, Realized: -538.49854904
        $account->update([
            'starting_balance' => '10000',
            'peak_equity' => '10000',
            'current_equity' => '9461.501450960003',
            'realized_pnl' => '-538.498549039997',
            'unrealized_pnl' => '0',
            'maximum_drawdown_percent' => '5.38498549',
            'current_drawdown_percent' => '5.38498549',
            'status' => 'BLOCKED',
        ]);

        EquitySnapshotRecord::query()->where('paper_account_id', $account->id)->delete();

        // Matching closed position and executions for realized PnL reconciliation
        $signalId = (string) Uuid::uuid4();
        SignalRecord::query()->create([
            'id' => $signalId, 'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT',
            'strategy_id' => 'baseline', 'strategy_version' => '1.0.0', 'timeframe' => '15', 'side' => 'BUY',
            'quality_class' => 'A', 'score' => 85, 'decision_time' => $now->subHours(3),
            'candle_close_time' => $now->subHours(3), 'expires_at' => $now->addHours(1),
            'maximum_holding_minutes' => 240, 'reasons' => ['production_baseline'], 'risks' => [],
            'indicator_snapshot' => [], 'contract' => [], 'schema_version' => '1.0.0',
        ]);

        $positionId = (string) Uuid::uuid4();
        $position = VirtualPositionRecord::query()->create([
            'id' => $positionId, 'paper_account_id' => $account->id, 'signal_id' => $signalId,
            'symbol' => 'BTCUSDT', 'side' => 'BUY', 'quality_class' => 'A',
            'status' => PositionStatus::ClosedSl->value, 'entry_price' => '60000.00', 'stop_loss' => '56780.00',
            'take_profit_1' => '63000.00', 'take_profit_2' => '65000.00', 'initial_quantity' => '0.10',
            'remaining_quantity' => '0.00', 'qty_step' => '0.001', 'risk_capital' => '100.00',
            'gross_pnl' => '-536.338507439997', 'fees' => '2.1600416', 'funding' => '0',
            'net_pnl' => '-538.498549039997', 'unrealized_pnl' => '0.00', 'r_multiple' => '-1.00',
            'opened_at' => $now->subHours(3), 'maximum_holding_until' => $now->addHours(1),
            'closed_at' => $now, 'last_processed_event_at' => $now, 'execution_model' => 'NEXT_CANDLE_OPEN',
            'strategy_version' => '1.0.0', 'risk_version' => '1.0.0', 'execution_version' => '1.0.0', 'schema_version' => '1.0.0',
            'funding_complete' => true,
        ]);

        VirtualExecutionRecord::query()->create([
            'id' => (string) Uuid::uuid4(), 'position_id' => $position->id, 'type' => ExecutionType::Entry->value,
            'side' => 'BUY', 'quantity' => '0.10', 'price' => '60000.00', 'gross_pnl' => '0.00',
            'commission' => '2.1600416', 'slippage' => '0.00', 'market_event_at' => $now->subHours(3),
            'registered_at' => $now->subHours(3), 'price_source' => 'NEXT_CANDLE_OPEN', 'ambiguous_execution' => false,
            'idempotency_key' => 'entry:'.$position->id, 'execution_version' => '1.0.0', 'schema_version' => '1.0.0',
        ]);

        VirtualExecutionRecord::query()->create([
            'id' => (string) Uuid::uuid4(), 'position_id' => $position->id, 'type' => ExecutionType::StopLoss->value,
            'side' => 'SELL', 'quantity' => '0.10', 'price' => '54636.61', 'gross_pnl' => '-536.338507439997',
            'commission' => '0.00', 'slippage' => '0.00', 'market_event_at' => $now,
            'registered_at' => $now, 'price_source' => 'TRIGGER_FILL', 'ambiguous_execution' => false,
            'idempotency_key' => 'exit:'.$position->id, 'execution_version' => '1.0.0', 'schema_version' => '1.0.0',
        ]);

        // Snapshot 1: Opening capital
        EquitySnapshotRecord::query()->create([
            'paper_account_id' => $account->id,
            'exchange_id' => 'bybit',
            'recorded_at' => CarbonImmutable::parse('2026-10-14 00:00:00', 'UTC'),
            'equity' => '10000',
            'realized_pnl' => '0',
            'unrealized_pnl' => '0',
            'drawdown_percent' => '0',
            'source_event_key' => 'account:initial',
            'valuation_fresh' => true,
        ]);

        // Snapshot 2: Historical peak with unverified floating unrealized PnL (pre-provenance migration)
        EquitySnapshotRecord::query()->create([
            'paper_account_id' => $account->id,
            'exchange_id' => 'bybit',
            'recorded_at' => CarbonImmutable::parse('2026-10-15 13:55:00', 'UTC'),
            'equity' => '10012.670631594740',
            'realized_pnl' => '-2.1600416',
            'unrealized_pnl' => '14.8306732',
            'drawdown_percent' => '0',
            'source_event_key' => 'checkpoint:2026-10-15T13:55:00Z',
            'valued_through' => null,
            'valuation_fresh' => null, // LEGACY_UNKNOWN
        ]);

        // Snapshot 3: Exit trough when closed at loss
        EquitySnapshotRecord::query()->create([
            'paper_account_id' => $account->id,
            'exchange_id' => 'bybit',
            'recorded_at' => CarbonImmutable::parse('2026-10-15 16:00:00', 'UTC'),
            'equity' => '9461.501450960003',
            'realized_pnl' => '-538.498549039997',
            'unrealized_pnl' => '0',
            'drawdown_percent' => '5.38498549',
            'source_event_key' => 'checkpoint:2026-10-15T16:00:00Z',
            'valued_through' => CarbonImmutable::parse('2026-10-15 16:00:00', 'UTC'),
            'valuation_fresh' => true,
        ]);

        $reconciliation = app(LedgerReconciliationService::class);
        $result = $reconciliation->reconcileAccount($account->id);

        // Verification:
        // 1. Stored maximum drawdown and recalculated drawdown match exactly
        self::assertDecimalSame('5.38498549', $result['recalculated_drawdown_percent']);
        self::assertDecimalSame('5.38498549', $result['stored_drawdown_percent']);

        // 2. No false HISTORICAL_DRAWDOWN_UNDERSTATED discrepancy
        $discrepancyCodes = array_column($result['discrepancies'], 'code');
        self::assertNotContains('HISTORICAL_DRAWDOWN_UNDERSTATED', $discrepancyCodes);
        self::assertSame(0, $result['discrepancy_count']);

        // 3. Status remains strictly BLOCKED
        self::assertSame('BLOCKED', $account->fresh()->status);

        // 4. Audit provenance tracks the presence of 1 legacy unverified peak
        self::assertSame(1, $result['findings']['legacy_unverified_peaks_count']);
    }

    public function test_bybit_momentum_production_case_with_legacy_unverified_snapshot_preserves_blocked_status_and_reconciles_without_false_discrepancy(): void
    {
        $accountService = app(PaperAccountService::class);
        $account = $accountService->primaryAccount('momentum', 'bybit');

        $now = CarbonImmutable::parse('2026-10-17 20:00:00', 'UTC');

        // Production evidence values:
        // Momentum account id: 1b36e922-59b6-50a6-8209-cd0391dce128
        // Stored peak: 10000, Stored max drawdown: 5.02839241%, Current equity: 9497.160758949486, Realized: -502.83924105
        $account->update([
            'starting_balance' => '10000',
            'peak_equity' => '10000',
            'current_equity' => '9497.160758949486',
            'realized_pnl' => '-502.839241050514',
            'unrealized_pnl' => '0',
            'maximum_drawdown_percent' => '5.02839241',
            'current_drawdown_percent' => '5.02839241',
            'status' => 'BLOCKED',
        ]);

        EquitySnapshotRecord::query()->where('paper_account_id', $account->id)->delete();

        // Matching closed position and executions for realized PnL reconciliation
        $signalId = (string) Uuid::uuid4();
        SignalRecord::query()->create([
            'id' => $signalId, 'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT',
            'strategy_id' => 'momentum', 'strategy_version' => '1.0.0', 'timeframe' => '15', 'side' => 'BUY',
            'quality_class' => 'A', 'score' => 88, 'decision_time' => $now->subHours(3),
            'candle_close_time' => $now->subHours(3), 'expires_at' => $now->addHours(1),
            'maximum_holding_minutes' => 240, 'reasons' => ['production_momentum'], 'risks' => [],
            'indicator_snapshot' => [], 'contract' => [], 'schema_version' => '1.0.0',
        ]);

        $positionId = (string) Uuid::uuid4();
        $position = VirtualPositionRecord::query()->create([
            'id' => $positionId, 'paper_account_id' => $account->id, 'signal_id' => $signalId,
            'symbol' => 'BTCUSDT', 'side' => 'BUY', 'quality_class' => 'A',
            'status' => PositionStatus::ClosedSl->value, 'entry_price' => '60000.00', 'stop_loss' => '55000.00',
            'take_profit_1' => '64000.00', 'take_profit_2' => '66000.00', 'initial_quantity' => '0.10',
            'remaining_quantity' => '0.00', 'qty_step' => '0.001', 'risk_capital' => '100.00',
            'gross_pnl' => '-479.626006780514', 'fees' => '23.21323427', 'funding' => '0',
            'net_pnl' => '-502.839241050514', 'unrealized_pnl' => '0.00', 'r_multiple' => '-1.00',
            'opened_at' => $now->subHours(3), 'maximum_holding_until' => $now->addHours(1),
            'closed_at' => $now, 'last_processed_event_at' => $now, 'execution_model' => 'NEXT_CANDLE_OPEN',
            'strategy_version' => '1.0.0', 'risk_version' => '1.0.0', 'execution_version' => '1.0.0', 'schema_version' => '1.0.0',
            'funding_complete' => true,
        ]);

        VirtualExecutionRecord::query()->create([
            'id' => (string) Uuid::uuid4(), 'position_id' => $position->id, 'type' => ExecutionType::Entry->value,
            'side' => 'BUY', 'quantity' => '0.10', 'price' => '60000.00', 'gross_pnl' => '0.00',
            'commission' => '23.21323427', 'slippage' => '0.00', 'market_event_at' => $now->subHours(3),
            'registered_at' => $now->subHours(3), 'price_source' => 'NEXT_CANDLE_OPEN', 'ambiguous_execution' => false,
            'idempotency_key' => 'entry:'.$position->id, 'execution_version' => '1.0.0', 'schema_version' => '1.0.0',
        ]);

        VirtualExecutionRecord::query()->create([
            'id' => (string) Uuid::uuid4(), 'position_id' => $position->id, 'type' => ExecutionType::StopLoss->value,
            'side' => 'SELL', 'quantity' => '0.10', 'price' => '55203.74', 'gross_pnl' => '-479.626006780514',
            'commission' => '0.00', 'slippage' => '0.00', 'market_event_at' => $now,
            'registered_at' => $now, 'price_source' => 'TRIGGER_FILL', 'ambiguous_execution' => false,
            'idempotency_key' => 'exit:'.$position->id, 'execution_version' => '1.0.0', 'schema_version' => '1.0.0',
        ]);

        // Snapshot 1: Opening capital
        EquitySnapshotRecord::query()->create([
            'paper_account_id' => $account->id,
            'exchange_id' => 'bybit',
            'recorded_at' => CarbonImmutable::parse('2026-10-14 00:00:00', 'UTC'),
            'equity' => '10000',
            'realized_pnl' => '0',
            'unrealized_pnl' => '0',
            'drawdown_percent' => '0',
            'source_event_key' => 'account:initial',
            'valuation_fresh' => true,
        ]);

        // Snapshot 2: Historical peak with unverified floating unrealized PnL (pre-provenance migration)
        EquitySnapshotRecord::query()->create([
            'paper_account_id' => $account->id,
            'exchange_id' => 'bybit',
            'recorded_at' => CarbonImmutable::parse('2026-10-17 17:11:00', 'UTC'),
            'equity' => '10007.827966395667',
            'realized_pnl' => '-23.21323427',
            'unrealized_pnl' => '31.04120066',
            'drawdown_percent' => '0',
            'source_event_key' => 'checkpoint:2026-10-17T17:11:00Z',
            'valued_through' => null,
            'valuation_fresh' => null, // LEGACY_UNKNOWN
        ]);

        // Snapshot 3: Exit trough when closed at loss
        EquitySnapshotRecord::query()->create([
            'paper_account_id' => $account->id,
            'exchange_id' => 'bybit',
            'recorded_at' => CarbonImmutable::parse('2026-10-17 20:00:00', 'UTC'),
            'equity' => '9497.160758949486',
            'realized_pnl' => '-502.839241050514',
            'unrealized_pnl' => '0',
            'drawdown_percent' => '5.02839241',
            'source_event_key' => 'checkpoint:2026-10-17T20:00:00Z',
            'valued_through' => CarbonImmutable::parse('2026-10-17 20:00:00', 'UTC'),
            'valuation_fresh' => true,
        ]);

        $reconciliation = app(LedgerReconciliationService::class);
        $result = $reconciliation->reconcileAccount($account->id);

        // Verification:
        // 1. Stored maximum drawdown and recalculated drawdown match exactly
        self::assertDecimalSame('5.02839241', $result['recalculated_drawdown_percent']);
        self::assertDecimalSame('5.02839241', $result['stored_drawdown_percent']);

        // 2. No false HISTORICAL_DRAWDOWN_UNDERSTATED discrepancy
        $discrepancyCodes = array_column($result['discrepancies'], 'code');
        self::assertNotContains('HISTORICAL_DRAWDOWN_UNDERSTATED', $discrepancyCodes);
        self::assertSame(0, $result['discrepancy_count']);

        // 3. Status remains strictly BLOCKED
        self::assertSame('BLOCKED', $account->fresh()->status);

        // 4. Audit provenance tracks the presence of 1 legacy unverified peak
        self::assertSame(1, $result['findings']['legacy_unverified_peaks_count']);
    }

    public function test_verified_fresh_snapshot_peak_correctly_triggers_understated_drawdown_discrepancy(): void
    {
        $accountService = app(PaperAccountService::class);
        $account = $accountService->primaryAccount('baseline', 'bybit');

        $now = CarbonImmutable::parse('2026-10-15 16:00:00', 'UTC');

        $account->update([
            'starting_balance' => '10000',
            'peak_equity' => '10000', // Outdated stored peak
            'current_equity' => '9000',
            'realized_pnl' => '-1000',
            'unrealized_pnl' => '0',
            'maximum_drawdown_percent' => '10.00000000', // (10000 - 9000)/10000 = 10%
            'current_drawdown_percent' => '10.00000000',
            'status' => 'HALTED',
        ]);

        EquitySnapshotRecord::query()->where('paper_account_id', $account->id)->delete();

        // Matching closed position and executions for realized PnL reconciliation
        $signalId = (string) Uuid::uuid4();
        SignalRecord::query()->create([
            'id' => $signalId, 'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT',
            'strategy_id' => 'baseline', 'strategy_version' => '1.0.0', 'timeframe' => '15', 'side' => 'BUY',
            'quality_class' => 'A', 'score' => 85, 'decision_time' => $now->subHours(3),
            'candle_close_time' => $now->subHours(3), 'expires_at' => $now->addHours(1),
            'maximum_holding_minutes' => 240, 'reasons' => ['test'], 'risks' => [],
            'indicator_snapshot' => [], 'contract' => [], 'schema_version' => '1.0.0',
        ]);

        $position = VirtualPositionRecord::query()->create([
            'id' => (string) Uuid::uuid4(), 'paper_account_id' => $account->id, 'signal_id' => $signalId,
            'symbol' => 'BTCUSDT', 'side' => 'BUY', 'quality_class' => 'A',
            'status' => PositionStatus::ClosedSl->value, 'entry_price' => '60000.00', 'stop_loss' => '50000.00',
            'take_profit_1' => '65000.00', 'take_profit_2' => '70000.00', 'initial_quantity' => '0.10',
            'remaining_quantity' => '0.00', 'qty_step' => '0.001', 'risk_capital' => '1000.00',
            'gross_pnl' => '-1000.00', 'fees' => '0.00', 'funding' => '0',
            'net_pnl' => '-1000.00', 'unrealized_pnl' => '0.00', 'r_multiple' => '-1.00',
            'opened_at' => $now->subHours(3), 'maximum_holding_until' => $now->addHours(1),
            'closed_at' => $now, 'last_processed_event_at' => $now, 'execution_model' => 'NEXT_CANDLE_OPEN',
            'strategy_version' => '1.0.0', 'risk_version' => '1.0.0', 'execution_version' => '1.0.0', 'schema_version' => '1.0.0',
            'funding_complete' => true,
        ]);

        VirtualExecutionRecord::query()->create([
            'id' => (string) Uuid::uuid4(), 'position_id' => $position->id, 'type' => ExecutionType::Entry->value,
            'side' => 'BUY', 'quantity' => '0.10', 'price' => '60000.00', 'gross_pnl' => '0.00',
            'commission' => '0.00', 'slippage' => '0.00', 'market_event_at' => $now->subHours(3),
            'registered_at' => $now->subHours(3), 'price_source' => 'NEXT_CANDLE_OPEN', 'ambiguous_execution' => false,
            'idempotency_key' => 'entry:'.$position->id, 'execution_version' => '1.0.0', 'schema_version' => '1.0.0',
        ]);

        VirtualExecutionRecord::query()->create([
            'id' => (string) Uuid::uuid4(), 'position_id' => $position->id, 'type' => ExecutionType::StopLoss->value,
            'side' => 'SELL', 'quantity' => '0.10', 'price' => '50000.00', 'gross_pnl' => '-1000.00',
            'commission' => '0.00', 'slippage' => '0.00', 'market_event_at' => $now,
            'registered_at' => $now, 'price_source' => 'TRIGGER_FILL', 'ambiguous_execution' => false,
            'idempotency_key' => 'exit:'.$position->id, 'execution_version' => '1.0.0', 'schema_version' => '1.0.0',
        ]);

        // Snapshot 1: Opening
        EquitySnapshotRecord::query()->create([
            'paper_account_id' => $account->id,
            'exchange_id' => 'bybit',
            'recorded_at' => CarbonImmutable::parse('2026-10-14 00:00:00', 'UTC'),
            'equity' => '10000',
            'realized_pnl' => '0',
            'unrealized_pnl' => '0',
            'drawdown_percent' => '0',
            'source_event_key' => 'account:initial',
            'valuation_fresh' => true,
        ]);

        // Snapshot 2: A VERIFIED FRESH mark reached 11,000 USDT (+1000 unrealized with fresh marks)
        EquitySnapshotRecord::query()->create([
            'paper_account_id' => $account->id,
            'exchange_id' => 'bybit',
            'recorded_at' => CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'),
            'equity' => '11000',
            'realized_pnl' => '0',
            'unrealized_pnl' => '1000',
            'drawdown_percent' => '0',
            'source_event_key' => 'checkpoint:verified-peak',
            'valued_through' => CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'),
            'valuation_fresh' => true, // Explicitly verified fresh
        ]);

        // Snapshot 3: Dropped to 9,000 USDT.
        // True drawdown from verified peak (11000): (11000 - 9000) / 11000 * 100 = 18.18181818%
        EquitySnapshotRecord::query()->create([
            'paper_account_id' => $account->id,
            'exchange_id' => 'bybit',
            'recorded_at' => CarbonImmutable::parse('2026-10-15 16:00:00', 'UTC'),
            'equity' => '9000',
            'realized_pnl' => '-1000',
            'unrealized_pnl' => '0',
            'drawdown_percent' => '18.18181818',
            'source_event_key' => 'checkpoint:trough',
            'valued_through' => CarbonImmutable::parse('2026-10-15 16:00:00', 'UTC'),
            'valuation_fresh' => true,
        ]);

        $reconciliation = app(LedgerReconciliationService::class);
        $result = $reconciliation->reconcileAccount($account->id);

        // Verification:
        // True verified peak elevated drawdown to 18.18181818%, exceeding stored 10.00000000%
        self::assertDecimalSame('18.18181818', $result['recalculated_drawdown_percent']);
        $discrepancyCodes = array_column($result['discrepancies'], 'code');
        self::assertContains('HISTORICAL_DRAWDOWN_UNDERSTATED', $discrepancyCodes);
    }

    public function test_stale_market_valuation_snapshot_never_elevates_peak(): void
    {
        $accountService = app(PaperAccountService::class);
        $account = $accountService->primaryAccount('baseline', 'bybit');

        $now = CarbonImmutable::parse('2026-10-15 16:00:00', 'UTC');

        $account->update([
            'starting_balance' => '10000',
            'peak_equity' => '10000',
            'current_equity' => '9500',
            'realized_pnl' => '-500',
            'unrealized_pnl' => '0',
            'maximum_drawdown_percent' => '5.00000000',
            'current_drawdown_percent' => '5.00000000',
            'status' => 'BLOCKED',
        ]);

        EquitySnapshotRecord::query()->where('paper_account_id', $account->id)->delete();

        // Matching closed position and executions for realized PnL reconciliation
        $signalId = (string) Uuid::uuid4();
        SignalRecord::query()->create([
            'id' => $signalId, 'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT',
            'strategy_id' => 'baseline', 'strategy_version' => '1.0.0', 'timeframe' => '15', 'side' => 'BUY',
            'quality_class' => 'A', 'score' => 85, 'decision_time' => $now->subHours(3),
            'candle_close_time' => $now->subHours(3), 'expires_at' => $now->addHours(1),
            'maximum_holding_minutes' => 240, 'reasons' => ['test'], 'risks' => [],
            'indicator_snapshot' => [], 'contract' => [], 'schema_version' => '1.0.0',
        ]);

        $position = VirtualPositionRecord::query()->create([
            'id' => (string) Uuid::uuid4(), 'paper_account_id' => $account->id, 'signal_id' => $signalId,
            'symbol' => 'BTCUSDT', 'side' => 'BUY', 'quality_class' => 'A',
            'status' => PositionStatus::ClosedSl->value, 'entry_price' => '60000.00', 'stop_loss' => '55000.00',
            'take_profit_1' => '65000.00', 'take_profit_2' => '70000.00', 'initial_quantity' => '0.10',
            'remaining_quantity' => '0.00', 'qty_step' => '0.001', 'risk_capital' => '500.00',
            'gross_pnl' => '-500.00', 'fees' => '0.00', 'funding' => '0',
            'net_pnl' => '-500.00', 'unrealized_pnl' => '0.00', 'r_multiple' => '-1.00',
            'opened_at' => $now->subHours(3), 'maximum_holding_until' => $now->addHours(1),
            'closed_at' => $now, 'last_processed_event_at' => $now, 'execution_model' => 'NEXT_CANDLE_OPEN',
            'strategy_version' => '1.0.0', 'risk_version' => '1.0.0', 'execution_version' => '1.0.0', 'schema_version' => '1.0.0',
            'funding_complete' => true,
        ]);

        VirtualExecutionRecord::query()->create([
            'id' => (string) Uuid::uuid4(), 'position_id' => $position->id, 'type' => ExecutionType::Entry->value,
            'side' => 'BUY', 'quantity' => '0.10', 'price' => '60000.00', 'gross_pnl' => '0.00',
            'commission' => '0.00', 'slippage' => '0.00', 'market_event_at' => $now->subHours(3),
            'registered_at' => $now->subHours(3), 'price_source' => 'NEXT_CANDLE_OPEN', 'ambiguous_execution' => false,
            'idempotency_key' => 'entry:'.$position->id, 'execution_version' => '1.0.0', 'schema_version' => '1.0.0',
        ]);

        VirtualExecutionRecord::query()->create([
            'id' => (string) Uuid::uuid4(), 'position_id' => $position->id, 'type' => ExecutionType::StopLoss->value,
            'side' => 'SELL', 'quantity' => '0.10', 'price' => '55000.00', 'gross_pnl' => '-500.00',
            'commission' => '0.00', 'slippage' => '0.00', 'market_event_at' => $now,
            'registered_at' => $now, 'price_source' => 'TRIGGER_FILL', 'ambiguous_execution' => false,
            'idempotency_key' => 'exit:'.$position->id, 'execution_version' => '1.0.0', 'schema_version' => '1.0.0',
        ]);

        // Snapshot 1: Opening
        EquitySnapshotRecord::query()->create([
            'paper_account_id' => $account->id,
            'exchange_id' => 'bybit',
            'recorded_at' => CarbonImmutable::parse('2026-10-14 00:00:00', 'UTC'),
            'equity' => '10000',
            'realized_pnl' => '0',
            'unrealized_pnl' => '0',
            'drawdown_percent' => '0',
            'source_event_key' => 'account:initial',
            'valuation_fresh' => true,
        ]);

        // Snapshot 2: STALE market valuation (lag > 300s, valuation_fresh = false)
        EquitySnapshotRecord::query()->create([
            'paper_account_id' => $account->id,
            'exchange_id' => 'bybit',
            'recorded_at' => CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'),
            'equity' => '12000', // Floating nominal equity from stale marks
            'realized_pnl' => '0',
            'unrealized_pnl' => '2000',
            'drawdown_percent' => '0',
            'source_event_key' => 'checkpoint:stale-peak',
            'valued_through' => CarbonImmutable::parse('2026-10-15 11:30:00', 'UTC'),
            'valuation_fresh' => false, // STALE!
        ]);

        // Snapshot 3: Dropped to 9500 USDT.
        EquitySnapshotRecord::query()->create([
            'paper_account_id' => $account->id,
            'exchange_id' => 'bybit',
            'recorded_at' => CarbonImmutable::parse('2026-10-15 16:00:00', 'UTC'),
            'equity' => '9500',
            'realized_pnl' => '-500',
            'unrealized_pnl' => '0',
            'drawdown_percent' => '5.00000000',
            'source_event_key' => 'checkpoint:trough',
            'valued_through' => CarbonImmutable::parse('2026-10-15 16:00:00', 'UTC'),
            'valuation_fresh' => true,
        ]);

        $reconciliation = app(LedgerReconciliationService::class);
        $result = $reconciliation->reconcileAccount($account->id);

        // Verification:
        // Stale snapshot did NOT elevate peak to 12,000. Recalculated drawdown is exactly (10000 - 9500)/10000 = 5.00%
        self::assertDecimalSame('5.00000000', $result['recalculated_drawdown_percent']);
        $discrepancyCodes = array_column($result['discrepancies'], 'code');
        self::assertNotContains('HISTORICAL_DRAWDOWN_UNDERSTATED', $discrepancyCodes);
        self::assertSame(0, $result['discrepancy_count']);
    }
}
