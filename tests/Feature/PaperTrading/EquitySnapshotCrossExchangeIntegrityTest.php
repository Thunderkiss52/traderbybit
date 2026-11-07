<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\Exchanges\Binance\BinanceDeploymentInitializer;
use App\Exchanges\Binance\ExchangePaperReconciliation;
use App\PaperTrading\Application\EquitySnapshotRepairService;
use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Jobs\CapturePaperEquityJob;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end PostgreSQL integration tests for cross-exchange equity snapshot integrity,
 * defect reproduction, dry-run, safe repair, idempotency, M9 reconciliation, and scheduler recurrence.
 */
final class EquitySnapshotCrossExchangeIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // This fixture verifies the preserved original 26-account financial cohort.
        config(['exchanges.enabled' => ['bybit', 'binance']]);
        DB::table('exchanges')->whereNotIn('id', ['bybit', 'binance'])->delete();
    }

    private function provisionTwentySixAccounts(): void
    {
        $this->app->make(PaperAccountService::class)->allPrimaryAccounts('bybit');
        $initializer = $this->app->make(BinanceDeploymentInitializer::class);
        $result = $initializer->initialize('10000', 'OWNER-VECTOR-20261023');
        self::assertSame('INITIALIZED_SUCCESSFULLY', $result['status']);
    }

    /**
     * Test 1 — Snapshot creation:
     * Execute CapturePaperEquityJob and verify correct exchange IDs for both Bybit and Binance accounts.
     */
    public function test_01_snapshot_creation_assigns_correct_exchange_id_from_authoritative_accounts(): void
    {
        $this->provisionTwentySixAccounts();

        // Clear existing snapshots so we test CapturePaperEquityJob in isolation
        DB::table('equity_snapshots')->delete();

        // Run real CapturePaperEquityJob
        $job = new CapturePaperEquityJob;
        $job->handle();

        $snapshots = DB::table('equity_snapshots')->get();
        self::assertCount(26, $snapshots);

        $accounts = DB::table('paper_accounts')->where('is_primary', true)->get()->keyBy('id');

        foreach ($snapshots as $snapshot) {
            $account = $accounts->get($snapshot->paper_account_id);
            self::assertNotNull($account);
            self::assertSame($account->exchange_id, $snapshot->exchange_id, "Snapshot exchange_id must match owning account exchange_id for {$account->name}");
            self::assertStringStartsWith('checkpoint:', $snapshot->source_event_key);
            self::assertEquals(BigDecimal::of((string) $account->current_equity), BigDecimal::of((string) $snapshot->equity));
        }

        $bybitSnapshots = $snapshots->where('exchange_id', 'bybit');
        $binanceSnapshots = $snapshots->where('exchange_id', 'binance');

        self::assertCount(13, $bybitSnapshots);
        self::assertCount(13, $binanceSnapshots);
    }

    /**
     * Test 2 — Reproduction of the production defect:
     * Simulate 13 Binance checkpoint snapshots incorrectly labeled 'bybit'.
     * Confirm M9 reconciliation fails with 13 CROSS_EXCHANGE_EQUITY_SNAPSHOTS issues.
     */
    public function test_02_reproduction_of_production_defect_causes_m9_reconciliation_failure(): void
    {
        $this->provisionTwentySixAccounts();

        // Add 13 checkpoint snapshots for Binance accounts labeled incorrectly as 'bybit' (exact production defect)
        $at = now('UTC')->startOfMinute();
        $key = 'checkpoint:'.$at->format('Y-m-d\\TH:i:00\\Z');
        $binanceAccounts = DB::table('paper_accounts')->where('exchange_id', 'binance')->where('is_primary', true)->get();

        foreach ($binanceAccounts as $account) {
            DB::table('equity_snapshots')->insert([
                'paper_account_id' => $account->id,
                'exchange_id' => 'bybit', // Incorrect label
                'recorded_at' => $at,
                'equity' => $account->current_equity,
                'realized_pnl' => '0',
                'unrealized_pnl' => '0',
                'drawdown_percent' => '0',
                'source_event_key' => $key,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $reconcile = $this->app->make(ExchangePaperReconciliation::class)->inspect();

        self::assertSame('FAIL', $reconcile['status']);
        self::assertCount(13, $reconcile['issues']);

        foreach ($reconcile['issues'] as $issue) {
            self::assertSame('binance', $issue['exchange_id']);
            self::assertSame('CROSS_EXCHANGE_EQUITY_SNAPSHOTS', $issue['code']);
            self::assertSame(1, $issue['mismatched_count']);
            self::assertSame('bybit', $issue['diagnostics'][0]['stored_exchange_id']);
        }
    }

    /**
     * Test 3 — Dry run:
     * Run the repair in dry-run mode. Confirm 13 candidates detected, 0 rows modified.
     */
    public function test_03_repair_dry_run_detects_candidates_and_mutates_zero_rows(): void
    {
        $this->provisionTwentySixAccounts();

        $at = now('UTC')->startOfMinute();
        $key = 'checkpoint:'.$at->format('Y-m-d\\TH:i:00\\Z');
        $binanceAccounts = DB::table('paper_accounts')->where('exchange_id', 'binance')->where('is_primary', true)->get();

        foreach ($binanceAccounts as $account) {
            DB::table('equity_snapshots')->insert([
                'paper_account_id' => $account->id,
                'exchange_id' => 'bybit',
                'recorded_at' => $at,
                'equity' => $account->current_equity,
                'realized_pnl' => '0',
                'unrealized_pnl' => '0',
                'drawdown_percent' => '0',
                'source_event_key' => $key,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $repairService = $this->app->make(EquitySnapshotRepairService::class);

        // Dry-run via service
        $dryRunResult = $repairService->repair(apply: false);

        self::assertSame('DRY_RUN_PENDING_APPROVAL', $dryRunResult['status']);
        self::assertFalse($dryRunResult['applied']);
        self::assertSame(13, $dryRunResult['candidates_count']);
        self::assertSame(0, $dryRunResult['repaired_count']);
        self::assertCount(13, $dryRunResult['manifest']);

        // Verify database is completely unmutated
        $mismatchedInDb = DB::table('equity_snapshots')
            ->whereIn('paper_account_id', $binanceAccounts->pluck('id'))
            ->where('exchange_id', 'bybit')
            ->count();
        self::assertSame(13, $mismatchedInDb);

        // Dry-run via Artisan command
        $this->artisan('paper:repair-equity-snapshots', ['--dry-run' => true])->assertSuccessful();

        $mismatchedInDbAfterArtisan = DB::table('equity_snapshots')
            ->whereIn('paper_account_id', $binanceAccounts->pluck('id'))
            ->where('exchange_id', 'bybit')
            ->count();
        self::assertSame(13, $mismatchedInDbAfterArtisan);
    }

    /**
     * Test 4 — Apply repair:
     * Execute repair with --apply. Confirm precisely 13 rows updated to 'binance'.
     * Confirm no other columns or financial tables change.
     */
    public function test_04_apply_repair_updates_precisely_thirteen_rows_without_side_effects(): void
    {
        $this->provisionTwentySixAccounts();

        $at = now('UTC')->startOfMinute();
        $key = 'checkpoint:'.$at->format('Y-m-d\\TH:i:00\\Z');
        $binanceAccounts = DB::table('paper_accounts')->where('exchange_id', 'binance')->where('is_primary', true)->get();

        $insertedIds = [];
        foreach ($binanceAccounts as $account) {
            $insertedIds[] = DB::table('equity_snapshots')->insertGetId([
                'paper_account_id' => $account->id,
                'exchange_id' => 'bybit',
                'recorded_at' => $at,
                'equity' => $account->current_equity,
                'realized_pnl' => '0',
                'unrealized_pnl' => '0',
                'drawdown_percent' => '0',
                'source_event_key' => $key,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Snapshot other tables to verify zero side-effects
        $accountsBefore = DB::table('paper_accounts')->orderBy('id')->get();
        $capitalEventsBefore = DB::table('paper_capital_events')->orderBy('id')->get();

        $repairService = $this->app->make(EquitySnapshotRepairService::class);
        $result = $repairService->repair(apply: true);

        self::assertSame('REPAIR_APPLIED_SUCCESSFULLY', $result['status']);
        self::assertTrue($result['applied']);
        self::assertSame(13, $result['candidates_count']);
        self::assertSame(13, $result['repaired_count']);
        self::assertEquals($insertedIds, $result['repaired_ids']);

        // Confirm all 13 rows now have exchange_id = 'binance'
        $repairedRows = DB::table('equity_snapshots')->whereIn('id', $insertedIds)->get();
        foreach ($repairedRows as $row) {
            self::assertSame('binance', $row->exchange_id);
            self::assertSame($key, $row->source_event_key);
        }

        // Confirm zero other rows or tables were altered
        $accountsAfter = DB::table('paper_accounts')->orderBy('id')->get();
        $capitalEventsAfter = DB::table('paper_capital_events')->orderBy('id')->get();

        self::assertEquals($accountsBefore, $accountsAfter);
        self::assertEquals($capitalEventsBefore, $capitalEventsAfter);
    }

    /**
     * Test 5 — Idempotency:
     * Running the repair a second time results in 0 pending repairs and 0 modifications.
     */
    public function test_05_repair_is_idempotent(): void
    {
        $this->provisionTwentySixAccounts();

        $at = now('UTC')->startOfMinute();
        $key = 'checkpoint:'.$at->format('Y-m-d\\TH:i:00\\Z');
        $binanceAccounts = DB::table('paper_accounts')->where('exchange_id', 'binance')->where('is_primary', true)->get();

        foreach ($binanceAccounts as $account) {
            DB::table('equity_snapshots')->insert([
                'paper_account_id' => $account->id,
                'exchange_id' => 'bybit',
                'recorded_at' => $at,
                'equity' => $account->current_equity,
                'realized_pnl' => '0',
                'unrealized_pnl' => '0',
                'drawdown_percent' => '0',
                'source_event_key' => $key,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $repairService = $this->app->make(EquitySnapshotRepairService::class);

        // First apply
        $firstResult = $repairService->repair(apply: true);
        self::assertSame('REPAIR_APPLIED_SUCCESSFULLY', $firstResult['status']);
        self::assertSame(13, $firstResult['repaired_count']);

        // Second apply
        $secondResult = $repairService->repair(apply: true);
        self::assertSame('ALREADY_CONSISTENT', $secondResult['status']);
        self::assertSame(0, $secondResult['candidates_count']);
        self::assertSame(0, $secondResult['repaired_count']);
        self::assertEmpty($secondResult['repaired_ids']);

        // Third run via artisan
        $this->artisan('paper:repair-equity-snapshots', ['--apply' => true])->assertSuccessful();
    }

    /**
     * Test 6 — M9 reconciliation:
     * Post-repair state yields LEDGER_PASS_ONLY, 26 verified pairs, 0 issues.
     */
    public function test_06_m9_reconciliation_passes_after_repair(): void
    {
        $this->provisionTwentySixAccounts();

        $at = now('UTC')->startOfMinute();
        $key = 'checkpoint:'.$at->format('Y-m-d\\TH:i:00\\Z');
        $binanceAccounts = DB::table('paper_accounts')->where('exchange_id', 'binance')->where('is_primary', true)->get();

        foreach ($binanceAccounts as $account) {
            DB::table('equity_snapshots')->insert([
                'paper_account_id' => $account->id,
                'exchange_id' => 'bybit',
                'recorded_at' => $at,
                'equity' => $account->current_equity,
                'realized_pnl' => '0',
                'unrealized_pnl' => '0',
                'drawdown_percent' => '0',
                'source_event_key' => $key,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Confirm FAIL before repair
        $beforeReport = $this->app->make(ExchangePaperReconciliation::class)->inspect();
        self::assertSame('FAIL', $beforeReport['status']);
        self::assertCount(13, $beforeReport['issues']);

        // Repair
        $this->app->make(EquitySnapshotRepairService::class)->repair(apply: true);

        // Confirm PASS after repair
        $afterReport = $this->app->make(ExchangePaperReconciliation::class)->inspect();
        self::assertSame('LEDGER_PASS_ONLY', $afterReport['status']);
        self::assertSame(26, $afterReport['expected_pairs']);
        self::assertSame(26, $afterReport['verified_pairs']);
        self::assertEmpty($afterReport['issues']);
    }

    /**
     * Test 7 — Unrelated data preservation:
     * Bybit accounts and snapshots are never touched by the repair process.
     */
    public function test_07_unrelated_bybit_snapshots_and_records_are_preserved(): void
    {
        $this->provisionTwentySixAccounts();

        $bybitSnapshotsBefore = DB::table('equity_snapshots')->where('exchange_id', 'bybit')->orderBy('id')->get();
        $bybitAccountsBefore = DB::table('paper_accounts')->where('exchange_id', 'bybit')->orderBy('id')->get();

        // Run repair (both dry run and apply)
        $repairService = $this->app->make(EquitySnapshotRepairService::class);
        $repairService->repair(apply: false);
        $repairService->repair(apply: true);

        $bybitSnapshotsAfter = DB::table('equity_snapshots')->where('exchange_id', 'bybit')->orderBy('id')->get();
        $bybitAccountsAfter = DB::table('paper_accounts')->where('exchange_id', 'bybit')->orderBy('id')->get();

        self::assertEquals($bybitSnapshotsBefore, $bybitSnapshotsAfter);
        self::assertEquals($bybitAccountsBefore, $bybitAccountsAfter);
    }

    /**
     * Test 8 — Scheduler recurrence:
     * Running CapturePaperEquityJob after repair produces zero cross-exchange snapshots, M9 remains PASS.
     */
    public function test_08_scheduler_recurrence_maintains_clean_snapshots_and_m9_pass(): void
    {
        $this->provisionTwentySixAccounts();

        // 1. Initial M9 reconciliation is PASS
        $report1 = $this->app->make(ExchangePaperReconciliation::class)->inspect();
        self::assertSame('LEDGER_PASS_ONLY', $report1['status']);

        // 2. Execute scheduled CapturePaperEquityJob (as scheduled every minute)
        (new CapturePaperEquityJob)->handle();

        // 3. Confirm all new snapshots have correct exchange_id
        $crossExchangeCount = DB::table('equity_snapshots as s')
            ->join('paper_accounts as a', 's.paper_account_id', '=', 'a.id')
            ->whereRaw('s.exchange_id != a.exchange_id')
            ->count();
        self::assertSame(0, $crossExchangeCount);

        // 4. M9 reconciliation remains PASS
        $report2 = $this->app->make(ExchangePaperReconciliation::class)->inspect();
        self::assertSame('LEDGER_PASS_ONLY', $report2['status']);
        self::assertEmpty($report2['issues']);

        // 5. Subsequent run at a later minute
        (new CapturePaperEquityJob)->handle();

        $crossExchangeCount2 = DB::table('equity_snapshots as s')
            ->join('paper_accounts as a', 's.paper_account_id', '=', 'a.id')
            ->whereRaw('s.exchange_id != a.exchange_id')
            ->count();
        self::assertSame(0, $crossExchangeCount2);

        $report3 = $this->app->make(ExchangePaperReconciliation::class)->inspect();
        self::assertSame('LEDGER_PASS_ONLY', $report3['status']);
    }

    /**
     * Test 9 — Concurrency and retries:
     * Verify that concurrent or repeated snapshot capture does not duplicate or corrupt snapshots.
     */
    public function test_09_concurrency_and_retries_do_not_duplicate_or_corrupt_snapshots(): void
    {
        $this->provisionTwentySixAccounts();

        $job = new CapturePaperEquityJob;

        // Simulate 3 immediate retries within the same minute
        $job->handle();
        $job->handle();
        $job->handle();

        $accounts = DB::table('paper_accounts')->where('is_primary', true)->get();

        foreach ($accounts as $account) {
            $snapshots = DB::table('equity_snapshots')->where('paper_account_id', $account->id)->get();
            // Distinct source_event_keys must equal total snapshot count (no duplicate checkpoints)
            self::assertSame($snapshots->count(), $snapshots->unique('source_event_key')->count());

            // Every snapshot must match account's exchange_id
            foreach ($snapshots as $snap) {
                self::assertSame($account->exchange_id, $snap->exchange_id);
            }
        }

        $report = $this->app->make(ExchangePaperReconciliation::class)->inspect();
        self::assertSame('LEDGER_PASS_ONLY', $report['status']);
    }
}
