<?php

declare(strict_types=1);

namespace App\Research\Application;

final readonly class MetaLabelingResearchService
{
    /**
     * Evaluate 4 paired policy configurations on the exact same decision cohort.
     *
     * 1. Base Strategy alone
     * 2. Base + ML Decision Advisor (P >= threshold)
     * 3. Base + Quality Grade Policy (Grades A, B, C eligible; D, E, UNRATED filtered)
     * 4. Base + ML Advisor + Quality Grade (Combined)
     */
    public function evaluatePairedPolicies(array $decisions, float $advisorThreshold = 0.40): array
    {
        $baseTrades = [];
        $advisorTrades = [];
        $gradeTrades = [];
        $combinedTrades = [];

        $directionInverted = false;

        foreach ($decisions as $dec) {
            $side = (string) ($dec['side'] ?? 'SKIP');
            if ($side === 'SKIP') {
                continue;
            }

            $realizedR = (float) ($dec['realized_r'] ?? 0.0);
            $prob = isset($dec['calibrated_probability']) ? (float) $dec['calibrated_probability'] : 0.50;
            $grade = (string) ($dec['quality_grade'] ?? 'UNRATED');

            $trade = [
                'id' => $dec['id'] ?? uniqid('dec_', true),
                'side' => $side,
                'realized_r' => $realizedR,
                'is_win' => $realizedR > 0.0,
                'probability' => $prob,
                'grade' => $grade,
            ];

            // 1. Base: all actionable setups taken
            $baseTrades[] = $trade;

            // 2. Base + ML Advisor: filter if probability < threshold
            $advisorPass = $prob >= $advisorThreshold;
            if ($advisorPass) {
                $advisorTrades[] = $trade;
            }

            // 3. Base + Quality Grade: filter if grade is D, E, or UNRATED
            $gradePass = in_array($grade, ['A', 'B', 'C'], true);
            if ($gradePass) {
                $gradeTrades[] = $trade;
            }

            // 4. Combined: requires BOTH advisor and grade pass
            if ($advisorPass && $gradePass) {
                $combinedTrades[] = $trade;
            }

            // Invariance check: ensure no policy flipped direction
            if (isset($dec['proposed_side']) && $dec['proposed_side'] !== $side) {
                $directionInverted = true;
            }
        }

        $calcMetrics = function (array $trades, int $totalCohort): array {
            $count = count($trades);
            $wins = count(array_filter($trades, fn ($t) => $t['is_win']));
            $losses = $count - $wins;
            $totalR = array_sum(array_column($trades, 'realized_r'));
            $winRate = $count > 0 ? round($wins / $count, 4) : 0.0;
            $avgR = $count > 0 ? round($totalR / $count, 4) : 0.0;

            $grossGains = 0.0;
            $grossLosses = 0.0;
            foreach ($trades as $t) {
                if ($t['realized_r'] > 0) {
                    $grossGains += $t['realized_r'];
                } else {
                    $grossLosses += abs($t['realized_r']);
                }
            }
            $profitFactor = $grossLosses > 0 ? round($grossGains / $grossLosses, 2) : ($grossGains > 0 ? 99.0 : 0.0);

            // Brier score on probabilistic calibration: (P - outcome)^2
            $brierSum = 0.0;
            foreach ($trades as $t) {
                $outcome = $t['is_win'] ? 1.0 : 0.0;
                $brierSum += ($t['probability'] - $outcome) ** 2;
            }
            $brier = $count > 0 ? round($brierSum / $count, 4) : 0.25;

            return [
                'executed_trades' => $count,
                'filtered_trades' => max(0, $totalCohort - $count),
                'filter_rate_pct' => $totalCohort > 0 ? round((($totalCohort - $count) / $totalCohort) * 100, 2) : 0.0,
                'win_count' => $wins,
                'loss_count' => $losses,
                'win_rate' => $winRate,
                'total_net_r' => round($totalR, 2),
                'average_net_r' => $avgR,
                'profit_factor' => $profitFactor,
                'brier_score' => $brier,
            ];
        };

        $totalCohort = count($baseTrades);
        $baseMetrics = $calcMetrics($baseTrades, $totalCohort);
        $advisorMetrics = $calcMetrics($advisorTrades, $totalCohort);
        $gradeMetrics = $calcMetrics($gradeTrades, $totalCohort);
        $combinedMetrics = $calcMetrics($combinedTrades, $totalCohort);

        return [
            'total_opportunities' => $totalCohort,
            'direction_invariance_verified' => ! $directionInverted,
            'policies' => [
                '1_base_strategy' => $baseMetrics,
                '2_base_plus_ml_advisor' => $advisorMetrics,
                '3_base_plus_quality_grade' => $gradeMetrics,
                '4_base_plus_advisor_and_grade' => $combinedMetrics,
            ],
            'comparisons_vs_base' => [
                'ml_advisor' => [
                    'delta_average_r' => round($advisorMetrics['average_net_r'] - $baseMetrics['average_net_r'], 4),
                    'trades_filtered' => $advisorMetrics['filtered_trades'],
                    'brier_reduction' => round($baseMetrics['brier_score'] - $advisorMetrics['brier_score'], 4),
                ],
                'quality_grade' => [
                    'delta_average_r' => round($gradeMetrics['average_net_r'] - $baseMetrics['average_net_r'], 4),
                    'trades_filtered' => $gradeMetrics['filtered_trades'],
                ],
                'advisor_and_grade' => [
                    'delta_average_r' => round($combinedMetrics['average_net_r'] - $baseMetrics['average_net_r'], 4),
                    'trades_filtered' => $combinedMetrics['filtered_trades'],
                ],
            ],
        ];
    }
}
