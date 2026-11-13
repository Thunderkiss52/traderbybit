<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\Research\Application\SignalQualitySummary;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class SignalAnalyticsSnapshotConsistencyTest extends TestCase
{
    private array $accountIds = [];

    private array $signalIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePostgresTestDatabaseReady('paper_accounts');
        $this->assertIsolatedDatabase();
        self::assertSame(0, DB::connection()->transactionLevel(), 'The independent writer needs committed fixtures.');
    }

    protected function tearDown(): void
    {
        try {
            while (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->assertIsolatedDatabase();
            // Remove only records created by this disposable fixture, never a shared cohort.
            DB::table('virtual_positions')->whereIn('paper_account_id', $this->accountIds)->delete();
            DB::table('signals')->whereIn('id', $this->signalIds)->delete();
            DB::table('paper_accounts')->whereIn('id', $this->accountIds)->delete();
            DB::disconnect('signal_analytics_writer');
        } finally {
            parent::tearDown();
        }
    }

    public function test_all_panels_use_one_statement_snapshot_inside_a_read_committed_caller(): void
    {
        [$accountId, $signalId, $positionId] = $this->fixture();
        $writer = $this->writer();
        self::assertNotSame(DB::selectOne('SELECT pg_backend_pid() AS pid')->pid, $writer->selectOne('SELECT pg_backend_pid() AS pid')->pid);
        $committed = false;
        $analyticsStatements = 0;
        DB::listen(function (QueryExecuted $query) use ($writer, $accountId, $positionId, &$committed, &$analyticsStatements): void {
            if ($query->connectionName !== DB::getDefaultConnection() || ! str_contains($query->sql, 'AS panel')) {
                return;
            }
            $analyticsStatements++;
            if ($committed) {
                return;
            }
            // Commit at the first analytics read boundary. Multiple SELECTs would let later
            // panels observe this version under READ COMMITTED; one statement cannot mix it.
            $writer->transaction(static function () use ($writer, $accountId, $positionId): void {
                $writer->table('virtual_positions')->where('id', $positionId)->update([
                    'gross_pnl' => '7.75', 'fees' => '0.499999999999', 'net_pnl' => '7.250000000001',
                ]);
                $writer->table('paper_accounts')->where('id', $accountId)->update([
                    'realized_pnl' => '7.250000000001', 'current_equity' => '10007.250000000001',
                    'peak_equity' => '10007.250000000001', 'maximum_drawdown_percent' => '2',
                ]);
            });
            $committed = true;
        });

        DB::beginTransaction();
        try {
            DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $result = (new SignalQualitySummary)->snapshot(
                SignalRecord::query()->where('id', $signalId),
                PaperAccountRecord::query()->where('id', $accountId),
            );

            self::assertTrue($committed, 'The second PostgreSQL connection must commit during the analytics call.');
            self::assertSame(1, $analyticsStatements);
            self::assertSame(1, DB::connection()->transactionLevel(), 'The caller still owns its transaction.');
            self::assertSame('read committed', DB::selectOne('SHOW transaction_isolation')->transaction_isolation);
            foreach (['position_totals', 'positions_exchange', 'positions_strategy_id', 'positions_symbol', 'positions_timeframe', 'positions_side', 'positions_quality_risk'] as $panel) {
                self::assertCount(1, $result[$panel], $panel);
                self::assertSame('3.125000000001', $result[$panel][0]['net_pnl'], $panel);
                self::assertSame(1, $result[$panel][0]['executed'], $panel);
                self::assertIsString($result[$panel][0]['fees'], $panel.' monetary values must stay decimal strings.');
            }
            self::assertSame(1, $result['signal_totals'][0]['signals']);
            self::assertSame(0, $result['invalid_positions'][0]['total']);
            self::assertSame(0, $result['invalid_risk'][0]['total']);
            self::assertSame('0.00000000', $result['drawdowns'][0]['drawdown']);

            // This later READ COMMITTED statement must see the independent commit. The
            // old values in every panel are therefore statement coherence, not stale data.
            self::assertSame('7.250000000001', DB::table('virtual_positions')->where('id', $positionId)->value('net_pnl'));
            self::assertSame('2.00000000', DB::table('paper_accounts')->where('id', $accountId)->value('maximum_drawdown_percent'));
        } finally {
            DB::rollBack();
        }
    }

    public function test_snapshot_rejects_an_oversized_group_without_returning_partial_totals(): void
    {
        [$accountId, $signalId] = $this->fixture();
        $attributes = DB::table('signals')->where('id', $signalId)->first();
        $secondId = (string) Uuid::uuid4();
        $row = (array) $attributes;
        $row['id'] = $secondId;
        $row['strategy_id'] = 'second_snapshot_strategy';
        $row['candle_close_time'] = now('UTC')->addSecond()->toDateTimeString();
        DB::table('signals')->insert($row);
        $this->signalIds[] = $secondId;
        config(['research.analytics.maximum_groups' => 1]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Analytics cohort exceeds the configured group limit');
        (new SignalQualitySummary)->snapshot(
            SignalRecord::query()->whereIn('id', [$signalId, $secondId]),
            PaperAccountRecord::query()->where('id', $accountId),
        );
    }

    private function writer(): Connection
    {
        config(['database.connections.signal_analytics_writer' => config('database.connections.pgsql')]);
        DB::purge('signal_analytics_writer');
        $writer = DB::connection('signal_analytics_writer');
        $this->assertIsolatedDatabase($writer);

        return $writer;
    }

    private function fixture(): array
    {
        $accountId = (string) Uuid::uuid4();
        $signalId = (string) Uuid::uuid4();
        $positionId = (string) Uuid::uuid4();
        $this->accountIds[] = $accountId;
        $this->signalIds[] = $signalId;
        $at = now('UTC');
        DB::table('paper_accounts')->insert([
            'id' => $accountId, 'name' => 'Isolated signal snapshot fixture', 'exchange_id' => 'bybit',
            'status' => 'BLOCKED', 'is_primary' => false, 'starting_balance' => '10000',
            'current_equity' => '10003.125000000001', 'peak_equity' => '10003.125000000001',
            'realized_pnl' => '3.125000000001', 'unrealized_pnl' => '0', 'reserved_risk' => '0',
            'created_at' => $at, 'updated_at' => $at,
        ]);
        DB::table('signals')->insert([
            'id' => $signalId, 'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT',
            'timeframe' => '15m', 'strategy_id' => 'snapshot_strategy', 'strategy_version' => '1.0.0',
            'side' => 'BUY', 'quality_class' => 'A', 'signal_quality' => 'HIGH', 'trade_risk' => 'LOW',
            'score' => 90, 'decision_time' => $at, 'candle_close_time' => $at,
            'expires_at' => $at->copy()->addMinutes(15), 'maximum_holding_minutes' => 15,
            'reasons' => '[]', 'risks' => '[]', 'indicator_snapshot' => '{}', 'contract' => '{}',
            'schema_version' => '1.0.0', 'created_at' => $at, 'updated_at' => $at,
        ]);
        DB::table('virtual_positions')->insert([
            'id' => $positionId, 'paper_account_id' => $accountId, 'signal_id' => $signalId,
            'exchange_id' => 'bybit', 'symbol' => 'BTCUSDT', 'side' => 'BUY', 'quality_class' => 'A',
            'status' => 'CLOSED_TP', 'entry_price' => '100', 'stop_loss' => '95',
            'take_profit_1' => '110', 'take_profit_2' => '115', 'initial_quantity' => '1',
            'remaining_quantity' => '0', 'qty_step' => '0.001', 'risk_capital' => '5',
            'gross_pnl' => '3.5', 'fees' => '0.374999999999', 'funding' => '0', 'net_pnl' => '3.125000000001',
            'opened_at' => $at->copy()->subHour(), 'closed_at' => $at,
            'maximum_holding_until' => $at->copy()->addHour(), 'funding_complete' => true,
            'execution_model' => 'ISOLATED_TEST_FIXTURE', 'strategy_version' => '1.0.0',
            'risk_version' => '1.0.0', 'execution_version' => '1.0.0', 'schema_version' => '1.0.0',
            'created_at' => $at, 'updated_at' => $at,
        ]);

        return [$accountId, $signalId, $positionId];
    }
}
