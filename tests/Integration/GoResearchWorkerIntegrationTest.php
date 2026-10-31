<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Research\Application\ResearchJobService;
use App\Research\Infrastructure\Persistence\ResearchJobRecord;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class GoResearchWorkerIntegrationTest extends TestCase
{
    private array $cleanupJobIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedDatabase(DB::connection('pgsql'));
    }

    protected function tearDown(): void
    {
        if (! empty($this->cleanupJobIds)) {
            ResearchJobRecord::query()->whereIn('job_id', $this->cleanupJobIds)->delete();
        }
        parent::tearDown();
    }

    private function ensureWorkerBinary(): string
    {
        $workerBinary = base_path('bin/research-worker');
        $sourceDir = base_path('research-go');

        $needsBuild = ! file_exists($workerBinary);
        if (! $needsBuild) {
            $binMtime = filemtime($workerBinary);
            $finder = new Finder;
            $finder->files()->in($sourceDir)->name('*.go');
            foreach ($finder as $file) {
                if ($file->getMTime() > $binMtime) {
                    $needsBuild = true;
                    break;
                }
            }
        }

        if ($needsBuild) {
            $buildProcess = new Process(['go', 'build', '-o', $workerBinary, './cmd/research-worker'], $sourceDir);
            $buildProcess->run();
            if (! $buildProcess->isSuccessful()) {
                $this->markTestSkipped('Go compiler unavailable to build research-worker binary: '.$buildProcess->getErrorOutput());
            }
        }

        return $workerBinary;
    }

    private function workerEnv(): array
    {
        return [
            'DB_HOST' => (string) config('database.connections.pgsql.host', '127.0.0.1'),
            'DB_PORT' => (string) config('database.connections.pgsql.port', '25432'),
            'DB_USERNAME' => (string) config('database.connections.pgsql.username', 'postgres'),
            'DB_PASSWORD' => (string) config('database.connections.pgsql.password', 'testpass'),
            'DB_DATABASE' => (string) config('database.connections.pgsql.database', 'bybit_test'),
            'DB_SSLMODE' => 'disable',
        ];
    }

    public function test_scheduler_queue_go_worker_persists_succeeded_result(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'pgsql') {
            $this->markTestSkipped('Go research-worker requires a PostgreSQL database connection (current: '.$driver.').');
        }

        $workerBinary = $this->ensureWorkerBinary();

        // 1. Seed closed candles into market_candles table
        $baseTime = new DateTimeImmutable('2026-10-01T00:00:00Z');
        for ($i = 0; $i < 50; $i++) {
            $time = $baseTime->modify('+'.($i * 5).' minutes');
            DB::table('market_candles')->insertOrIgnore([
                'exchange' => 'bybit',
                'category' => 'linear',
                'symbol' => 'BTCUSDT',
                'interval' => '5',
                'open_time' => $time,
                'open' => 50000 + $i * 10,
                'high' => 50050 + $i * 10,
                'low' => 49950 + $i * 10,
                'close' => 50020 + $i * 10,
                'volume' => 100,
                'turnover' => 5000000,
                'is_closed' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 2. Submit backtest research job via ResearchJobService
        $service = $this->app->make(ResearchJobService::class);
        $job = $service->submitJob([
            'job_id' => Uuid::uuid4()->toString(),
            'schema_version' => 1,
            'idempotency_key' => 'idemp-worker-test-'.Uuid::uuid4()->toString(),
            'kind' => 'BACKTEST',
            'algorithm_version' => '1.0.0',
            'code_revision' => 'git-v4',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'configuration_hash' => hash('sha256', 'worker_test'),
            'research_mode' => 'RESEARCH',
            'limits' => ['max_rows' => 500],
            'payload' => [
                'exchange' => 'bybit',
                'symbol' => 'BTCUSDT',
                'interval' => '5',
                'category' => 'linear',
                'from' => '2026-10-01T00:00:00Z',
                'to' => '2026-10-02T00:00:00Z',
            ],
            'created_by' => 'integration_test',
        ]);
        $this->cleanupJobIds[] = $job->job_id;

        self::assertSame('QUEUED', $job->status);

        // 3. Execute Go worker in -once mode
        $startTime = microtime(true);
        $process = new Process([$workerBinary, '-once'], base_path(), $this->workerEnv());
        $process->setTimeout(30);
        $process->run();

        self::assertTrue(
            $process->isSuccessful(),
            sprintf(
                "Worker exited with failure code %d.\nStderr:\n%s\nStdout:\n%s",
                $process->getExitCode() ?? -1,
                $process->getErrorOutput(),
                $process->getOutput()
            )
        );

        // 4. Assert job in PostgreSQL transitioned to SUCCEEDED with bounded condition polling
        $refreshed = null;
        $pollDeadline = microtime(true) + 10.0;
        do {
            $refreshed = ResearchJobRecord::query()->where('job_id', $job->job_id)->first();
            if ($refreshed !== null && $refreshed->status === 'SUCCEEDED') {
                break;
            }
            usleep(50_000);
        } while (microtime(true) < $pollDeadline);

        self::assertNotNull($refreshed, 'Research job record should exist in database.');
        self::assertSame(
            'SUCCEEDED',
            $refreshed->status,
            sprintf(
                "Go research-worker failed to transition job %s to SUCCEEDED.\nExit Code: %d\nElapsed: %.2fs\nJob Status: %s\nProgress: %s\nSanitized Error: %s\nStdout:\n%s\nStderr:\n%s",
                $job->job_id,
                $process->getExitCode() ?? -1,
                microtime(true) - $startTime,
                $refreshed->status,
                (string) ($refreshed->progress ?? 'null'),
                $refreshed->sanitized_error ?? 'none',
                $process->getOutput(),
                $process->getErrorOutput()
            )
        );
        self::assertSame(100, $refreshed->progress);
        self::assertNotEmpty($refreshed->result_manifest_sha256);
        self::assertIsArray($refreshed->result_payload);
        self::assertArrayHasKey('strategy_id', $refreshed->result_payload);
        self::assertArrayHasKey('win_rate', $refreshed->result_payload);
        self::assertIsArray($refreshed->runtime_stats);
        self::assertGreaterThanOrEqual(0, $refreshed->runtime_stats['duration_ms']);
        self::assertSame(50, $refreshed->runtime_stats['rows_processed']);

        // 5. Assert Go worker registered in PostgreSQL research_workers table with heartbeat and completion telemetry
        $workerRow = DB::table('research_workers')
            ->where('engine', 'go')
            ->where('last_job_id', $job->job_id)
            ->first();
        self::assertNotNull($workerRow, 'Go worker should register in research_workers table and record executed job');
        self::assertSame('go', $workerRow->engine);
        self::assertSame('1.0.0', $workerRow->version);
        self::assertGreaterThanOrEqual(1, $workerRow->total_jobs_completed);
        self::assertNotNull($workerRow->heartbeat_at);
        self::assertNotNull($workerRow->last_job_finished_at);

        // 6. Assert ResearchJobService dynamically routes to live Go worker
        $routing = $service->getCapabilityRouting();
        self::assertArrayHasKey('capabilities', $routing);
        self::assertArrayHasKey('go', $routing['capabilities']);
        self::assertTrue($routing['capabilities']['go']['available']);
        self::assertSame('ready', $routing['capabilities']['go']['status']);
        self::assertNotEmpty($routing['capabilities']['go']['workers']);

        $workerEntry = $routing['workers'][$workerRow->worker_id] ?? null;
        self::assertNotNull($workerEntry);
        self::assertTrue($workerEntry['available']);
        self::assertTrue($workerEntry['is_heartbeat_fresh']);
        self::assertSame('ready', $workerEntry['status']);
    }

    public function test_go_worker_persists_failure_and_sanitized_error_when_job_invalid(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'pgsql') {
            $this->markTestSkipped('Go research-worker requires a PostgreSQL database connection (current: '.$driver.').');
        }

        $workerBinary = $this->ensureWorkerBinary();

        // Direct DB insert simulating an already-queued raw job with unsupported exchange
        $jobId = Uuid::uuid4()->toString();
        $this->cleanupJobIds[] = $jobId;
        DB::table('research_jobs')->insert([
            'job_id' => $jobId,
            'schema_version' => 1,
            'idempotency_key' => 'idemp-worker-fail-'.Uuid::uuid4()->toString(),
            'kind' => 'BACKTEST',
            'status' => 'QUEUED',
            'algorithm_version' => '1.0.0',
            'code_revision' => 'git-v4',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'configuration_hash' => hash('sha256', 'worker_fail_test'),
            'research_mode' => 'RESEARCH',
            'max_retries' => 1,
            'retry_count' => 0,
            'progress' => 0,
            'fencing_token' => 1,
            'limits' => json_encode(['max_rows' => 100]),
            'payload' => json_encode([
                'exchange' => 'unsupported_exchange',
                'symbol' => 'BTCUSDT',
            ]),
            'created_by' => 'integration_test_failure',
            'requested_at_utc' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        // Execute Go worker in -once mode; invalid exchange must trigger FailJob
        $process = new Process([$workerBinary, '-once'], base_path(), $this->workerEnv());
        $process->setTimeout(30);
        $process->run();

        // Worker must exit with non-zero failure in -once mode when job execution fails
        self::assertFalse($process->isSuccessful(), 'Worker should exit non-zero when executing an invalid job in -once mode.');
        self::assertSame(1, $process->getExitCode());

        // Bounded condition polling for terminal FAILED state in PostgreSQL
        $refreshed = null;
        $pollDeadline = microtime(true) + 10.0;
        do {
            $refreshed = ResearchJobRecord::query()->where('job_id', $jobId)->first();
            if ($refreshed !== null && $refreshed->status === 'FAILED') {
                break;
            }
            usleep(50_000);
        } while (microtime(true) < $pollDeadline);

        self::assertNotNull($refreshed);
        self::assertSame('FAILED', $refreshed->status);
        self::assertNotNull($refreshed->completed_at, 'completed_at timestamp must be set when retries are exhausted without SQL type errors');
        self::assertStringContainsString('research job exchange is missing or unsupported', (string) $refreshed->sanitized_error);
        self::assertSame(1, $refreshed->retry_count);
        self::assertNull($refreshed->lease_owner);

        // Assert worker registered failed job in telemetry
        $workerRow = DB::table('research_workers')
            ->where('engine', 'go')
            ->where('last_job_id', $jobId)
            ->first();
        self::assertNotNull($workerRow);
        self::assertGreaterThanOrEqual(1, $workerRow->total_jobs_failed);
    }

    public function test_go_worker_requeues_job_when_retries_remain(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'pgsql') {
            $this->markTestSkipped('Go research-worker requires a PostgreSQL database connection (current: '.$driver.').');
        }

        $workerBinary = $this->ensureWorkerBinary();

        // Direct DB insert simulating an already-queued raw job with unsupported exchange and max_retries = 3
        $jobId = Uuid::uuid4()->toString();
        $this->cleanupJobIds[] = $jobId;
        DB::table('research_jobs')->insert([
            'job_id' => $jobId,
            'schema_version' => 1,
            'idempotency_key' => 'idemp-worker-retry-'.Uuid::uuid4()->toString(),
            'kind' => 'BACKTEST',
            'status' => 'QUEUED',
            'algorithm_version' => '1.0.0',
            'code_revision' => 'git-v4',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'configuration_hash' => hash('sha256', 'worker_retry_test'),
            'research_mode' => 'RESEARCH',
            'max_retries' => 3,
            'retry_count' => 0,
            'progress' => 0,
            'fencing_token' => 1,
            'limits' => json_encode(['max_rows' => 100]),
            'payload' => json_encode([
                'exchange' => 'unsupported_exchange',
                'symbol' => 'BTCUSDT',
            ]),
            'created_by' => 'integration_test_retry',
            'requested_at_utc' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        $process = new Process([$workerBinary, '-once'], base_path(), $this->workerEnv());
        $process->setTimeout(30);
        $process->run();

        self::assertFalse($process->isSuccessful(), 'Worker should exit non-zero when job fails in -once mode.');

        // Bounded condition polling for QUEUED retry state in PostgreSQL
        $refreshed = null;
        $pollDeadline = microtime(true) + 10.0;
        do {
            $refreshed = ResearchJobRecord::query()->where('job_id', $jobId)->first();
            if ($refreshed !== null && $refreshed->status === 'QUEUED' && $refreshed->retry_count === 1) {
                break;
            }
            usleep(50_000);
        } while (microtime(true) < $pollDeadline);

        self::assertNotNull($refreshed);
        self::assertSame('QUEUED', $refreshed->status);
        self::assertNull($refreshed->completed_at, 'completed_at must remain NULL while retries remain');
        self::assertSame(1, $refreshed->retry_count);
        self::assertNull($refreshed->lease_owner);
        self::assertStringContainsString('research job exchange is missing or unsupported', (string) $refreshed->sanitized_error);
    }

    public function test_worker_lifecycle_e2e_superseded_worker_rejected_and_cannot_persist_result(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'pgsql') {
            $this->markTestSkipped('Go research-worker requires a PostgreSQL database connection (current: '.$driver.').');
        }

        $service = $this->app->make(ResearchJobService::class);
        $workerId = 'go-worker-lifecycle-'.Uuid::uuid4()->toString();
        $now = CarbonImmutable::now('UTC');

        // 1. Worker A registers initially (generation 1)
        DB::statement('
            INSERT INTO research_workers (
                worker_id, engine, version, status, hostname, pid, supported_algorithms,
                heartbeat_at, registered_at, created_at, updated_at, generation_token
            ) VALUES (
                ?, \'go\', \'1.0.0\', \'ready\', \'host-e2e\', 1001, \'["backtest"]\', ?, ?, ?, ?, 1
            )
            ON CONFLICT (worker_id) DO UPDATE SET
                generation_token = research_workers.generation_token + 1,
                status = \'ready\',
                updated_at = EXCLUDED.updated_at
        ', [$workerId, $now, $now, $now, $now]);

        $workerA = DB::table('research_workers')->where('worker_id', $workerId)->first();
        self::assertEquals(1, $workerA->generation_token);
        self::assertEquals('ready', $workerA->status);

        // 2. Submit a research job and claim it for Worker A (generation 1)
        $job = $service->submitJob([
            'job_id' => Uuid::uuid4()->toString(),
            'schema_version' => 1,
            'idempotency_key' => 'idemp-lifecycle-'.Uuid::uuid4()->toString(),
            'kind' => 'BACKTEST',
            'algorithm_version' => '1.0.0',
            'code_revision' => 'git-v4',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'configuration_hash' => hash('sha256', 'lifecycle_test'),
            'research_mode' => 'RESEARCH',
            'limits' => ['max_rows' => 100],
            'payload' => [
                'exchange' => 'bybit',
                'symbol' => 'BTCUSDT',
            ],
            'created_by' => 'lifecycle_integration_test',
        ]);
        $this->cleanupJobIds[] = $job->job_id;

        // Transition job to CLAIMED by worker A
        DB::table('research_jobs')->where('job_id', $job->job_id)->update([
            'status' => 'CLAIMED',
            'lease_owner' => $workerId,
            'lease_expires_at' => now()->addMinutes(5),
            'fencing_token' => 1,
        ]);

        // 3. Worker B supersedes Worker A by re-registering with the SAME worker ID
        DB::statement('
            INSERT INTO research_workers (
                worker_id, engine, version, status, hostname, pid, supported_algorithms,
                heartbeat_at, registered_at, created_at, updated_at, generation_token
            ) VALUES (
                ?, \'go\', \'1.0.0\', \'ready\', \'host-e2e\', 1002, \'["backtest"]\', ?, ?, ?, ?, 1
            )
            ON CONFLICT (worker_id) DO UPDATE SET
                generation_token = research_workers.generation_token + 1,
                status = \'ready\',
                updated_at = EXCLUDED.updated_at
        ', [$workerId, $now, $now, $now, $now]);

        $workerB = DB::table('research_workers')->where('worker_id', $workerId)->first();
        self::assertEquals(2, $workerB->generation_token, 'Re-registering same worker ID must increment generation token to 2');

        // 4. Worker A (stale generation = 1) attempts job mutations under transactional generation fencing:
        $host = config('database.connections.pgsql.host', '127.0.0.1');
        $port = config('database.connections.pgsql.port', '25432');
        $db = config('database.connections.pgsql.database', 'bybit_test');
        $user = config('database.connections.pgsql.username', 'postgres');
        $pass = config('database.connections.pgsql.password', 'testpass');

        $pdoA = new \PDO("pgsql:host={$host};port={$port};dbname={$db}", $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        // Worker A executes the exact protocol:
        // Transaction: SELECT generation_token FROM research_workers WHERE worker_id = :wid FOR SHARE
        $pdoA->beginTransaction();
        $checkStmtA = $pdoA->prepare('SELECT generation_token FROM research_workers WHERE worker_id = :wid FOR SHARE');
        $checkStmtA->execute(['wid' => $workerId]);
        $observedGenA = (int) $checkStmtA->fetchColumn();

        self::assertSame(2, $observedGenA, 'Observed generation must be 2');
        self::assertNotEquals(1, $observedGenA, 'Worker A generation 1 is superseded by 2');

        // Worker A aborts because observedGenA != workerA_expected_generation (1 != 2)
        $pdoA->rollBack();

        // 5. Assert job state in PostgreSQL remains strictly untouched
        $jobUntouched = ResearchJobRecord::query()->where('job_id', $job->job_id)->firstOrFail();
        self::assertSame('CLAIMED', $jobUntouched->status);
        self::assertNull($jobUntouched->completed_at);
        self::assertNull($jobUntouched->result_manifest_sha256);

        // 6. Worker B (active generation = 2) executes the transaction successfully
        $pdoB = new \PDO("pgsql:host={$host};port={$port};dbname={$db}", $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        $pdoB->beginTransaction();
        $checkStmtB = $pdoB->prepare('SELECT generation_token FROM research_workers WHERE worker_id = :wid FOR SHARE');
        $checkStmtB->execute(['wid' => $workerId]);
        $observedGenB = (int) $checkStmtB->fetchColumn();

        self::assertSame(2, $observedGenB, 'Worker B generation matches database generation');

        // Complete job under worker B
        $completeStmtB = $pdoB->prepare('
            UPDATE research_jobs
            SET status = \'SUCCEEDED\', progress = 100, completed_at = NOW(),
                result_manifest_sha256 = :manifest, result_payload = \'{"win_rate": 0.7}\',
                lease_owner = NULL, lease_expires_at = NULL
            WHERE job_id = :jid AND lease_owner = :wid AND fencing_token = 1
        ');
        $manifestSha = hash('sha256', 'valid-e2e-result');
        $completeStmtB->execute([
            'manifest' => $manifestSha,
            'jid' => $job->job_id,
            'wid' => $workerId,
        ]);
        $pdoB->commit();

        // 7. Assert job is now SUCCEEDED with authoritative result
        $jobFinal = ResearchJobRecord::query()->where('job_id', $job->job_id)->firstOrFail();
        self::assertSame('SUCCEEDED', $jobFinal->status);
        self::assertNotNull($jobFinal->completed_at);
        self::assertSame($manifestSha, $jobFinal->result_manifest_sha256);

        // Cleanup worker
        DB::table('research_workers')->where('worker_id', $workerId)->delete();
    }
}
