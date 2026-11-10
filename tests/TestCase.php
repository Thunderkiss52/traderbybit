<?php

namespace Tests;

use Brick\Math\BigDecimal;
use Illuminate\Database\Connection;
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
        if (Schema::hasTable('paper_capital_events')) {
            $db->table('paper_capital_events')->delete();
        }
        if (Schema::hasTable('risk_decisions')) {
            $db->table('risk_decisions')->delete();
        }
        $db->table('equity_snapshots')->delete();
        $db->table('paper_accounts')->delete();
    }

    protected function ensurePostgresTestDatabaseReady(string $requiredTable = 'paper_accounts'): void
    {
        $testPort = (int) (env('TEST_DB_PORT') ?: config('database.connections.pgsql.port') ?: env('DB_PORT', 25432));
        $testHost = (string) (env('TEST_DB_HOST') ?: config('database.connections.pgsql.host') ?: env('DB_HOST', '127.0.0.1'));
        $testDb = (string) (env('TEST_DB_DATABASE') ?: config('database.connections.pgsql.database') ?: env('DB_DATABASE', 'bybit_test'));
        $testUser = (string) (env('TEST_DB_USERNAME') ?: config('database.connections.pgsql.username') ?: env('DB_USERNAME', 'postgres'));
        $testPass = (string) (env('TEST_DB_PASSWORD') ?: config('database.connections.pgsql.password') ?: env('DB_PASSWORD', 'testpass'));

        $connectionOk = @fsockopen($testHost, $testPort, $errno, $errstr, 0.5);
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

        // Fail-closed read-only preflight before checking schema or running any migrations
        $this->assertIsolatedDatabase(DB::connection('pgsql'));

        if (! Schema::connection('pgsql')->hasTable($requiredTable)) {
            Artisan::call('migrate', ['--database' => 'pgsql', '--force' => true]);
        }
    }

    public function assertIsolatedDatabase(?Connection $connection = null): void
    {
        $connection ??= DB::connection('pgsql');

        // 1. Environment safety: strictly testing environment during test execution
        if (! app()->environment('testing') || ! app()->runningUnitTests()) {
            throw new \RuntimeException('Database isolation check failed: must run strictly in testing environment.');
        }
        if (app()->environment('production', 'staging', 'prod')) {
            throw new \RuntimeException('Database isolation check failed: cannot target production or staging.');
        }

        // 2. Driver check: strictly PostgreSQL
        if ($connection->getDriverName() !== 'pgsql') {
            throw new \RuntimeException("Database isolation check failed: driver must be 'pgsql', got '{$connection->getDriverName()}'.");
        }

        // 3. Configured database name check: strictly dedicated test database
        $configuredDb = (string) $connection->getDatabaseName();
        $expectedDb = (string) (env('TEST_DB_DATABASE') ?: env('DB_DATABASE', 'bybit_test'));
        if (! str_starts_with($configuredDb, 'bybit_test') || $configuredDb !== $expectedDb) {
            throw new \RuntimeException("Database isolation check failed: target database must be strictly '{$expectedDb}' (configured: '{$configuredDb}').");
        }

        // 4. Host check: local loopback or isolated test container
        $host = (string) $connection->getConfig('host');
        if (! $this->isSafeTestHost($host)) {
            throw new \RuntimeException("Database isolation check failed: host '{$host}' is not an authorized local or isolated container endpoint.");
        }

        // 5. Port check: valid positive integer
        $port = (int) $connection->getConfig('port');
        if ($port <= 0) {
            throw new \RuntimeException('Database isolation check failed: invalid or missing port.');
        }

        // 6. Dangerous URL override check
        $url = (string) $connection->getConfig('url');
        if (filled($url)) {
            $parsed = parse_url($url);
            if (! is_array($parsed)
                || ($parsed['scheme'] ?? '') !== 'pgsql'
                || ! str_starts_with(ltrim($parsed['path'] ?? '', '/'), 'bybit_test')
                || ! $this->isSafeTestHost($parsed['host'] ?? '')) {
                throw new \RuntimeException('Database isolation check failed: dangerous database URL override detected.');
            }
        }

        // 7. Live server-side query verification: verify current_database() and current_user
        try {
            $identity = $connection->selectOne('SELECT current_database() AS database_name, current_user AS database_user');
        } catch (\Throwable $e) {
            throw new \RuntimeException('Database isolation check failed: cannot query PostgreSQL server identity: '.$e->getMessage(), 0, $e);
        }

        if ($identity === null || ! str_starts_with($identity->database_name, 'bybit_test') || $identity->database_name !== $expectedDb) {
            throw new \RuntimeException("Database isolation check failed: server current_database() is '".($identity?->database_name ?? 'null')."', strictly '{$expectedDb}' required.");
        }

        $expectedUser = (string) $connection->getConfig('username');
        if ($expectedUser !== '' && $identity->database_user !== $expectedUser) {
            throw new \RuntimeException("Database isolation check failed: server current_user is '{$identity->database_user}', expected '{$expectedUser}'.");
        }
    }

    public function isSafeTestHost(string $host): bool
    {
        if ($host === '' || $host === '127.0.0.1' || $host === 'localhost' || $host === '::1') {
            return true;
        }

        // Isolated Docker container service names commonly used in CI/Compose
        if (in_array(strtolower($host), ['postgres', 'pgsql', 'db', 'vector-postgres', 'bybit-signals-postgres', 'vector-ci-opt-pg-20261031'], true)) {
            return true;
        }

        // Private container bridge networks (e.g. 172.16.0.0/12, 10.0.0.0/8, 192.168.0.0/16)
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            && filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return false;
    }
}
