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
            'payload' => ['exchange' => 'bybit', 'symbol' => 'BTCUSDT', 'timeframe' => '15m'],
        ]);

        $this->assertNotNull($job->job_id);
        $this->assertSame('BACKTEST', $job->kind);
        $this->assertSame('QUEUED', $job->status);
        $this->assertSame('trend_pullback', $job->strategy_id);
        $this->assertSame(42, $job->random_seed);
        $this->assertSame('bybit', $job->payload['exchange']);
        $this->assertSame('bybit', $job->payload['exchange_id']);
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
            'payload' => ['exchange' => 'bybit'],
        ]);
    }

    public function test_deduplicates_active_job_with_same_idempotency_key(): void
    {
        $job1 = $this->service->submitJob([
            'kind' => 'WALK_FORWARD',
            'idempotency_key' => 'dedup-test-key-123',
            'strategy_id' => 'momentum',
            'payload' => ['exchange' => 'bybit'],
        ]);

        $job2 = $this->service->submitJob([
            'kind' => 'WALK_FORWARD',
            'idempotency_key' => 'dedup-test-key-123',
            'strategy_id' => 'momentum',
            'payload' => ['exchange' => 'bybit'],
        ]);

        $this->assertSame($job1->job_id, $job2->job_id);
        $this->assertSame(1, ResearchJobRecord::query()->where('idempotency_key', 'dedup-test-key-123')->count());
    }

    public function test_can_claim_and_start_job(): void
    {
        $job = $this->service->submitJob([
            'kind' => 'PATTERN_MINE',
            'strategy_id' => 'support_resistance_bounce',
            'payload' => ['exchange' => 'bybit'],
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
            'payload' => ['exchange' => 'bybit'],
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
            'payload' => ['exchange' => 'bybit'],
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
            'payload' => ['exchange' => 'bybit'],
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

    public function test_cancelled_job_cannot_be_requeued_by_stale_worker_failure(): void
    {
        $job = $this->service->submitJob([
            'kind' => 'BACKTEST',
            'payload' => ['exchange' => 'bybit'],
        ]);
        $claimed = $this->service->claimNextJob('worker-before-cancel', 60);
        $this->assertTrue($this->service->cancelJob($job->job_id));
        $this->assertFalse($this->service->failJob(
            $job->job_id,
            'worker-before-cancel',
            $claimed->fencing_token,
            'internal error'
        ));
        $this->assertSame('CANCELLED', $job->fresh()->status);
        $this->assertSame(0, $job->fresh()->retry_count);
    }

    public function test_expired_lease_cannot_be_revived_or_completed(): void
    {
        $job = $this->service->submitJob([
            'kind' => 'BACKTEST',
            'payload' => ['exchange' => 'bybit'],
        ]);
        $claimed = $this->service->claimNextJob('expired-worker', 60);
        $claimed->update(['lease_expires_at' => CarbonImmutable::now('UTC')->subMinute()]);

        $this->assertFalse($this->service->startJob($job->job_id, 'expired-worker', $claimed->fencing_token));
        $this->assertFalse($this->service->heartbeat($job->job_id, 'expired-worker', $claimed->fencing_token));
        $this->assertFalse($this->service->completeJob($job->job_id, 'expired-worker', $claimed->fencing_token, ['ok' => true]));
        $this->assertFalse($this->service->failJob($job->job_id, 'expired-worker', $claimed->fencing_token, 'secret'));
        $this->assertSame('CLAIMED', $job->fresh()->status);
        $this->assertSame(1, $this->service->reconcileStaleLeases());
        $this->assertSame('QUEUED', $job->fresh()->status);
    }

    public function test_reconcile_stale_leases(): void
    {
        $job = $this->service->submitJob([
            'kind' => 'BACKTEST',
            'payload' => ['exchange' => 'bybit'],
        ]);
        $claimed = $this->service->claimNextJob('crashed-worker', 10);

        // Manually expire the lease
        $claimed->update(['lease_expires_at' => CarbonImmutable::now('UTC')->subSeconds(20)]);

        $reconciled = $this->service->reconcileStaleLeases();
        $this->assertSame(1, $reconciled);

        $fresh = $job->fresh();
        $this->assertSame('QUEUED', $fresh->status);
        $this->assertNull($fresh->lease_owner);
    }

    public function test_rejects_go_kind_missing_exchange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Research job kind 'BACKTEST' requires explicit exchange provenance ('bybit' or 'binance').");
        $this->service->submitJob([
            'kind' => 'BACKTEST',
            'payload' => ['symbol' => 'BTCUSDT'],
        ]);
    }

    public function test_accepts_binance_exchange_provenance(): void
    {
        $job = $this->service->submitJob([
            'kind' => 'BACKTEST',
            'payload' => ['exchange' => 'binance', 'symbol' => 'BTCUSDT'],
        ]);

        $this->assertSame('binance', $job->payload['exchange']);
        $this->assertSame('binance', $job->payload['exchange_id']);
    }

    public function test_accepts_dataset_exchange_provenance(): void
    {
        $job = $this->service->submitJob([
            'kind' => 'BACKTEST',
            'payload' => [
                'dataset' => ['exchange' => 'binance'],
                'symbol' => 'BTCUSDT',
            ],
        ]);

        $this->assertSame('binance', $job->payload['exchange']);
        $this->assertSame('binance', $job->payload['exchange_id']);
    }

    public function test_accepts_dataset_exchange_top_level_alias(): void
    {
        $job = $this->service->submitJob([
            'kind' => 'WALK_FORWARD',
            'payload' => [
                'dataset_exchange' => 'bybit',
                'symbol' => 'BTCUSDT',
            ],
        ]);

        $this->assertSame('bybit', $job->payload['exchange']);
        $this->assertSame('bybit', $job->payload['exchange_id']);
    }

    public function test_rejects_conflicting_exchange_identities(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Research job exchange identities disagree: 'bybit' vs 'binance'");
        $this->service->submitJob([
            'kind' => 'FEATURE_BUILD',
            'payload' => [
                'exchange' => 'bybit',
                'exchange_id' => 'binance',
            ],
        ]);
    }

    public function test_rejects_conflicting_dataset_exchange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Research job exchange identities disagree: 'bybit' vs 'binance'");
        $this->service->submitJob([
            'kind' => 'BACKTEST',
            'payload' => [
                'exchange' => 'bybit',
                'dataset' => ['exchange' => 'binance'],
            ],
        ]);
    }

    public function test_rejects_unsupported_exchange_identity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Research job exchange 'okx' is unsupported. Strictly 'bybit' or 'binance' required.");
        $this->service->submitJob([
            'kind' => 'BACKTEST',
            'payload' => ['exchange' => 'okx'],
        ]);
    }

    public function test_rejects_empty_or_non_string_exchange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Research job exchange at 'payload.exchange' must be a nonempty string.");
        $this->service->submitJob([
            'kind' => 'BACKTEST',
            'payload' => ['exchange' => '   '],
        ]);
    }

    public function test_idempotency_key_cannot_conflate_bybit_and_binance_jobs(): void
    {
        // 1. Submit active Bybit job with shared idempotency key
        $bybitJob = $this->service->submitJob([
            'kind' => 'BACKTEST',
            'idempotency_key' => 'shared-key-exchange-test',
            'payload' => ['exchange' => 'bybit', 'symbol' => 'BTCUSDT'],
        ]);
        $this->assertSame('QUEUED', $bybitJob->status);

        // 2. Submitting same idempotency key for Binance must reject conflict
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Idempotency conflict: Active research job '{$bybitJob->job_id}' with key 'shared-key-exchange-test' belongs to exchange 'bybit', cannot be reused for 'binance'.");

        $this->service->submitJob([
            'kind' => 'BACKTEST',
            'idempotency_key' => 'shared-key-exchange-test',
            'payload' => ['exchange' => 'binance', 'symbol' => 'BTCUSDT'],
        ]);
    }

    public function test_auto_generated_idempotency_keys_differ_between_exchanges(): void
    {
        $bybitJob = $this->service->submitJob([
            'kind' => 'BACKTEST',
            'strategy_id' => 'trend_pullback',
            'payload' => ['exchange' => 'bybit', 'symbol' => 'BTCUSDT'],
        ]);

        $binanceJob = $this->service->submitJob([
            'kind' => 'BACKTEST',
            'strategy_id' => 'trend_pullback',
            'payload' => ['exchange' => 'binance', 'symbol' => 'BTCUSDT'],
        ]);

        $this->assertNotSame($bybitJob->idempotency_key, $binanceJob->idempotency_key);
        $this->assertNotSame($bybitJob->job_id, $binanceJob->job_id);
    }

    public function test_capability_routing_reflects_actual_worker_presence(): void
    {
        // 1. Initial state with zero workers -> Go engine is offline, not falsely declared ready
        $routing = $this->service->getCapabilityRouting();
        $this->assertArrayHasKey('capabilities', $routing);
        $this->assertArrayHasKey('jobs', $routing);
        $this->assertSame('offline', $routing['capabilities']['go']['status']);
        $this->assertFalse($routing['capabilities']['go']['available']);
        $this->assertSame('NO_REGISTERED_WORKERS', $routing['capabilities']['go']['unavailable_reason']);

        // 2. Register active Go worker -> Go engine dynamically becomes ready and available
        $this->service->registerWorker('go-worker-live-1', 'go', '1.0.0', ['backtest', 'walk_forward']);
        $routingLive = $this->service->getCapabilityRouting();
        $this->assertSame('ready', $routingLive['capabilities']['go']['status']);
        $this->assertTrue($routingLive['capabilities']['go']['available']);
        $this->assertNull($routingLive['capabilities']['go']['unavailable_reason']);
        $this->assertCount(1, $routingLive['capabilities']['go']['workers']);
        $this->assertSame('go-worker-live-1', $routingLive['capabilities']['go']['workers'][0]['worker_id']);
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
        $this->assertTrue($workers['go-worker-prod-1']['can_accept_jobs']);
        $this->assertTrue($workers['go-worker-prod-1']['is_heartbeat_fresh']);

        // Assert unsupported-version worker is rejected with reason
        $this->assertSame('unsupported_version', $workers['go-worker-legacy']['status']);
        $this->assertFalse($workers['go-worker-legacy']['available']);
        $this->assertSame('UNSUPPORTED_VERSION', $workers['go-worker-legacy']['unavailable_reason']);
        $this->assertFalse($workers['go-worker-legacy']['can_accept_jobs']);

        // Assert expired-heartbeat worker is offline with reason
        $this->assertSame('offline', $workers['python-worker-stale']['status']);
        $this->assertFalse($workers['python-worker-stale']['available']);
        $this->assertSame('HEARTBEAT_EXPIRED', $workers['python-worker-stale']['unavailable_reason']);
        $this->assertFalse($workers['python-worker-stale']['is_heartbeat_fresh']);

        // Engine capabilities report dynamic availability
        $this->assertTrue($routing['capabilities']['go']['available']);
        $this->assertFalse($routing['capabilities']['python']['available']);
        $this->assertSame('HEARTBEAT_EXPIRED', $routing['capabilities']['python']['unavailable_reason']);
    }
}
