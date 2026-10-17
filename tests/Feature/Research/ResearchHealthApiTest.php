<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\PaperTrading\Application\PaperAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ResearchHealthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_overview_endpoint_returns_strategy_funnel_and_status(): void
    {
        $this->app->make(PaperAccountService::class)->default();

        $response = $this->getJson('/api/v1/research/health/overview?period=24h');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'strategies',
                    'system_status',
                    'generated_at',
                ],
            ]);
    }

    public function test_data_freshness_endpoint_returns_candle_and_queue_health(): void
    {
        $response = $this->getJson('/api/v1/research/health/data-freshness');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'status',
                    'timestamp',
                    'database' => ['status', 'latency_ms'],
                    'candle_freshness',
                    'queues',
                    'execution_failures_24h',
                    'alerts',
                ],
            ]);
    }

    public function test_reconcile_and_reconciliation_runs_endpoints(): void
    {
        $account = $this->app->make(PaperAccountService::class)->default();

        $postResponse = $this->postJson('/api/v1/research/health/reconcile', [
            'account_id' => $account->id,
        ]);

        $postResponse->assertOk()
            ->assertJsonPath('data.status', 'PASSED')
            ->assertJsonPath('data.discrepancy_count', 0);

        $runsResponse = $this->getJson('/api/v1/research/health/reconciliation-runs?per_page=10');
        $runsResponse->assertOk()
            ->assertJsonPath('data.per_page', 10)
            ->assertJsonPath('data.total', 1);
    }
}
