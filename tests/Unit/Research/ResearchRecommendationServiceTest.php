<?php

declare(strict_types=1);

namespace Tests\Unit\Research;

use App\Research\Application\ResearchRecommendationService;
use App\Research\Infrastructure\Persistence\CounterfactualEvaluationRecord;
use App\Research\Infrastructure\Persistence\StrategyDegradationSnapshotRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ResearchRecommendationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_recommendations_from_counterfactual_and_degradation(): void
    {
        $service = new ResearchRecommendationService;
        $now = CarbonImmutable::now();

        // 1. Seed 3 counterfactual TP skips (> 4R missed) for ADX filter
        for ($i = 0; $i < 3; $i++) {
            CounterfactualEvaluationRecord::query()->create([
                'id' => (string) Str::uuid(),
                'strategy_id' => 'trend_pullback',
                'strategy_version' => '1.0.0',
                'symbol' => 'BTCUSDT',
                'timeframe' => '15',
                'decision_time' => $now->subDays(2),
                'skip_reason' => 'adx_below_threshold',
                'filter_family' => 'ADX',
                'candidate_direction' => 'BUY',
                'hypothetical_entry' => '60000.0',
                'hypothetical_sl' => '59000.0',
                'hypothetical_tp' => '62000.0',
                'status' => 'RESOLVED_TP',
                'mfe' => '62500.0',
                'mae' => '59800.0',
                'r_multiple' => '2.0',
                'net_return_percent' => '3.0',
                'resolved_at' => $now->subDays(1),
                'decision_snapshot' => ['adx' => 19.0],
            ]);
        }

        // 2. Seed a degrading strategy snapshot
        StrategyDegradationSnapshotRecord::query()->create([
            'id' => (string) Str::uuid(),
            'strategy_id' => 'bollinger_mean_reversion',
            'strategy_version' => '1.0.0',
            'symbol' => 'ETHUSDT',
            'horizon' => '14d',
            'sample_size' => 10,
            'expectancy_r' => -0.5,
            'profit_factor' => 0.4,
            'win_rate' => 0.2,
            'win_rate_ci_lower' => 0.05,
            'win_rate_ci_upper' => 0.45,
            'max_drawdown_percent' => 18.5,
            'status' => 'DEGRADING',
            'degradation_type' => 'POSSIBLE_ALPHA_DECAY',
            'diagnostics' => ['losses' => 8, 'wins' => 2],
            'as_of_time' => $now,
        ]);

        $recommendations = $service->generateRecommendations();

        self::assertCount(2, $recommendations);

        $categories = array_map(fn ($r) => $r->category, $recommendations);
        self::assertContains('ALPHA_IMPROVEMENT', $categories);
        self::assertContains('RISK_MITIGATION', $categories);

        // Verify deduplication: repeated runs must update existing OPEN records, not create duplicates
        $secondRun = $service->generateRecommendations();
        self::assertCount(2, $secondRun);
        $this->assertDatabaseCount('research_recommendations', 2);
    }
}
