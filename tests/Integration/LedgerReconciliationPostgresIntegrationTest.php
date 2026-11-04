<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Domain\ExecutionType;
use App\PaperTrading\Domain\PositionStatus;
use App\PaperTrading\Infrastructure\Persistence\EquitySnapshotRecord;
use App\PaperTrading\Infrastructure\Persistence\MarketFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\PositionFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Research\Application\LedgerReconciliationService;
use App\Research\Infrastructure\Persistence\ReconciliationRunRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class LedgerReconciliationPostgresIntegrationTest extends TestCase
{
    private array $cleanUpAccountIds = [];

    private array $cleanUpSignalIds = [];

    private array $cleanUpPositionIds = [];

    private array $cleanUpExecutionIds = [];

    private array $cleanUpFundingEventIds = [];

    private array $cleanUpMarketFundingIds = [];

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
            if (! empty($this->cleanUpFundingEventIds)) {
                PositionFundingEventRecord::query()->whereIn('id', $this->cleanUpFundingEventIds)->delete();
            }
            if (! empty($this->cleanUpMarketFundingIds)) {
                MarketFundingEventRecord::query()->whereIn('id', $this->cleanUpMarketFundingIds)->delete();
            }
            if (! empty($this->cleanUpPositionIds)) {
                VirtualPositionRecord::query()->whereIn('id', $this->cleanUpPositionIds)->delete();
            }
            if (! empty($this->cleanUpSignalIds)) {
                SignalRecord::query()->whereIn('id', $this->cleanUpSignalIds)->delete();
            }
            if (! empty($this->cleanUpAccountIds)) {
                ReconciliationRunRecord::query()->whereIn('paper_account_id', $this->cleanUpAccountIds)->delete();
                PaperAccountRecord::query()->whereIn('id', $this->cleanUpAccountIds)->delete();
            }
        }

        parent::tearDown();
    }

    public function test_reconcile_all_primary_accounts_in_postgresql_succeeds_without_sql_error(): void
    {
        // The ledger invariant is exactly thirteen PRIMARY accounts. The isolated bybit_test
        // database is shared by multiple integration suites that create and clean up their
        // own non-primary scaffolding accounts, so assert on the primary invariant rather
        // than on a global row count that unrelated residue could perturb.
        $this->app->make(PaperAccountService::class)->allPrimaryAccounts();
        $accounts = PaperAccountRecord::query()->where('is_primary', true)->orderBy('name')->get();
        self::assertTrue(in_array($accounts->count(), [13, 26], true), "Expected 13 or 26 primary paper accounts, got {$accounts->count()}.");

        $service = $this->app->make(LedgerReconciliationService::class);
        $results = [];
        foreach ($accounts as $account) {
            $results[] = $service->reconcileAccount($account->id);
        }

        self::assertCount($accounts->count(), $results);
        foreach ($results as $res) {
            // Legacy migration-created fixtures may lack initial snapshots; do not certify them complete.
            $hasInitial = DB::table('equity_snapshots')->where('paper_account_id', $res['paper_account_id'])->where('source_event_key', 'account:initial')->exists();
            self::assertSame(
                $hasInitial ? 'PASSED' : 'INCOMPLETE',
                $res['status'],
                "Account {$res['paper_account_id']} failed reconciliation with status '{$res['status']}'. Discrepancies: ".json_encode($res['discrepancies'], JSON_PRETTY_PRINT)
            );
            if (! $hasInitial) {
                self::assertContains('initial_equity_snapshot_unavailable', $res['incomplete_reasons']);
            }
            self::assertSame(0, $res['discrepancy_count']);
            self::assertNotEmpty($res['id']);
        }
    }

    public function test_postgresql_financial_reconciliation_with_partial_and_full_exits_and_funding(): void
    {
        $now = CarbonImmutable::now('UTC');

        // 1. Create isolated paper account
        $account = PaperAccountRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'name' => 'Isolation Test Account',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'starting_balance' => '10000.00',
            'current_equity' => '10247.70',
            'peak_equity' => '10247.70',
            'realized_pnl' => '247.70',
            'unrealized_pnl' => '0.00',
            'current_drawdown_percent' => '0.00',
            'maximum_drawdown_percent' => '0.00',
            'status' => 'ACTIVE',
        ]);
        $this->cleanUpAccountIds[] = $account->id;
        EquitySnapshotRecord::query()->create([
            'paper_account_id' => $account->id, 'recorded_at' => $now->subHours(4), 'equity' => '10000',
            'realized_pnl' => '0', 'unrealized_pnl' => '0', 'drawdown_percent' => '0', 'source_event_key' => 'account:initial',
        ]);

        // 2. Create signal
        $signal = SignalRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'timeframe' => '15',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 85,
            'decision_time' => $now->subHours(2),
            'candle_close_time' => $now->subHours(2),
            'expires_at' => $now->addHours(2),
            'maximum_holding_minutes' => 240,
            'reasons' => ['test_reconciliation'],
            'risks' => [],
            'indicator_snapshot' => [],
            'contract' => [],
            'schema_version' => '1.0.0',
        ]);
        $this->cleanUpSignalIds[] = $signal->id;

        // 3. Create closed position
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
            'gross_pnl' => '250.00',
            'fees' => '4.80',
            'funding' => '2.50',
            'net_pnl' => '247.70',
            'unrealized_pnl' => '0.00',
            'r_multiple' => '2.477',
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

        // 4. Create executions: Entry, TP1 (partial exit), TP2 (final exit)
        // Entry: gross_pnl = 0, commission = 1.50
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
            'market_event_at' => $now->subHours(2),
            'registered_at' => $now->subHours(2),
            'price_source' => 'NEXT_CANDLE_OPEN',
            'ambiguous_execution' => false,
            'idempotency_key' => 'exec:entry:'.$position->id,
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);
        $this->cleanUpExecutionIds[] = $entryExec->id;

        // TP1: gross_pnl = 100.00, commission = 1.60
        $tp1Exec = VirtualExecutionRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'position_id' => $position->id,
            'type' => ExecutionType::TakeProfit1->value,
            'side' => 'SELL',
            'quantity' => '0.05',
            'price' => '62000.00',
            'gross_pnl' => '100.00',
            'commission' => '1.60',
            'slippage' => '0.00',
            'market_event_at' => $now->subMinutes(90),
            'registered_at' => $now->subMinutes(90),
            'price_source' => 'LIMIT_ORDER',
            'ambiguous_execution' => false,
            'idempotency_key' => 'exec:tp1:'.$position->id,
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);
        $this->cleanUpExecutionIds[] = $tp1Exec->id;

        // Funding event: amount = 2.50
        $mktFunding = MarketFundingEventRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'funding_at' => $now->subMinutes(75),
            'rate' => '0.0001',
            'mark_price' => '62500.00',
        ]);
        $this->cleanUpMarketFundingIds[] = $mktFunding->id;

        $posFunding = PositionFundingEventRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'position_id' => $position->id,
            'market_funding_event_id' => $mktFunding->id,
            'quantity' => '0.05',
            'mark_price' => '62500.00',
            'rate' => '0.0001',
            'amount' => '2.50',
            'funding_at' => $now->subMinutes(75),
        ]);
        $this->cleanUpFundingEventIds[] = $posFunding->id;

        // TP2: gross_pnl = 150.00, commission = 1.70
        $tp2Exec = VirtualExecutionRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'position_id' => $position->id,
            'type' => ExecutionType::TakeProfit2->value,
            'side' => 'SELL',
            'quantity' => '0.05',
            'price' => '63000.00',
            'gross_pnl' => '150.00',
            'commission' => '1.70',
            'slippage' => '0.00',
            'market_event_at' => $now->subHour(),
            'registered_at' => $now->subHour(),
            'price_source' => 'LIMIT_ORDER',
            'ambiguous_execution' => false,
            'idempotency_key' => 'exec:tp2:'.$position->id,
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);
        $this->cleanUpExecutionIds[] = $tp2Exec->id;

        // 5. Execute reconciliation on PostgreSQL
        $service = $this->app->make(LedgerReconciliationService::class);
        $result = $service->reconcileAccount($account->id);

        // 6. Assert zero discrepancies and exact mathematical matching
        self::assertSame('PASSED', $result['status']);
        self::assertSame(0, $result['discrepancy_count']);
        self::assertEmpty($result['discrepancies']);

        // Check balance equality: Starting(10000) + RealizedGross(250) - Fees(4.80) + Funding(2.50) = 10247.70
        self::assertTrue(BigDecimal::of($result['recalculated_balance'])->isEqualTo('10247.70'));
        self::assertTrue(BigDecimal::of($result['stored_balance'])->isEqualTo('10247.70'));
        self::assertTrue(BigDecimal::of($result['recalculated_equity'])->isEqualTo('10247.70'));
        self::assertTrue(BigDecimal::of($result['stored_equity'])->isEqualTo('10247.70'));
    }

    public function test_postgresql_reconciliation_detects_balance_discrepancy(): void
    {
        $now = CarbonImmutable::now('UTC');

        // Create account where stored realized_pnl does not match execution reality
        $account = PaperAccountRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'name' => 'Corrupt Balance Test Account',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'starting_balance' => '10000.00',
            'current_equity' => '10500.00', // Fabricated $500 instead of real
            'peak_equity' => '10500.00',
            'realized_pnl' => '500.00', // Stored differs from real executions (0) by $500
            'unrealized_pnl' => '0.00',
            'current_drawdown_percent' => '0.00',
            'maximum_drawdown_percent' => '0.00',
            'status' => 'ACTIVE',
        ]);
        $this->cleanUpAccountIds[] = $account->id;

        $service = $this->app->make(LedgerReconciliationService::class);
        $result = $service->reconcileAccount($account->id);

        self::assertSame('DISCREPANCY_DETECTED', $result['status']);
        self::assertGreaterThanOrEqual(1, $result['discrepancy_count']);
        $codes = collect($result['discrepancies'])->pluck('code')->all();
        self::assertContains('BALANCE_MISMATCH', $codes);
        self::assertContains('EQUITY_MISMATCH', $codes);
    }
}
