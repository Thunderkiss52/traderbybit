<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\PaperTrading\Domain\ExecutionType;
use App\PaperTrading\Domain\PositionStatus;
use App\PaperTrading\Infrastructure\Persistence\EquitySnapshotRecord;
use App\PaperTrading\Infrastructure\Persistence\MarketFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\PositionFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Research\Application\LedgerReconciliationService;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class FinancialAccountingAndReconciliationPostgresIntegrationTest extends TestCase
{
    private array $cleanUpAccountIds = [];

    private array $cleanUpSignalIds = [];

    private array $cleanUpPositionIds = [];

    private array $cleanUpExecutionIds = [];

    private array $cleanUpFundingIds = [];

    private array $cleanUpMarketFundingIds = [];

    private array $cleanUpSnapshotIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePostgresTestDatabaseReady('paper_accounts');
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            if (! empty($this->cleanUpExecutionIds)) {
                VirtualExecutionRecord::query()->whereIn('id', $this->cleanUpExecutionIds)->delete();
            }
            if (! empty($this->cleanUpFundingIds)) {
                PositionFundingEventRecord::query()->whereIn('id', $this->cleanUpFundingIds)->delete();
            }
            if (! empty($this->cleanUpMarketFundingIds)) {
                MarketFundingEventRecord::query()->whereIn('id', $this->cleanUpMarketFundingIds)->delete();
            }
            if (! empty($this->cleanUpPositionIds)) {
                VirtualPositionRecord::query()->whereIn('id', $this->cleanUpPositionIds)->delete();
            }
            if (! empty($this->cleanUpSnapshotIds)) {
                EquitySnapshotRecord::query()->whereIn('id', $this->cleanUpSnapshotIds)->delete();
            }
            if (! empty($this->cleanUpSignalIds)) {
                SignalRecord::query()->whereIn('id', $this->cleanUpSignalIds)->delete();
            }
            if (! empty($this->cleanUpAccountIds)) {
                PaperAccountRecord::query()->whereIn('id', $this->cleanUpAccountIds)->delete();
            }
        }

        parent::tearDown();
    }

    public function test_golden_positions_lifecycle_accounting_identities(): void
    {
        $fixturePath = base_path('tests/Fixtures/Golden/golden_positions_29.json');
        if (! file_exists($fixturePath)) {
            $this->markTestSkipped('Golden fixture not found: '.$fixturePath);
        }

        $positions = json_decode(file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(29, $positions);

        $totalGross = BigDecimal::zero();
        $totalFees = BigDecimal::zero();
        $totalFunding = BigDecimal::zero();
        $totalNet = BigDecimal::zero();

        foreach ($positions as $p) {
            $gross = BigDecimal::of($p['gross_pnl'] ?? '0');
            $fees = BigDecimal::of($p['fees'] ?? '0');
            $funding = BigDecimal::of($p['funding'] ?? '0');
            $net = BigDecimal::of($p['net_pnl'] ?? '0');

            // Invariant: net = gross - fees + signed_funding (within 0.0001 rounding)
            $expectedNet = $gross->minus($fees)->plus($funding);
            $diff = $net->minus($expectedNet)->abs();
            self::assertTrue($diff->isLessThan(BigDecimal::of('0.0002')), "Position {$p['id']} identity violated: net={$net}, expected={$expectedNet}");

            $totalGross = $totalGross->plus($gross);
            $totalFees = $totalFees->plus($fees);
            $totalFunding = $totalFunding->plus($funding);
            $totalNet = $totalNet->plus($net);
        }

        // Section 1 verification: gross ~+142.5411, fees ~104.4998, signed funding ~-0.5356, net ~+37.5057
        self::assertSame('142.5411', (string) $totalGross->toScale(4, RoundingMode::HalfUp));
        self::assertSame('104.4998', (string) $totalFees->toScale(4, RoundingMode::HalfUp));
        self::assertSame('-0.5356', (string) $totalFunding->toScale(4, RoundingMode::HalfUp));
        self::assertSame('37.5057', (string) $totalNet->toScale(4, RoundingMode::HalfUp));
    }

    public function test_window_reconciliation_detects_boundary_fills_and_matches_fee_gap(): void
    {
        $now = CarbonImmutable::parse('2026-10-18 14:45:00', 'UTC');
        $windowStart = $now;
        $windowEnd = $now->addHours(24);

        $account = PaperAccountRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'name' => 'Boundary Fill Test Account',
            'strategy_id' => 'momentum',
            'strategy_version' => '1.0.0',
            'starting_balance' => '10000.00',
            'current_equity' => '10050.00',
            'peak_equity' => '10050.00',
            'realized_pnl' => '50.00',
            'unrealized_pnl' => '0.00',
            'current_drawdown_percent' => '0.00',
            'maximum_drawdown_percent' => '0.00',
            'status' => 'ACTIVE',
        ]);
        $this->cleanUpAccountIds[] = $account->id;

        $signal = SignalRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15',
            'side' => 'BUY',
            'strategy_id' => 'momentum',
            'strategy_version' => '1.0.0',
            'score' => 85,
            'decision_time' => $windowStart->subMinutes(30),
            'candle_close_time' => $windowStart->subMinutes(30),
            'expires_at' => $windowEnd,
            'maximum_holding_minutes' => 1440,
            'reasons' => ['test'],
            'risks' => [],
            'indicator_snapshot' => [],
            'contract' => [],
            'schema_version' => '1.0.0',
        ]);
        $this->cleanUpSignalIds[] = $signal->id;

        // Position opened 30m before window, closed inside window
        $position = VirtualPositionRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'paper_account_id' => $account->id,
            'signal_id' => $signal->id,
            'symbol' => 'BTCUSDT',
            'side' => 'BUY',
            'quality_class' => 'A',
            'status' => PositionStatus::ClosedTp->value,
            'entry_price' => '60000.00',
            'stop_loss' => '59000.00',
            'take_profit_1' => '62000.00',
            'take_profit_2' => '63000.00',
            'initial_quantity' => '0.10',
            'remaining_quantity' => '0.00',
            'qty_step' => '0.001',
            'risk_capital' => '100.00',
            'gross_pnl' => '200.00',
            'fees' => '3.50', // 1.50 entry + 2.00 exit
            'funding' => '0.00',
            'net_pnl' => '196.50',
            'unrealized_pnl' => '0.00',
            'r_multiple' => '1.965',
            'opened_at' => $windowStart->subMinutes(20), // Outside window
            'maximum_holding_until' => $windowEnd,
            'closed_at' => $windowStart->addHours(2), // Inside window
            'last_processed_event_at' => $windowStart->addHours(2),
            'execution_model' => 'NEXT_CANDLE_OPEN',
            'strategy_version' => '1.0.0',
            'risk_version' => '1.0.0',
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);
        $this->cleanUpPositionIds[] = $position->id;

        // Entry execution: 20 minutes before window (BOUNDARY FILL, fee = 1.50)
        $entryExec = VirtualExecutionRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'position_id' => $position->id,
            'type' => ExecutionType::Entry->value,
            'side' => 'BUY',
            'quantity' => '0.10',
            'price' => '60000.00',
            'gross_pnl' => '0.00',
            'commission' => '1.50',
            'slippage' => '0.00',
            'market_event_at' => $windowStart->subMinutes(20),
            'registered_at' => $windowStart->subMinutes(20),
            'price_source' => 'NEXT_CANDLE_OPEN',
            'ambiguous_execution' => false,
            'idempotency_key' => 'exec:entry:'.$position->id,
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);
        $this->cleanUpExecutionIds[] = $entryExec->id;

        // Exit execution: 2 hours inside window (IN-WINDOW, fee = 2.00)
        $exitExec = VirtualExecutionRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'position_id' => $position->id,
            'type' => ExecutionType::TakeProfit1->value,
            'side' => 'SELL',
            'quantity' => '0.10',
            'price' => '62000.00',
            'gross_pnl' => '200.00',
            'commission' => '2.00',
            'slippage' => '0.00',
            'market_event_at' => $windowStart->addHours(2),
            'registered_at' => $windowStart->addHours(2),
            'price_source' => 'LIMIT_ORDER',
            'ambiguous_execution' => false,
            'idempotency_key' => 'exec:exit:'.$position->id,
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);
        $this->cleanUpExecutionIds[] = $exitExec->id;

        $service = $this->app->make(LedgerReconciliationService::class);
        $reconciliation = $service->reconcileWindow($account->id, $windowStart, $windowEnd);

        self::assertSame(1, $reconciliation['in_window_executions_count']);
        self::assertTrue(BigDecimal::of($reconciliation['in_window_fees'])->isEqualTo('2.00'));
        self::assertTrue(BigDecimal::of($reconciliation['active_positions_lifecycle_fees'])->isEqualTo('3.50'));
        self::assertSame(1, $reconciliation['boundary_executions_count']);
        self::assertSame([$entryExec->id], $reconciliation['boundary_execution_ids']);
        self::assertTrue(BigDecimal::of($reconciliation['boundary_fees'])->isEqualTo('1.50'));
        self::assertTrue($reconciliation['fee_difference_reconciled']);
    }

    public function test_negative_control_inverted_funding_sign_fails_reconciliation(): void
    {
        $now = CarbonImmutable::now('UTC');

        $account = PaperAccountRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'name' => 'Funding Sign Fault Test Account',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'starting_balance' => '10000.00',
            'current_equity' => '10010.00',
            'peak_equity' => '10010.00',
            'realized_pnl' => '10.00', // Stored includes positive funding of +10
            'unrealized_pnl' => '0.00',
            'current_drawdown_percent' => '0.00',
            'maximum_drawdown_percent' => '0.00',
            'status' => 'ACTIVE',
        ]);
        $this->cleanUpAccountIds[] = $account->id;

        $signal = SignalRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15',
            'side' => 'BUY',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'score' => 80,
            'decision_time' => $now->subHours(2),
            'candle_close_time' => $now->subHours(2),
            'expires_at' => $now->addHours(2),
            'maximum_holding_minutes' => 240,
            'reasons' => ['test'],
            'risks' => [],
            'indicator_snapshot' => [],
            'contract' => [],
            'schema_version' => '1.0.0',
        ]);
        $this->cleanUpSignalIds[] = $signal->id;

        $position = VirtualPositionRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'paper_account_id' => $account->id,
            'signal_id' => $signal->id,
            'symbol' => 'BTCUSDT',
            'side' => 'BUY',
            'quality_class' => 'A',
            'status' => PositionStatus::ClosedTp->value,
            'entry_price' => '60000.00',
            'stop_loss' => '59000.00',
            'take_profit_1' => '62000.00',
            'take_profit_2' => '63000.00',
            'initial_quantity' => '0.10',
            'remaining_quantity' => '0.00',
            'qty_step' => '0.001',
            'risk_capital' => '100.00',
            'gross_pnl' => '0.00',
            'fees' => '0.00',
            'funding' => '10.00',
            'net_pnl' => '10.00',
            'unrealized_pnl' => '0.00',
            'r_multiple' => '0.10',
            'opened_at' => $now->subHours(2),
            'maximum_holding_until' => $now->addHours(2),
            'closed_at' => $now->subHour(),
            'last_processed_event_at' => $now->subHour(),
            'execution_model' => 'NEXT_CANDLE_OPEN',
            'strategy_version' => '1.0.0',
            'risk_version' => '1.0.0',
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);
        $this->cleanUpPositionIds[] = $position->id;

        // Fault injected: funding event recorded as -10 instead of +10 (sign inversion)
        $mktFunding = MarketFundingEventRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'funding_at' => $now->subMinutes(75),
            'rate' => '-0.0001',
            'mark_price' => '60000.00',
        ]);
        $this->cleanUpMarketFundingIds[] = $mktFunding->id;

        $posFunding = PositionFundingEventRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'position_id' => $position->id,
            'market_funding_event_id' => $mktFunding->id,
            'quantity' => '0.10',
            'mark_price' => '60000.00',
            'rate' => '-0.0001',
            'amount' => '-10.00', // INVERTED SIGN: -10 vs +10
            'funding_at' => $now->subMinutes(75),
        ]);
        $this->cleanUpFundingIds[] = $posFunding->id;

        $service = $this->app->make(LedgerReconciliationService::class);
        $result = $service->reconcileAccount($account->id);

        // Reconciliation MUST detect discrepancy
        self::assertSame('DISCREPANCY_DETECTED', $result['status']);
        self::assertGreaterThanOrEqual(1, $result['discrepancy_count']);
        $codes = collect($result['discrepancies'])->pluck('code')->all();
        self::assertContains('BALANCE_MISMATCH', $codes);
    }
}
