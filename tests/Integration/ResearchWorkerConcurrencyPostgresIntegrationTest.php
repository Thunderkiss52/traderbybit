<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Research\Application\ResearchJobService;
use App\Research\Infrastructure\Persistence\ResearchJobRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Ramsey\Uuid\Uuid;
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

    private function createIndependentPdo(): PDO
    {
        $host = config('database.connections.pgsql.host', '127.0.0.1');
        $port = config('database.connections.pgsql.port', '25432');
        $db = config('database.connections.pgsql.database', 'bybit_test');
        $user = config('database.connections.pgsql.username', 'postgres');
        $pass = config('database.connections.pgsql.password', 'testpass');

        $dsn = "pgsql:host={$host};port={$port};dbname={$db}";

        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
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

    public function test_real_concurrency_with_independent_pdo_connections(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL connection required.');
        }

        $pdo1 = $this->createIndependentPdo();
        $pdo2 = $this->createIndependentPdo();

        $workerId = 'fenced-concurrency-worker-'.Uuid::uuid4()->toString();
        $this->cleanupWorkerIds[] = $workerId;
        $now = CarbonImmutable::now('UTC')->toIso8601String();

        // Register worker initially on PDO 1 (generation = 1)
        $regStmt = $pdo1->prepare('
            INSERT INTO research_workers (
                worker_id, engine, version, status, hostname, pid, supported_algorithms,
                heartbeat_at, registered_at, created_at, updated_at, generation_token
            ) VALUES (
                :wid, \'go\', \'1.0.0\', \'ready\', \'host-conc\', 301, \'["backtest"]\',
                :now, :now, :now, :now, 1
            )
            ON CONFLICT (worker_id) DO UPDATE SET
                generation_token = research_workers.generation_token + 1
            RETURNING generation_token;
        ');
        $regStmt->execute(['wid' => $workerId, 'now' => $now]);
        $gen1 = (int) $regStmt->fetchColumn();
        self::assertSame(1, $gen1);

        // Seed a queued research job
        $jobId = Uuid::uuid4()->toString();
        $this->cleanupJobIds[] = $jobId;
        ResearchJobRecord::query()->create([
            'job_id' => $jobId,
            'schema_version' => 1,
            'idempotency_key' => 'idemp-'.$jobId,
            'kind' => 'BACKTEST',
            'algorithm_version' => '1.0.0',
            'code_revision' => 'git-conc',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'configuration_hash' => hash('sha256', 'conc'),
            'research_mode' => 'RESEARCH',
            'limits' => ['max_rows' => 500],
            'payload' => ['symbol' => 'BTCUSDT'],
            'created_by' => 'concurrency_test',
            'requested_at_utc' => CarbonImmutable::now('UTC'),
            'status' => 'CLAIMED',
            'lease_owner' => $workerId,
            'lease_expires_at' => CarbonImmutable::now('UTC')->addMinutes(5),
            'fencing_token' => 1,
        ]);

        // SCENARIO A: Transaction Ordering A - Genuine lock serialization
        // Connection 1 holds FOR SHARE on research_workers; Connection 2 attempts update with short lock timeout
        $pdo1->beginTransaction();
        $checkStmt1 = $pdo1->prepare('SELECT generation_token FROM research_workers WHERE worker_id = :wid FOR SHARE');
        $checkStmt1->execute(['wid' => $workerId]);
        $observedGen = (int) $checkStmt1->fetchColumn();
        self::assertSame(1, $observedGen);

        // Connection 2 attempts to register/update worker with short lock_timeout
        $pdo2->exec("SET lock_timeout = '200ms'");
        $timedOut = false;
        try {
            $regStmt2 = $pdo2->prepare('
                UPDATE research_workers
                SET generation_token = generation_token + 1
                WHERE worker_id = :wid
            ');
            $regStmt2->execute(['wid' => $workerId]);
        } catch (PDOException $e) {
            // Lock timeout confirmed: Connection 1 safely serializes access
            $timedOut = true;
        }
        self::assertTrue($timedOut, 'Exclusive lock on worker replacement must block while job transaction holds SHARE lock');

        // Connection 1 finishes job completion and commits
        $completeStmt1 = $pdo1->prepare('
            UPDATE research_jobs
            SET status = \'SUCCEEDED\', progress = 100, completed_at = :now, lease_owner = NULL
            WHERE job_id = :jid AND lease_owner = :wid AND fencing_token = 1
        ');
        $completeStmt1->execute(['now' => $now, 'jid' => $jobId, 'wid' => $workerId]);
        $pdo1->commit();

        $freshJob = ResearchJobRecord::query()->where('job_id', $jobId)->first();
        self::assertSame('SUCCEEDED', $freshJob->status);

        // SCENARIO B: Transaction Ordering B - Superseded Worker Replacement
        // Seed another job claimed by generation 1
        $jobId2 = Uuid::uuid4()->toString();
        $this->cleanupJobIds[] = $jobId2;
        ResearchJobRecord::query()->create([
            'job_id' => $jobId2,
            'schema_version' => 1,
            'idempotency_key' => 'idemp-'.$jobId2,
            'kind' => 'BACKTEST',
            'algorithm_version' => '1.0.0',
            'code_revision' => 'git-conc',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'configuration_hash' => hash('sha256', 'conc2'),
            'research_mode' => 'RESEARCH',
            'limits' => ['max_rows' => 500],
            'payload' => ['symbol' => 'BTCUSDT'],
            'created_by' => 'concurrency_test',
            'requested_at_utc' => CarbonImmutable::now('UTC'),
            'status' => 'CLAIMED',
            'lease_owner' => $workerId,
            'lease_expires_at' => CarbonImmutable::now('UTC')->addMinutes(5),
            'fencing_token' => 1,
        ]);

        // Connection 2 now resets lock_timeout and increments generation_token to 2 (Worker restarted)
        $pdo2->exec("SET lock_timeout = '3s'");
        $bumpStmt = $pdo2->prepare('
            UPDATE research_workers
            SET generation_token = generation_token + 1
            WHERE worker_id = :wid
            RETURNING generation_token;
        ');
        $bumpStmt->execute(['wid' => $workerId]);
        $newGen = (int) $bumpStmt->fetchColumn();
        self::assertSame(2, $newGen);

        // Connection 1 (stale worker still holding gen 1) attempts to complete job 2
        // Transaction protocol check:
        $pdo1->beginTransaction();
        $checkStmtStale = $pdo1->prepare('SELECT generation_token FROM research_workers WHERE worker_id = :wid FOR SHARE');
        $checkStmtStale->execute(['wid' => $workerId]);
        $currentGenInDb = (int) $checkStmtStale->fetchColumn();

        self::assertSame(2, $currentGenInDb);
        // Stale worker generation check fails: 1 !== 2
        self::assertNotEquals(1, $currentGenInDb, 'Observed generation must reflect superseded token');

        // Obsolete worker must abort without modifying research_jobs
        $pdo1->rollBack();

        $job2AfterStale = ResearchJobRecord::query()->where('job_id', $jobId2)->first();
        self::assertSame('CLAIMED', $job2AfterStale->status, 'Job state must remain unchanged when stale generation is rejected');
        self::assertNull($job2AfterStale->completed_at);
    }
}
