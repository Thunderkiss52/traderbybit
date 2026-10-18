<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Research\Application\ResearchJobService;
use App\Research\Infrastructure\Persistence\ResearchJobRecord;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class GoResearchWorkerIntegrationTest extends TestCase
{
    private array $cleanupJobIds = [];

    protected function tearDown(): void
    {
        if (! empty($this->cleanupJobIds)) {
            ResearchJobRecord::query()->whereIn('job_id', $this->cleanupJobIds)->delete();
        }
        parent::tearDown();
    }

    public function test_scheduler_queue_go_worker_persists_succeeded_result(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'pgsql') {
            $this->markTestSkipped('Go research-worker requires a PostgreSQL database connection (current: '.$driver.').');
        }

        $workerBinary = base_path('bin/research-worker');
        if (! file_exists($workerBinary)) {
            // Build binary if not built yet
            $buildProcess = new Process(['go', 'build', '-o', $workerBinary, './cmd/research-worker'], base_path('research-go'));
            $buildProcess->run();
            if (! $buildProcess->isSuccessful()) {
                $this->markTestSkipped('Go compiler unavailable to build research-worker binary.');
            }
        }

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
        $env = [
            'DB_HOST' => config('database.connections.pgsql.host', '127.0.0.1'),
            'DB_PORT' => (string) config('database.connections.pgsql.port', '5432'),
            'DB_USERNAME' => config('database.connections.pgsql.username', 'postgres'),
            'DB_PASSWORD' => config('database.connections.pgsql.password', 'testpass'),
            'DB_DATABASE' => config('database.connections.pgsql.database', 'bybit_test'),
            'DB_SSLMODE' => 'disable',
        ];

        $process = new Process([$workerBinary, '-once'], base_path(), $env);
        $process->setTimeout(30);
        $process->run();

        self::assertTrue($process->isSuccessful(), 'Worker exited with failure: '.$process->getErrorOutput().' '.$process->getOutput());

        // 4. Assert job in PostgreSQL transitioned to SUCCEEDED with persisted manifest and telemetry
        $refreshed = ResearchJobRecord::query()->where('job_id', $job->job_id)->firstOrFail();
        self::assertSame('SUCCEEDED', $refreshed->status);
        self::assertSame(100, $refreshed->progress);
        self::assertNotEmpty($refreshed->result_manifest_sha256);
        self::assertIsArray($refreshed->result_payload);
        self::assertArrayHasKey('strategy_id', $refreshed->result_payload);
        self::assertArrayHasKey('win_rate', $refreshed->result_payload);
        self::assertIsArray($refreshed->runtime_stats);
        self::assertGreaterThanOrEqual(0, $refreshed->runtime_stats['duration_ms']);
        self::assertSame(50, $refreshed->runtime_stats['rows_processed']);
    }
}
