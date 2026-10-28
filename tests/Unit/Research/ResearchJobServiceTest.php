<?php

declare(strict_types=1);

namespace Tests\Unit\Research;

use App\Research\Application\ResearchJobService;
use App\Research\Infrastructure\Persistence\ResearchJobRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class ResearchJobServiceTest extends TestCase
{
    use RefreshDatabase;

    private ResearchJobService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ResearchJobService;
    }

    public function test_can_submit_valid_research_job(): void
    {
        $job = $this->service->submitJob([
            'kind' => 'BACKTEST',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'random_seed' => 42,
            'payload' => ['symbol' => 'BTCUSDT', 'timeframe' => '15m'],
        ]);

        $this->assertNotNull($job->job_id);
        $this->assertSame('BACKTEST', $job->kind);
        $this->assertSame('QUEUED', $job->status);
        $this->assertSame('trend_pullback', $job->strategy_id);
        $this->assertSame(42, $job->random_seed);
        $this->assertDatabaseHas('research_jobs', [
            'job_id' => $job->job_id,
            'status' => 'QUEUED',
        ]);
    }

    public function test_rejects_invalid_job_kind(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->submitJob([
            'kind' => 'INVALID_KIND',
        ]);
    }

    public function test_deduplicates_active_job_with_same_idempotency_key(): void
    {
        $job1 = $this->service->submitJob([
            'kind' => 'WALK_FORWARD',
            'idempotency_key' => 'dedup-test-key-123',
            'strategy_id' => 'momentum',
        ]);

        $job2 = $this->service->submitJob([
            'kind' => 'WALK_FORWARD',
            'idempotency_key' => 'dedup-test-key-123',
            'strategy_id' => 'momentum',
        ]);

        $this->assertSame($job1->job_id, $job2->job_id);
        $this->assertSame(1, ResearchJobRecord::query()->where('idempotency_key', 'dedup-test-key-123')->count());
    }

    public function test_can_claim_and_start_job(): void
    {
        $job = $this->service->submitJob([
            'kind' => 'PATTERN_MINE',
            'strategy_id' => 'support_resistance_bounce',
        ]);

        $claimed = $this->service->claimNextJob('worker-node-1', 120);
        $this->assertNotNull($claimed);
        $this->assertSame($job->job_id, $claimed->job_id);
        $this->assertSame('CLAIMED', $claimed->status);
        $this->assertSame('worker-node-1', $claimed->lease_owner);
        $this->assertSame(2, $claimed->fencing_token);

        $started = $this->service->startJob($claimed->job_id, 'worker-node-1', $claimed->fencing_token);
        $this->assertTrue($started);

        $fresh = $claimed->fresh();
        $this->assertSame('RUNNING', $fresh->status);
        $this->assertNotNull($fresh->started_at);
    }

    public function test_heartbeat_updates_progress_and_lease(): void
    {
        $job = $this->service->submitJob([
            'kind' => 'MONTE_CARLO',
        ]);

        $claimed = $this->service->claimNextJob('worker-node-1', 60);
        $this->service->startJob($claimed->job_id, 'worker-node-1', $claimed->fencing_token);

        $hb = $this->service->heartbeat($claimed->job_id, 'worker-node-1', $claimed->fencing_token, 45, 'Simulating 500 paths', 90);
        $this->assertTrue($hb);

        $fresh = $claimed->fresh();
        $this->assertSame(45, $fresh->progress);
        $this->assertSame('Simulating 500 paths', $fresh->progress_message);
    }

    public function test_complete_job_persists_manifest_and_stats(): void
    {
        $job = $this->service->submitJob([
            'kind' => 'REPLAY',
        ]);

        $claimed = $this->service->claimNextJob('worker-node-1', 60);
        $this->service->startJob($claimed->job_id, 'worker-node-1', $claimed->fencing_token);

        $resultPayload = [
            'metrics' => ['winrate' => 0.65, 'profit_factor' => 1.85],
            'trades_count' => 120,
        ];
        $runtimeStats = [
            'duration_ms' => 420,
            'memory_bytes' => 10485760,
            'rows_processed' => 1500,
        ];

        $completed = $this->service->completeJob($claimed->job_id, 'worker-node-1', $claimed->fencing_token, $resultPayload, $runtimeStats);
        $this->assertTrue($completed);

        $fresh = $claimed->fresh();
        $this->assertSame('SUCCEEDED', $fresh->status);
        $this->assertSame(100, $fresh->progress);
        $this->assertNotNull($fresh->result_manifest_sha256);
        $this->assertSame(0.65, $fresh->result_payload['metrics']['winrate']);
        $this->assertSame(420, $fresh->runtime_stats['duration_ms']);
    }

    public function test_fail_job_retries_then_fails(): void
    {
        $job = $this->service->submitJob([
            'kind' => 'BACKTEST',
            'max_retries' => 2,
        ]);

        $claimed = $this->service->claimNextJob('worker-node-1', 60);
        // First failure -> re-queues
        $this->service->failJob($claimed->job_id, 'worker-node-1', $claimed->fencing_token, 'OOM error');
        $fresh = $job->fresh();
        $this->assertSame('QUEUED', $fresh->status);
        $this->assertSame(1, $fresh->retry_count);

        // Claim again
        $claimed2 = $this->service->claimNextJob('worker-node-2', 60);
        $this->assertSame($job->job_id, $claimed2->job_id);

        // Second failure -> reaches max_retries (2) -> FAILED
        $this->service->failJob($claimed2->job_id, 'worker-node-2', $claimed2->fencing_token, 'OOM error again');
        $fresh2 = $job->fresh();
        $this->assertSame('FAILED', $fresh2->status);
        $this->assertSame(2, $fresh2->retry_count);
    }

    public function test_reconcile_stale_leases(): void
    {
        $job = $this->service->submitJob(['kind' => 'BACKTEST']);
        $claimed = $this->service->claimNextJob('crashed-worker', 10);

        // Manually expire the lease
        $claimed->update(['lease_expires_at' => CarbonImmutable::now('UTC')->subSeconds(20)]);

        $reconciled = $this->service->reconcileStaleLeases();
        $this->assertSame(1, $reconciled);

        $fresh = $job->fresh();
        $this->assertSame('QUEUED', $fresh->status);
        $this->assertNull($fresh->lease_owner);
    }

    public function test_capability_routing(): void
    {
        $routing = $this->service->getCapabilityRouting();
        $this->assertArrayHasKey('capabilities', $routing);
        $this->assertArrayHasKey('jobs', $routing);
        $this->assertSame('ready', $routing['capabilities']['go']['status']);
    }

    public function test_f21_worker_capability_map_reports_compatible_workers_and_unavailable_reasons(): void
    {
        $now = CarbonImmutable::now('UTC');

        // 1. Register supported Go worker (v1.0.0, fresh heartbeat)
        $this->service->registerWorker('go-worker-prod-1', 'go', '1.0.0', ['backtest', 'walk_forward'], $now);

        // 2. Register unsupported-version Go worker (v0.8.0 < 1.0.0 min)
        $this->service->registerWorker('go-worker-legacy', 'go', '0.8.0', ['backtest'], $now);

        // 3. Register expired-heartbeat worker (heartbeat 300 seconds ago)
        $this->service->registerWorker('python-worker-stale', 'python', '1.0.0', ['model_train'], $now->subSeconds(300));

        $routing = $this->service->getCapabilityRouting();

        $this->assertArrayHasKey('workers', $routing);
        $workers = $routing['workers'];

        // Assert supported worker is ready and available
        $this->assertSame('ready', $workers['go-worker-prod-1']['status']);
        $this->assertTrue($workers['go-worker-prod-1']['available']);
        $this->assertNull($workers['go-worker-prod-1']['unavailable_reason']);

        // Assert unsupported-version worker is rejected with reason
        $this->assertSame('unsupported_version', $workers['go-worker-legacy']['status']);
        $this->assertFalse($workers['go-worker-legacy']['available']);
        $this->assertSame('UNSUPPORTED_VERSION', $workers['go-worker-legacy']['unavailable_reason']);

        // Assert expired-heartbeat worker is offline with reason
        $this->assertSame('offline', $workers['python-worker-stale']['status']);
        $this->assertFalse($workers['python-worker-stale']['available']);
        $this->assertSame('HEARTBEAT_EXPIRED', $workers['python-worker-stale']['unavailable_reason']);

        // Engine capabilities report dynamic availability
        $this->assertTrue($routing['capabilities']['go']['available']);
        $this->assertFalse($routing['capabilities']['python']['available']);
        $this->assertSame('HEARTBEAT_EXPIRED', $routing['capabilities']['python']['unavailable_reason']);
    }
}
