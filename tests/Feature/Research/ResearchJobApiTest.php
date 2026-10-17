<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\Research\Infrastructure\Persistence\ResearchJobRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ResearchJobApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_research_jobs(): void
    {
        ResearchJobRecord::query()->create([
            'job_id' => '11111111-1111-1111-1111-111111111111',
            'kind' => 'BACKTEST',
            'status' => 'QUEUED',
            'strategy_id' => 'trend_pullback',
            'idempotency_key' => 'key-1',
            'requested_at_utc' => now('UTC'),
        ]);

        $response = $this->getJson('/api/v1/research/jobs');
        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'data',
            'pagination' => ['current_page', 'per_page', 'has_more'],
        ]);
        $response->assertJsonFragment(['job_id' => '11111111-1111-1111-1111-111111111111']);
    }

    public function test_can_submit_research_job(): void
    {
        $response = $this->postJson('/api/v1/research/jobs', [
            'kind' => 'WALK_FORWARD',
            'strategy_id' => 'donchian_atr_breakout',
            'payload' => [
                'symbol' => 'BTCUSDT',
                'parameters' => ['donchian_period' => 20],
            ],
        ]);

        $response->assertStatus(202);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => ['job_id', 'kind', 'status'],
        ]);

        $this->assertDatabaseHas('research_jobs', [
            'kind' => 'WALK_FORWARD',
            'strategy_id' => 'donchian_atr_breakout',
            'status' => 'QUEUED',
        ]);
    }

    public function test_can_show_single_research_job(): void
    {
        $job = ResearchJobRecord::query()->create([
            'job_id' => '22222222-2222-2222-2222-222222222222',
            'kind' => 'PATTERN_MINE',
            'status' => 'SUCCEEDED',
            'idempotency_key' => 'key-2',
            'requested_at_utc' => now('UTC'),
            'result_payload' => ['patterns_found' => 8],
        ]);

        $response = $this->getJson('/api/v1/research/jobs/'.$job->job_id);
        $response->assertOk();
        $response->assertJsonPath('data.job_id', $job->job_id);
        $response->assertJsonPath('data.result_payload.patterns_found', 8);
    }

    public function test_can_cancel_queued_research_job(): void
    {
        $job = ResearchJobRecord::query()->create([
            'job_id' => '33333333-3333-3333-3333-333333333333',
            'kind' => 'MONTE_CARLO',
            'status' => 'QUEUED',
            'idempotency_key' => 'key-3',
            'requested_at_utc' => now('UTC'),
        ]);

        $response = $this->postJson("/api/v1/research/jobs/{$job->job_id}/cancel", [
            'reason' => 'User aborted simulation',
        ]);

        $response->assertOk();
        $this->assertSame('CANCELLED', $job->fresh()->status);
        $this->assertSame('User aborted simulation', $job->fresh()->sanitized_error);
    }

    public function test_returns_capabilities_routing(): void
    {
        $response = $this->getJson('/api/v1/research/capabilities');
        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'data' => [
                'capabilities' => ['php', 'go', 'python'],
                'jobs' => ['queued', 'claimed', 'running', 'succeeded', 'failed', 'cancelled', 'expired'],
            ],
        ]);
        $response->assertJsonPath('data.capabilities.go.engine', 'Go Research Engine');
    }
}
