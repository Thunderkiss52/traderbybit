<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\Research\Application\MetaLabelingResearchService;
use Tests\TestCase;

final class MetaLabelingResearchTest extends TestCase
{
    private MetaLabelingResearchService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MetaLabelingResearchService;
    }

    public function test_paired_policy_comparison_demonstrates_meta_labeling_filter_advantage(): void
    {
        // 50 synthetic closed trading opportunities with paired ground truth and advisor predictions
        $decisions = [];

        // 20 high-quality setups: positive outcome, high probability, Grade A or B
        for ($i = 0; $i < 20; $i++) {
            $decisions[] = [
                'id' => 'setup-win-'.$i,
                'proposed_side' => 'BUY',
                'side' => 'BUY',
                'realized_r' => 1.80,
                'calibrated_probability' => 0.85,
                'quality_grade' => 'A',
            ];
        }

        // 15 mediocre setups: marginal outcome (+0.30 R), moderate probability 0.70, Grade C
        for ($i = 0; $i < 15; $i++) {
            $decisions[] = [
                'id' => 'setup-med-'.$i,
                'proposed_side' => 'BUY',
                'side' => 'BUY',
                'realized_r' => 0.30,
                'calibrated_probability' => 0.70,
                'quality_grade' => 'C',
            ];
        }

        // 15 low-quality setups: losing outcome (-1.0 R), low probability 0.35, Grade D or UNRATED
        for ($i = 0; $i < 15; $i++) {
            $decisions[] = [
                'id' => 'setup-loss-'.$i,
                'proposed_side' => 'BUY',
                'side' => 'BUY',
                'realized_r' => -1.0,
                'calibrated_probability' => 0.35, // Advisor threshold is 0.40 -> will be filtered
                'quality_grade' => 'D',           // Grade filter will also exclude
            ];
        }

        $result = $this->service->evaluatePairedPolicies($decisions, advisorThreshold: 0.40);

        self::assertSame(50, $result['total_opportunities']);
        self::assertTrue($result['direction_invariance_verified']);

        $base = $result['policies']['1_base_strategy'];
        $advisor = $result['policies']['2_base_plus_ml_advisor'];
        $grade = $result['policies']['3_base_plus_quality_grade'];
        $combined = $result['policies']['4_base_plus_advisor_and_grade'];

        // 1. Base Strategy takes all 50 trades
        self::assertSame(50, $base['executed_trades']);
        self::assertSame(0, $base['filtered_trades']);
        self::assertSame(35, $base['win_count']);
        self::assertSame(15, $base['loss_count']);
        self::assertSame(0.70, $base['win_rate']);
        // Net R: 20*1.8 + 15*0.3 - 15*1.0 = 36 + 4.5 - 15 = 25.5 -> avg R = 25.5 / 50 = 0.51
        self::assertSame(25.5, $base['total_net_r']);
        self::assertSame(0.51, $base['average_net_r']);

        // 2. Base + ML Advisor filters the 15 low probability trades
        self::assertSame(35, $advisor['executed_trades']);
        self::assertSame(15, $advisor['filtered_trades']);
        self::assertSame(30.0, $advisor['filter_rate_pct']);
        self::assertSame(35, $advisor['win_count']);
        self::assertSame(0, $advisor['loss_count']);
        self::assertSame(1.0, $advisor['win_rate']); // 100% win rate after filtering low-prob losers
        // Net R: 36 + 4.5 = 40.5 -> avg R = 40.5 / 35 = 1.1571
        self::assertSame(40.5, $advisor['total_net_r']);
        self::assertGreaterThan($base['average_net_r'], $advisor['average_net_r']);

        // 3. Comparisons prove positive delta R and Brier score reduction
        $compAdvisor = $result['comparisons_vs_base']['ml_advisor'];
        self::assertGreaterThan(0.0, $compAdvisor['delta_average_r']);
        self::assertSame(15, $compAdvisor['trades_filtered']);
        self::assertGreaterThan(0.0, $compAdvisor['brier_reduction']);

        // 4. Combined policy also filters out losing cohort
        self::assertSame(35, $combined['executed_trades']);
        self::assertGreaterThan($base['average_net_r'], $combined['average_net_r']);
    }

    public function test_direction_invariance_detects_illicit_side_flip(): void
    {
        $decisions = [
            [
                'id' => 'dec-01',
                'proposed_side' => 'BUY',
                'side' => 'SELL', // Illicit direction inversion!
                'realized_r' => 1.0,
                'calibrated_probability' => 0.50,
                'quality_grade' => 'B',
            ],
        ];

        $result = $this->service->evaluatePairedPolicies($decisions);
        self::assertFalse($result['direction_invariance_verified']);
    }
}
