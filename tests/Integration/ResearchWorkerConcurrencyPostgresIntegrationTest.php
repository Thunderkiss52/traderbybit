<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Research\Application\ResearchJobService;
use App\Research\Infrastructure\Persistence\ResearchJobRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ResearchWorkerConcurrencyPostgresIntegrationTest extends TestCase
{
    private array $cleanupWorkerIds = [];

    private array $cleanupJobIds = [];

    protected function tearDown(): void
    {
        if (! empty($this->cleanupWorkerIds)) {
            DB::table('research_workers')->whereIn('worker_id', $this->cleanupWorkerIds)->delete();
        }
        if (! empty($this->cleanupJobIds)) {
            ResearchJobRecord::query()->whereIn('job_id', $this->cleanupJobIds)->delete();
        }
        parent::tearDown();
    }

    public function test_worker_generation_token_fencing_and_zombie_rejection(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL connection required.');
        }

        $workerId = 'test-fenced-worker-'.Uuid::uuid4()->toString();
        $this->cleanupWorkerIds[] = $workerId;
        $now = CarbonImmutable::now('UTC');

        // 1. Initial registration creates generation_token = 1
        DB::statement('
            INSERT INTO research_workers (
                worker_id, engine, version, status, hostname, pid, supported_algorithms,
                heartbeat_at, registered_at, created_at, updated_at, generation_token
            ) VALUES (
                ?, \'go\', \'1.0.0\', \'ready\', \'host-1\', 101, \'["backtest"]\', ?, ?, ?, ?, 1
            )
            ON CONFLICT (worker_id) DO UPDATE SET
                generation_token = research_workers.generation_token + 1,
                status = \'ready\',
                updated_at = EXCLUDED.updated_at
        ', [$workerId, $now, $now, $now, $now]);

        $worker = DB::table('research_workers')->where('worker_id', $workerId)->first();
        self::assertNotNull($worker);
        self::assertEquals(1, $worker->generation_token);
        self::assertEquals('ready', $worker->status);

        // 2. Restarted worker registers again, incrementing generation_token to 2
        DB::statement('
            INSERT INTO research_workers (
                worker_id, engine, version, status, hostname, pid, supported_algorithms,
                heartbeat_at, registered_at, created_at, updated_at, generation_token
            ) VALUES (
                ?, \'go\', \'1.0.0\', \'ready\', \'host-1\', 102, \'["backtest"]\', ?, ?, ?, ?, 1
            )
            ON CONFLICT (worker_id) DO UPDATE SET
                generation_token = research_workers.generation_token + 1,
                status = \'ready\',
                pid = EXCLUDED.pid,
                updated_at = EXCLUDED.updated_at
        ', [$workerId, $now, $now, $now, $now]);

        $worker = DB::table('research_workers')->where('worker_id', $workerId)->first();
        self::assertEquals(2, $worker->generation_token);
        self::assertEquals(102, $worker->pid);

        // 3. Stale zombie process (generation 1) attempts to send heartbeat: 0 rows affected
        $staleHeartbeatAffected = DB::update('
            UPDATE research_workers
            SET heartbeat_at = ?, status = \'ready\', updated_at = ?
            WHERE worker_id = ? AND generation_token = ?
        ', [$now, $now, $workerId, 1]);
        self::assertEquals(0, $staleHeartbeatAffected, 'Stale generation token must be rejected with 0 affected rows');

        // 4. Stale zombie process (generation 1) attempts to record job completion: 0 rows affected
        $staleJobAffected = DB::update('
            UPDATE research_workers
            SET total_jobs_completed = total_jobs_completed + 1,
                status = \'ready\',
                updated_at = ?
            WHERE worker_id = ? AND generation_token = ?
        ', [$now, $workerId, 1]);
        self::assertEquals(0, $staleJobAffected, 'Stale generation token must not update job counters');

        // 5. Active worker (generation 2) sends heartbeat: succeeds with 1 row affected
        $activeHeartbeatAffected = DB::update('
            UPDATE research_workers
            SET heartbeat_at = ?, status = \'ready\', updated_at = ?
            WHERE worker_id = ? AND generation_token = ?
        ', [$now, $now, $workerId, 2]);
        self::assertEquals(1, $activeHeartbeatAffected, 'Active generation token must succeed');

        // 6. Active worker (generation 2) unregisters: marks status stopped
        $activeUnregisterAffected = DB::update('
            UPDATE research_workers
            SET status = \'stopped\', updated_at = ?
            WHERE worker_id = ? AND generation_token = ?
        ', [$now, $workerId, 2]);
        self::assertEquals(1, $activeUnregisterAffected);

        $worker = DB::table('research_workers')->where('worker_id', $workerId)->first();
        self::assertEquals('stopped', $worker->status);
    }

    public function test_concurrent_independent_workers_registration_and_routing(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL connection required.');
        }

        $worker1 = 'test-worker-alpha-'.Uuid::uuid4()->toString();
        $worker2 = 'test-worker-beta-'.Uuid::uuid4()->toString();
        $this->cleanupWorkerIds[] = $worker1;
        $this->cleanupWorkerIds[] = $worker2;

        $service = $this->app->make(ResearchJobService::class);

        $now = CarbonImmutable::now('UTC');
        $reg1 = $service->registerWorker($worker1, 'go', '1.0.0', ['backtest', 'walk_forward'], $now, 'host-a', 201);
        $reg2 = $service->registerWorker($worker2, 'go', '1.0.0', ['pattern_mine', 'replay'], $now, 'host-b', 202);

        self::assertEquals('ready', $reg1['status']);
        self::assertEquals('ready', $reg2['status']);

        $routing = $service->getCapabilityRouting();
        self::assertTrue($routing['capabilities']['go']['available']);
        self::assertEquals('ready', $routing['capabilities']['go']['status']);

        $workers = $routing['capabilities']['go']['workers'];
        $workerIds = array_column($workers, 'worker_id');
        self::assertContains($worker1, $workerIds);
        self::assertContains($worker2, $workerIds);
    }
}
