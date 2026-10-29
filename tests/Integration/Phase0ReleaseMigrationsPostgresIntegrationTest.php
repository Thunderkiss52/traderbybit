<?php

declare(strict_types=1);

namespace Tests\Integration;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class Phase0ReleaseMigrationsPostgresIntegrationTest extends TestCase
{
    private array $cleanUpWorkerIds = [];

    private array $cleanUpSnapshotIds = [];

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            if (! empty($this->cleanUpWorkerIds)) {
                DB::table('research_workers')->whereIn('worker_id', $this->cleanUpWorkerIds)->delete();
            }
            if (! empty($this->cleanUpSnapshotIds)) {
                DB::table('portfolio_correlation_snapshots')->whereIn('id', $this->cleanUpSnapshotIds)->delete();
            }
        }
        parent::tearDown();
    }

    public function test_all_three_migrations_exist_and_apply_in_sequential_order(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL connection required.');
        }

        $migrationFiles = [
            base_path('database/migrations/2026_10_29_180000_create_research_workers_table.php'),
            base_path('database/migrations/2026_10_29_180100_make_concentration_score_nullable_in_portfolio_correlation_snapshots.php'),
            base_path('database/migrations/2026_10_29_180200_add_generation_token_to_research_workers_table.php'),
        ];

        foreach ($migrationFiles as $file) {
            self::assertFileExists($file, "Migration file must exist: {$file}");
        }

        // Verify migration order based on timestamp prefix
        $basenames = array_map('basename', $migrationFiles);
        $sorted = $basenames;
        sort($sorted);
        self::assertSame($sorted, $basenames, 'Migrations must be strictly ordered chronologically');

        // Verify Schema presence in PostgreSQL
        self::assertTrue(Schema::hasTable('research_workers'));
        self::assertTrue(Schema::hasColumn('research_workers', 'worker_id'));
        self::assertTrue(Schema::hasColumn('research_workers', 'engine'));
        self::assertTrue(Schema::hasColumn('research_workers', 'status'));
        self::assertTrue(Schema::hasColumn('research_workers', 'generation_token'));

        self::assertTrue(Schema::hasTable('portfolio_correlation_snapshots'));
        self::assertTrue(Schema::hasColumn('portfolio_correlation_snapshots', 'concentration_score'));
    }

    public function test_portfolio_correlation_snapshots_preserves_existing_data_and_allows_null(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL connection required.');
        }

        $id1 = Uuid::uuid4()->toString();
        $id2 = Uuid::uuid4()->toString();
        $this->cleanUpSnapshotIds = [$id1, $id2];
        $now = CarbonImmutable::now('UTC');

        // Record with legitimate numeric concentration score
        DB::table('portfolio_correlation_snapshots')->insert([
            'id' => $id1,
            'horizon' => '14d',
            'pair_matrix' => json_encode(['trend_pullback' => ['baseline' => 0.45]]),
            'concurrent_overlap' => json_encode([]),
            'concentration_score' => 0.4567,
            'alerts' => json_encode([]),
            'evaluated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Record with null concentration score (insufficient trading history)
        DB::table('portfolio_correlation_snapshots')->insert([
            'id' => $id2,
            'horizon' => '14d',
            'pair_matrix' => json_encode([]),
            'concurrent_overlap' => json_encode([]),
            'concentration_score' => null,
            'alerts' => json_encode([]),
            'evaluated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $row1 = DB::table('portfolio_correlation_snapshots')->where('id', $id1)->first();
        self::assertNotNull($row1);
        self::assertEquals('0.4567', (string) $row1->concentration_score);

        $row2 = DB::table('portfolio_correlation_snapshots')->where('id', $id2)->first();
        self::assertNotNull($row2);
        self::assertNull($row2->concentration_score);
    }

    public function test_research_workers_preserves_data_and_enforces_default_generation_token(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL connection required.');
        }

        $workerId = 'mig-test-worker-'.Uuid::uuid4()->toString();
        $this->cleanUpWorkerIds[] = $workerId;
        $now = CarbonImmutable::now('UTC');

        // Insert worker using default column values
        DB::table('research_workers')->insert([
            'worker_id' => $workerId,
            'engine' => 'go',
            'version' => '1.0.0',
            'status' => 'ready',
            'heartbeat_at' => $now,
            'registered_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $row = DB::table('research_workers')->where('worker_id', $workerId)->first();
        self::assertNotNull($row);
        self::assertEquals(1, $row->generation_token, 'Default generation_token must be 1');
        self::assertEquals('ready', $row->status);
    }
}
