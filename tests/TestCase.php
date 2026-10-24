<?php

namespace Tests;

use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    protected static function assertDecimalSame(string $expected, mixed $actual): void
    {
        self::assertIsString($actual);
        self::assertSame(0, BigDecimal::of($expected)->compareTo(BigDecimal::of($actual)), 'Exact decimal value differs.');
    }

    /** Tests explicitly build their own account fixtures after real migrations. */
    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(RefreshDatabase::class, class_uses_recursive(static::class), true)) {
            return;
        }
        $db = DB::connection();
        if (! app()->runningUnitTests() || $db->transactionLevel() < 1) {
            throw new \RuntimeException('Fixture reset requires a rolled-back test transaction.');
        }
        if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== env('TEST_DB_DATABASE', 'bybit_test')) {
            throw new \RuntimeException('Test database identity mismatch: strictly isolated PostgreSQL bybit_test required.');
        }
        // This is fixture setup, not migration behavior. Never remove history.
        foreach (['virtual_positions', 'virtual_executions', 'trading_journal_events', 'paper_signal_states'] as $table) {
            if ($db->table($table)->exists()) {
                throw new \RuntimeException('Expected an empty migrated test fixture.');
            }
        }
        $db->table('equity_snapshots')->delete();
        $db->table('paper_accounts')->delete();
    }

    protected function ensurePostgresTestDatabaseReady(string $requiredTable = 'paper_accounts'): void
    {
        $testPort = (int) env('TEST_DB_PORT', 25432);
        $testHost = (string) env('TEST_DB_HOST', '127.0.0.1');
        $testDb = (string) env('TEST_DB_DATABASE', 'bybit_test');
        $testUser = (string) env('TEST_DB_USERNAME', 'postgres');
        $testPass = (string) env('TEST_DB_PASSWORD', 'testpass');

        $connectionOk = @fsockopen($testHost, $testPort, $errno, $errstr, 1);
        if (! $connectionOk) {
            $this->markTestSkipped("PostgreSQL test container is not reachable on {$testHost}:{$testPort}.");
        }
        fclose($connectionOk);

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => $testHost,
            'database.connections.pgsql.port' => $testPort,
            'database.connections.pgsql.database' => $testDb,
            'database.connections.pgsql.username' => $testUser,
            'database.connections.pgsql.password' => $testPass,
        ]);
        DB::purge('pgsql');
        DB::reconnect('pgsql');

        $currentDb = DB::connection()->getDatabaseName();
        if ($currentDb !== 'bybit_test') {
            $this->fail("SAFETY ABORT: PostgreSQL test must run strictly against isolated 'bybit_test' database (current: {$currentDb}).");
        }
        if (app()->environment('production')) {
            $this->fail('SAFETY ABORT: Cannot run integration tests in production environment.');
        }

        if (! Schema::connection('pgsql')->hasTable($requiredTable)) {
            Artisan::call('migrate', ['--database' => 'pgsql', '--force' => true]);
        }
    }
}
