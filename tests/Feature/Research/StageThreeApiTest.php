<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\PaperTrading\Application\PaperAccountService;
use App\Research\Infrastructure\Persistence\ResearchRecommendationRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StageThreeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_degradation_endpoint(): void
    {
        $this->app->make(PaperAccountService::class)->default();

        $response = $this->getJson('/api/v1/research/degradation?horizon=14d');
        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'horizon',
                    'strategies',
                ],
            ]);
    }

    public function test_correlations_endpoint(): void
    {
        $response = $this->getJson('/api/v1/research/correlations?horizon=14d');
        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'horizon',
                    'pair_matrix',
                    'concurrent_overlap',
                    'concentration_score',
                    'alerts',
                ],
            ]);
    }

    public function test_recommendations_and_lifecycle_endpoints(): void
    {
        $rec = ResearchRecommendationRecord::query()->create([
            'id' => (string) Str::uuid(),
            'category' => 'RISK_MITIGATION',
            'strategy_id' => 'trend_pullback',
            'title' => 'Test Risk Mitigation Recommendation',
            'description' => 'Test description',
            'evidence' => ['sample_size' => 10],
            'proposed_changes' => ['risk_factor' => 0.5],
            'status' => 'OPEN',
        ]);

        $listRes = $this->getJson('/api/v1/research/recommendations');
        $listRes->assertOk();

        $acceptRes = $this->postJson('/api/v1/research/recommendations/'.$rec->id.'/accept');
        $acceptRes->assertOk()
            ->assertJsonPath('data.status', 'ACCEPTED');

        $dismissRes = $this->postJson('/api/v1/research/recommendations/'.$rec->id.'/dismiss');
        $dismissRes->assertOk()
            ->assertJsonPath('data.status', 'DISMISSED');
    }

    public function test_report_endpoint_returns_signed_audit_manifest(): void
    {
        $this->app->make(PaperAccountService::class)->default();

        $response = $this->getJson('/api/v1/research/report?period=daily');
        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'report_type',
                    'generated_at',
                    'period',
                    'system_freshness',
                    'strategy_funnel_health',
                    'strategy_degradation_watchlist',
                    'counterfactual_filter_effectiveness',
                    'probability_calibration',
                    'portfolio_correlation_and_risk',
                    'actionable_recommendations',
                    'manifest' => [
                        'content_sha256',
                        'schema_version',
                        'operating_mode',
                    ],
                ],
            ])
            ->assertJsonPath('data.manifest.operating_mode', 'PAPER_TRADING_RESEARCH_ONLY');
    }
}
