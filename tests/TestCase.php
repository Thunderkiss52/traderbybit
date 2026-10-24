<?php

namespace Tests;

use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

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
        if ($db->getDriverName() !== 'sqlite' && $db->getDatabaseName() !== env('TEST_DB_DATABASE', 'bybit_test')) {
            throw new \RuntimeException('Test database identity mismatch.');
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
}
