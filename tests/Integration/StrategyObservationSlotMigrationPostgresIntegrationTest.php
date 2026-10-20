<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class StrategyObservationSlotMigrationPostgresIntegrationTest extends TestCase
{
    private array $cleanUpIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $testPort = (int) env('TEST_DB_PORT', 25432);
        $testHost = env('TEST_DB_HOST', '127.0.0.1');
        $testDb = env('TEST_DB_DATABASE', 'bybit_test');
        $testUser = env('TEST_DB_USERNAME', 'postgres');
        $testPass = env('TEST_DB_PASSWORD', 'testpass');

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
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            if (! empty($this->cleanUpIds)) {
                DB::table('strategy_observations')->whereIn('id', $this->cleanUpIds)->delete();
            }
        }

        parent::tearDown();
    }

    public function test_migration_canonicalizes_duplicate_slots_deterministically_and_preserves_all_rows(): void
    {
        $decisionTime = CarbonImmutable::parse('2026-10-18 15:00:00', 'UTC');
        $symbol = 'BTC_TEST_MIG';

        $uuid1 = Uuid::uuid4()->toString();
        $uuid2 = Uuid::uuid4()->toString();
        $uuid3 = Uuid::uuid4()->toString();
        $uuid4 = Uuid::uuid4()->toString();

        $this->cleanUpIds = [$uuid1, $uuid2, $uuid3, $uuid4];

        // Prepare pre-migration state: drop slot unique index and ensure legacy index exists so duplicates can be simulated
        DB::statement('DROP INDEX IF EXISTS strategy_observations_slot_unique');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS strategy_observations_identity_unique ON strategy_observations (exchange, category, symbol, strategy_id, configuration_hash, decision_time)');

        // Seed 3 duplicate observations for the same slot (differing configuration_hash, side, score, created_at)
        // Row 1: SKIP, score 40, created earlier
        // Row 2: BUY (Actionable!), score 85, created middle
        // Row 3: SKIP, score 60, created later
        // Row 4: Distinct slot (symbol ETH_TEST_MIG)
        DB::table('strategy_observations')->insert([
            [
                'id' => $uuid1,
                'exchange' => 'bybit',
                'category' => 'linear',
                'symbol' => $symbol,
                'strategy_id' => 'baseline',
                'strategy_version' => '1.2.0',
                'configuration_hash' => 'hash_skip_early',
                'side' => 'SKIP',
                'quality_class' => null,
                'score' => 40,
                'decision_time' => $decisionTime,
                'reasons' => json_encode(['adx_trend_insufficient']),
                'analysis' => json_encode(['test' => 1]),
                'superseded_by' => null,
                'created_at' => CarbonImmutable::parse('2026-10-18 15:00:01', 'UTC'),
                'updated_at' => CarbonImmutable::parse('2026-10-18 15:00:01', 'UTC'),
            ],
            [
                'id' => $uuid2,
                'exchange' => 'bybit',
                'category' => 'linear',
                'symbol' => $symbol,
                'strategy_id' => 'baseline',
                'strategy_version' => '1.2.0',
                'configuration_hash' => 'hash_buy_actionable',
                'side' => 'BUY',
                'quality_class' => 'A',
                'score' => 85,
                'decision_time' => $decisionTime,
                'reasons' => json_encode(['ema_breakout_confirmed']),
                'analysis' => json_encode(['test' => 2]),
                'superseded_by' => null,
                'created_at' => CarbonImmutable::parse('2026-10-18 15:00:02', 'UTC'),
                'updated_at' => CarbonImmutable::parse('2026-10-18 15:00:02', 'UTC'),
            ],
            [
                'id' => $uuid3,
                'exchange' => 'bybit',
                'category' => 'linear',
                'symbol' => $symbol,
                'strategy_id' => 'baseline',
                'strategy_version' => '1.2.0',
                'configuration_hash' => 'hash_skip_late',
                'side' => 'SKIP',
                'quality_class' => null,
                'score' => 60,
                'decision_time' => $decisionTime,
                'reasons' => json_encode(['rsi_overbought']),
                'analysis' => json_encode(['test' => 3]),
                'superseded_by' => null,
                'created_at' => CarbonImmutable::parse('2026-10-18 15:00:03', 'UTC'),
                'updated_at' => CarbonImmutable::parse('2026-10-18 15:00:03', 'UTC'),
            ],
            [
                'id' => $uuid4,
                'exchange' => 'bybit',
                'category' => 'linear',
                'symbol' => 'ETH_TEST_MIG',
                'strategy_id' => 'baseline',
                'strategy_version' => '1.2.0',
                'configuration_hash' => 'hash_distinct_slot',
                'side' => 'BUY',
                'quality_class' => 'B',
                'score' => 70,
                'decision_time' => $decisionTime,
                'reasons' => json_encode(['level_bounce']),
                'analysis' => json_encode(['test' => 4]),
                'superseded_by' => null,
                'created_at' => CarbonImmutable::parse('2026-10-18 15:00:01', 'UTC'),
                'updated_at' => CarbonImmutable::parse('2026-10-18 15:00:01', 'UTC'),
            ],
        ]);

        self::assertSame(4, DB::table('strategy_observations')->whereIn('id', $this->cleanUpIds)->count());

        // Execute migration up()
        $migration = require database_path('migrations/2026_10_20_000000_add_slot_idempotency_to_strategy_observations.php');
        $migration->up();

        // 1. Assert row preservation: 100% of rows are preserved (zero deletions)
        $remaining = DB::table('strategy_observations')->whereIn('id', $this->cleanUpIds)->get()->keyBy('id');
        self::assertCount(4, $remaining, 'Zero rows must be deleted during migration');

        // 2. Assert deterministic canonical record selection:
        // Row 2 (BUY, actionable) must be selected as canonical over SKIP rows
        $row2 = $remaining->get($uuid2);
        self::assertNull($row2->superseded_by, 'Actionable BUY row must be selected as canonical (superseded_by IS NULL)');

        // 3. Assert superseded rows reference the exact canonical ID
        $row1 = $remaining->get($uuid1);
        $row3 = $remaining->get($uuid3);
        self::assertSame($uuid2, $row1->superseded_by, 'Row 1 must be marked superseded by canonical Row 2');
        self::assertSame($uuid2, $row3->superseded_by, 'Row 3 must be marked superseded by canonical Row 2');

        // 4. Assert distinct slot remains canonical
        $row4 = $remaining->get($uuid4);
        self::assertNull($row4->superseded_by, 'Distinct slot must remain canonical');
    }

    public function test_migration_creates_valid_unique_partial_index_verified_via_catalog(): void
    {
        $migration = require database_path('migrations/2026_10_20_000000_add_slot_idempotency_to_strategy_observations.php');
        $migration->up();

        $catalogMeta = DB::selectOne("
            SELECT i.relname AS index_name,
                   ix.indisunique AS is_unique,
                   ix.indisvalid AS is_valid,
                   pg_get_expr(ix.indpred, ix.indrelid) AS index_predicate,
                   pg_get_indexdef(ix.indexrelid) AS index_definition
            FROM pg_index ix
            JOIN pg_class i ON i.oid = ix.indexrelid
            WHERE i.relname = 'strategy_observations_slot_unique'
        ");

        self::assertNotNull($catalogMeta, "Index 'strategy_observations_slot_unique' must exist in pg_catalog");
        self::assertTrue((bool) $catalogMeta->is_unique, 'Index must be unique');
        self::assertTrue((bool) $catalogMeta->is_valid, 'Index must be valid');
        self::assertSame('(superseded_by IS NULL)', $catalogMeta->index_predicate, 'Index predicate must be (superseded_by IS NULL)');
        self::assertStringContainsString('exchange', $catalogMeta->index_definition);
        self::assertStringContainsString('category', $catalogMeta->index_definition);
        self::assertStringContainsString('symbol', $catalogMeta->index_definition);
        self::assertStringContainsString('strategy_id', $catalogMeta->index_definition);
        self::assertStringContainsString('strategy_version', $catalogMeta->index_definition);
        self::assertStringContainsString('decision_time', $catalogMeta->index_definition);
    }

    public function test_migration_is_safely_idempotent_on_repeated_runs(): void
    {
        $migration = require database_path('migrations/2026_10_20_000000_add_slot_idempotency_to_strategy_observations.php');

        // Run 1
        $migration->up();

        // Run 2 (repeated execution)
        $migration->up();

        $indexMeta = DB::selectOne("
            SELECT ix.indisunique AS is_unique, ix.indisvalid AS is_valid
            FROM pg_index ix
            JOIN pg_class i ON i.oid = ix.indexrelid
            WHERE i.relname = 'strategy_observations_slot_unique'
        ");
        self::assertTrue((bool) $indexMeta->is_valid, 'Index must remain valid after repeated migration execution');
    }

    public function test_legacy_rollback_write_path_compatibility(): void
    {
        $decisionTime = CarbonImmutable::parse('2026-10-18 16:00:00', 'UTC');
        $symbol = 'BTC_ROLLBACK_TEST';

        $uuidCanonical = Uuid::uuid4()->toString();
        $this->cleanUpIds[] = $uuidCanonical;

        // Write initial canonical observation
        DB::table('strategy_observations')->insert([
            'id' => $uuidCanonical,
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => $symbol,
            'strategy_id' => 'baseline',
            'strategy_version' => '1.2.0',
            'configuration_hash' => 'hash_primary',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 80,
            'decision_time' => $decisionTime,
            'reasons' => json_encode(['level_bounce']),
            'analysis' => json_encode(['test' => 1]),
            'superseded_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Simulate legacy application image write path (sha-94c8534...) which executes:
        // StrategyObservationRecord::query()->insertOrIgnore([[ ... ]]);
        // with a different configuration hash for the same slot
        $legacyUuid = Uuid::uuid4()->toString();
        $this->cleanUpIds[] = $legacyUuid;

        StrategyObservationRecord::query()->insertOrIgnore([[
            'id' => $legacyUuid,
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => $symbol,
            'strategy_id' => 'baseline',
            'strategy_version' => '1.2.0',
            'configuration_hash' => 'hash_legacy_conflicting',
            'side' => 'SKIP',
            'quality_class' => null,
            'score' => 20,
            'decision_time' => $decisionTime,
            'reasons' => json_encode(['skipped']),
            'analysis' => json_encode(['test' => 2]),
            'created_at' => now(),
            'updated_at' => now(),
        ]]);

        // Assert: No SQL exception occurred, and the duplicate insert was ignored by partial unique index!
        $records = DB::table('strategy_observations')->where('symbol', $symbol)->get();
        self::assertCount(1, $records, 'Legacy insertOrIgnore must be ignored without exception on slot collision');
        self::assertSame($uuidCanonical, $records->first()->id, 'Initial canonical observation must remain untouched');
    }

    public function test_negative_control_duplicate_canonical_direct_insert_rejected(): void
    {
        $decisionTime = CarbonImmutable::parse('2026-10-18 17:00:00', 'UTC');
        $symbol = 'BTC_NEG_CONTROL';

        $uuidCanonical = Uuid::uuid4()->toString();
        $this->cleanUpIds[] = $uuidCanonical;

        DB::table('strategy_observations')->insert([
            'id' => $uuidCanonical,
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => $symbol,
            'strategy_id' => 'baseline',
            'strategy_version' => '1.2.0',
            'configuration_hash' => 'hash_1',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 80,
            'decision_time' => $decisionTime,
            'reasons' => json_encode(['level_bounce']),
            'analysis' => json_encode(['test' => 1]),
            'superseded_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Attempting direct insert (without ON CONFLICT) with same slot must trigger UniqueConstraintViolationException
        $uuidDup = Uuid::uuid4()->toString();
        $this->cleanUpIds[] = $uuidDup;

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('strategy_observations')->insert([
            'id' => $uuidDup,
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => $symbol,
            'strategy_id' => 'baseline',
            'strategy_version' => '1.2.0',
            'configuration_hash' => 'hash_2_different',
            'side' => 'SKIP',
            'quality_class' => null,
            'score' => 20,
            'decision_time' => $decisionTime,
            'reasons' => json_encode(['skipped']),
            'analysis' => json_encode(['test' => 2]),
            'superseded_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
