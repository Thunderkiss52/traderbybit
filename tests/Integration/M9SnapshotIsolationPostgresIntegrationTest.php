<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exchanges\Binance\BinanceDeploymentInitializer;
use App\Exchanges\Binance\ExchangePaperReconciliation;
use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class M9SnapshotIsolationPostgresIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedDatabase();
        $this->ensurePostgresTestDatabaseReady('paper_accounts');
        $this->cleanTables();
    }

    protected function tearDown(): void
    {
        $this->cleanTables();
        parent::tearDown();
    }

    private function cleanTables(): void
    {
        $this->assertIsolatedDatabase();
        if (DB::connection('pgsql')->getDriverName() === 'pgsql') {
            DB::connection('pgsql')->statement('TRUNCATE TABLE virtual_executions, virtual_positions, equity_snapshots, signals, paper_accounts, position_funding_events, market_funding_events CASCADE;');
        }
    }

    public function test_atomic_valuation_commit_during_m9_does_not_create_false_mismatch(): void
    {
        [$accountId, $positionId] = $this->createConsistentCohortWithPosition();
        $writer = $this->independentWriter();
        self::assertNotSame(DB::selectOne('select pg_backend_pid() as pid')->pid, $writer->selectOne('select pg_backend_pid() as pid')->pid);
        $committed = false;
        $accountQuery = null;
        $positionQuery = null;
        DB::listen(function (QueryExecuted $query) use ($writer, $accountId, $positionId, &$committed, &$accountQuery, &$positionQuery): void {
            if ($query->connectionName !== 'pgsql') {
                return;
            }
            if (str_contains($query->sql, 'from "virtual_positions" where "paper_account_id"')) {
                $positionQuery ??= $query->sql;
            }
            if ($committed || ! str_contains($query->sql, 'from "paper_accounts" where "is_primary"')) {
                return;
            }
            $accountQuery = $query->sql;
            $writer->transaction(static function () use ($writer, $accountId, $positionId): void {
                $writer->table('virtual_positions')->where('id', $positionId)->update(['unrealized_pnl' => '2.40825228']);
                $writer->table('paper_accounts')->where('id', $accountId)->update([
                    'unrealized_pnl' => '2.40825228', 'current_equity' => '10002.40825228',
                ]);
            });
            $committed = true;
        });

        $report = app(ExchangePaperReconciliation::class)->inspect();
        self::assertTrue($committed);
        self::assertNotNull($accountQuery);
        self::assertNotNull($positionQuery);
        self::assertSame('LEDGER_PASS_ONLY', $report['status'], json_encode($report['issues']));
        self::assertSame('repeatable read', $report['transaction_isolation']);
        self::assertSame(26, $report['verified_pairs']);
        $covered = collect($report['accounts'])->firstWhere('account_id', $accountId);
        self::assertSame('10000.000000000000', $covered['current_equity'], 'M9 must read the pre-commit repeatable-read snapshot, not the later committed valuation.');
        self::assertSame('2.408252280000', (string) DB::table('paper_accounts')->where('id', $accountId)->value('unrealized_pnl'));
        self::assertSame('2.408252280000', (string) DB::table('virtual_positions')->where('id', $positionId)->value('unrealized_pnl'));
    }

    public function test_genuine_mismatch_remains_a_failing_diagnostic(): void
    {
        [$accountId] = $this->createConsistentCohortWithPosition();
        DB::table('paper_accounts')->where('id', $accountId)->update([
            'unrealized_pnl' => '2.40825228', 'current_equity' => '10002.40825228',
        ]);

        $report = app(ExchangePaperReconciliation::class)->inspect();
        self::assertSame('FAIL', $report['status']);
        $issue = collect($report['issues'])->first(fn (array $row): bool => $row['exchange_id'] === 'bybit'
            && $row['strategy_id'] === 'multi_timeframe_trend_following' && $row['code'] === 'UNREALIZED_MISMATCH');
        self::assertNotNull($issue);
        self::assertSame('2.408252280000', $issue['account_unrealized_pnl']);
        self::assertSame('0.000000000000', $issue['active_position_unrealized_pnl']);
        self::assertSame('2.408252280000', $issue['difference']);
        self::assertSame(1, $issue['active_position_count']);
        self::assertSame(0, $issue['closed_nonzero_unrealized_count']);
        self::assertNotEmpty($issue['snapshot']);
        self::assertNotEmpty($issue['reconciliation_at_utc']);
        self::assertSame('repeatable read', $issue['transaction_isolation']);
        self::assertSame($accountId, $issue['account_id']);
        self::assertSame('ACTIVE', $issue['account_status']);
        self::assertSame(1, $issue['position_count']);
        self::assertSame('PER_EXCHANGE_PER_STRATEGY', $issue['reconciliation_scope']);
    }

    public function test_deployment_initializer_uses_one_snapshot_across_an_atomic_valuation_commit(): void
    {
        [$accountId, $positionId] = $this->createConsistentCohortWithPosition();
        $writer = $this->independentWriter();
        $committed = false;
        DB::listen(function (QueryExecuted $query) use ($writer, $accountId, $positionId, &$committed): void {
            if ($committed || $query->connectionName !== 'pgsql'
                || ! str_contains($query->sql, 'from "paper_accounts" where "is_primary"')) {
                return;
            }
            $writer->transaction(static function () use ($writer, $accountId, $positionId): void {
                $writer->table('virtual_positions')->where('id', $positionId)->update(['unrealized_pnl' => '2.40825228']);
                $writer->table('paper_accounts')->where('id', $accountId)->update([
                    'unrealized_pnl' => '2.40825228', 'current_equity' => '10002.40825228',
                ]);
            });
            $committed = true;
        });

        $result = app(BinanceDeploymentInitializer::class)->initialize();
        self::assertTrue($committed);
        self::assertSame('INITIALIZED_SUCCESSFULLY', $result['status']);
        self::assertSame('LEDGER_PASS_ONLY', $result['reconciliation']['status']);
        self::assertSame('repeatable read', $result['reconciliation']['transaction_isolation']);
        self::assertSame(26, $result['reconciliation']['verified_pairs']);
        $covered = collect($result['reconciliation']['accounts'])->firstWhere('account_id', $accountId);
        self::assertSame('10000.000000000000', $covered['current_equity'], 'Deployment M9 must use one snapshot across the valuation commit.');
        self::assertSame('2.408252280000', (string) DB::table('paper_accounts')->where('id', $accountId)->value('unrealized_pnl'));
    }

    public function test_reconciliation_reports_multiple_real_financial_errors_and_accepts_blocked_status(): void
    {
        [$positionAccountId, $positionId] = $this->createConsistentCohortWithPosition();
        $blocked = DB::table('paper_accounts')->where('exchange_id', 'bybit')
            ->where('primary_strategy_id', 'baseline')->first();
        DB::table('paper_accounts')->where('id', $blocked->id)->update([
            'status' => 'BLOCKED', 'current_drawdown_percent' => '5', 'peak_equity' => '10526.315789473684',
        ]);
        $cleanReport = app(ExchangePaperReconciliation::class)->inspect();
        self::assertSame('LEDGER_PASS_ONLY', $cleanReport['status'], json_encode($cleanReport['issues']));
        self::assertSame(26, $cleanReport['verified_pairs']);
        self::assertContains($blocked->id, array_column($cleanReport['accounts'], 'account_id'));
        self::assertSame('BLOCKED', DB::table('paper_accounts')->where('id', $blocked->id)->value('status'));

        DB::table('paper_accounts')->where('id', $positionAccountId)->update([
            'unrealized_pnl' => '2.40825228', 'current_equity' => '10002.40825228',
        ]);
        DB::table('paper_accounts')->where('exchange_id', 'bybit')
            ->where('primary_strategy_id', 'regime_switching')->update([
                'realized_pnl' => '1', 'current_equity' => '10002',
            ]);
        DB::table('virtual_positions')->where('id', $positionId)->update([
            'status' => 'CLOSED_TP', 'unrealized_pnl' => '3',
        ]);

        $report = app(ExchangePaperReconciliation::class)->inspect();
        self::assertSame('FAIL', $report['status']);
        self::assertSame(26, $report['verified_pairs']);
        $codes = array_column($report['issues'], 'code');
        foreach (['UNREALIZED_MISMATCH', 'CLOSED_POSITION_UNREALIZED_NONZERO',
            'ACCOUNT_REALIZED_MISMATCH', 'ACCOUNT_EQUITY_MISMATCH'] as $code) {
            self::assertContains($code, $codes);
        }
        $unrealized = collect($report['issues'])->firstWhere('code', 'UNREALIZED_MISMATCH');
        self::assertSame('multi_timeframe_trend_following', $unrealized['strategy_id']);
        self::assertSame('2.408252280000', $unrealized['difference']);
        self::assertSame(0, $unrealized['active_position_count']);
        self::assertSame(1, $unrealized['closed_nonzero_unrealized_count']);
        self::assertSame('3.000000000000', $unrealized['closed_nonzero_unrealized_positions'][0]['unrealized_pnl']);
        self::assertSame($report['snapshot'], $unrealized['snapshot']);
        $realized = collect($report['issues'])->firstWhere('code', 'ACCOUNT_REALIZED_MISMATCH');
        self::assertSame('regime_switching', $realized['strategy_id']);
        self::assertNotEmpty($realized['account_id']);
        self::assertSame($report['snapshot'], $realized['snapshot']);
        self::assertArrayHasKey('active_position_unrealized_pnl', $realized);
        self::assertArrayHasKey('unrealized_difference', $realized);
        self::assertSame('BLOCKED', DB::table('paper_accounts')->where('id', $blocked->id)->value('status'));
    }

    public function test_runtime_nested_read_committed_reconciliation_fails_closed(): void
    {
        $this->createConsistentCohortWithPosition();
        DB::beginTransaction();
        app()->detectEnvironment(static fn (): string => 'production');
        try {
            app(ExchangePaperReconciliation::class)->inspect();
            self::fail('A runtime READ COMMITTED caller must not authorize M9.');
        } catch (\RuntimeException $e) {
            self::assertSame('M9 reconciliation requires a repeatable-read transaction.', $e->getMessage());
        } finally {
            app()->detectEnvironment(static fn (): string => 'testing');
            DB::rollBack();
        }
    }

    private function createConsistentCohortWithPosition(): array
    {
        app(PaperAccountService::class)->allPrimaryAccounts('bybit');
        self::assertSame('INITIALIZED_SUCCESSFULLY', app(BinanceDeploymentInitializer::class)->initialize()['status']);
        $account = DB::table('paper_accounts')->where('exchange_id', 'bybit')
            ->where('primary_strategy_id', 'multi_timeframe_trend_following')->first();
        $now = now('UTC');
        $signal = SignalRecord::query()->create([
            'id' => (string) Uuid::uuid4(), 'exchange' => 'bybit', 'category' => 'linear',
            'symbol' => 'BTCUSDT', 'timeframe' => '15', 'side' => 'BUY', 'score' => 80,
            'decision_time' => $now, 'candle_close_time' => $now,
            'expires_at' => $now->copy()->addMinutes(15), 'maximum_holding_minutes' => 240,
            'reasons' => [], 'risks' => [], 'indicator_snapshot' => [], 'contract' => [],
            'strategy_version' => '1.0.0', 'schema_version' => '1.0.0',
            'strategy_id' => 'multi_timeframe_trend_following',
        ]);
        $position = VirtualPositionRecord::query()->create([
            'id' => (string) Uuid::uuid4(), 'paper_account_id' => $account->id,
            'exchange_id' => 'bybit', 'signal_id' => $signal->id, 'symbol' => 'BTCUSDT',
            'side' => 'BUY', 'quality_class' => 'A', 'status' => 'OPEN',
            'entry_price' => '100', 'stop_loss' => '95', 'take_profit_1' => '110',
            'take_profit_2' => '115', 'initial_quantity' => '1', 'remaining_quantity' => '1',
            'qty_step' => '0.001', 'risk_capital' => '5', 'gross_pnl' => '0',
            'net_pnl' => '0', 'unrealized_pnl' => '0', 'fees' => '0', 'funding' => '0',
            'opened_at' => $now, 'maximum_holding_until' => $now->copy()->addHours(4),
            'execution_model' => 'TEST_FIXTURE', 'strategy_version' => '1.0.0',
            'risk_version' => '1.0.0', 'execution_version' => '1.0.0', 'schema_version' => '1.0.0',
        ]);
        VirtualExecutionRecord::query()->create([
            'id' => (string) Uuid::uuid4(), 'position_id' => $position->id,
            'exchange_id' => 'bybit', 'type' => 'ENTRY', 'side' => 'BUY',
            'quantity' => '1', 'price' => '100', 'gross_pnl' => '0',
            'commission' => '0', 'slippage' => '0', 'market_event_at' => $now,
            'registered_at' => $now, 'price_source' => 'TEST_FIXTURE',
            'idempotency_key' => 'test-m9-snapshot-'.$position->id,
            'execution_version' => '1.0.0', 'schema_version' => '1.0.0',
        ]);

        return [$account->id, $position->id];
    }

    private function independentWriter(): Connection
    {
        config(['database.connections.m9_writer' => config('database.connections.pgsql')]);
        DB::purge('m9_writer');

        return DB::connection('m9_writer');
    }

    private function assertIsolatedDatabase(): void
    {
        $connection = DB::connection('pgsql');
        if (! app()->environment('testing') || $connection->getDriverName() !== 'pgsql'
            || $connection->getDatabaseName() !== 'bybit_test' || $connection->getConfig('host') !== '127.0.0.1') {
            throw new \RuntimeException('M9 concurrency test requires isolated loopback PostgreSQL bybit_test.');
        }
    }
}
