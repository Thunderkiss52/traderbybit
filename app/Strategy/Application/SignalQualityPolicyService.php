<?php

declare(strict_types=1);

namespace App\Strategy\Application;

use App\Strategy\Domain\QualityClass;
use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\SignalSide;

final readonly class SignalQualityPolicyService
{
    public const VERSION = '1.1.0';

    public function version(): string
    {
        return self::VERSION;
    }

    /**
     * Evaluate quality grade A-E / UNRATED based on score, calibrated expectancy, and uncertainty.
     * Quality grading evaluates the intrinsic opportunity and remains decoupled from final risk/trade execution.
     *
     * @param array{
     *     expected_net_r?: float|null,
     *     uncertainty?: float|null,
     *     sample_size?: int|null,
     *     model_artifact_id?: string|null,
     *     data_snapshot_id?: string|null
     * } $context
     * @return array{
     *     decision_id: string|null,
     *     direction: string,
     *     original_proposal: string,
     *     quality_grade: string,
     *     quality_policy_version: string,
     *     eligibility_status: string,
     *     rejection_reason: string|null,
     *     expected_net_r: float,
     *     confidence_or_uncertainty: float,
     *     model_artifact_id: string|null,
     *     data_snapshot_id: string|null
     * }
     */
    public function evaluate(SignalDecision $decision, array $context = []): array
    {
        $side = $decision->side;
        $decisionId = $decision->id;
        $score = $decision->score;

        // Pure SKIP without price plan or score is UNRATED
        if ($decision->pricePlan === null && $score <= 0) {
            return [
                'decision_id' => $decisionId,
                'direction' => $side->value,
                'original_proposal' => $side->value,
                'quality_grade' => QualityClass::Unrated->value,
                'quality_policy_version' => $this->version(),
                'eligibility_status' => 'NOT_ELIGIBLE',
                'rejection_reason' => ! empty($decision->reasons) ? implode(';', $decision->reasons) : 'no_candidate_setup',
                'expected_net_r' => 0.0,
                'confidence_or_uncertainty' => 1.0,
                'model_artifact_id' => $context['model_artifact_id'] ?? null,
                'data_snapshot_id' => $context['data_snapshot_id'] ?? null,
            ];
        }

        $sampleSize = $context['sample_size'] ?? 100;
        $uncertainty = (float) ($context['uncertainty'] ?? 0.05);

        // Compute expected net R: from context or approximated from score and price plan
        $expectedNetR = isset($context['expected_net_r'])
            ? (float) $context['expected_net_r']
            : $this->approximateExpectedNetR($decision);

        // Calculate 95% conservative lower-bound expected net R (1.645 sigma)
        $conservativeNetR = $expectedNetR - (1.645 * $uncertainty);

        if ($sampleSize < 10) {
            $grade = QualityClass::Unrated;
            $eligibility = 'INSUFFICIENT_SAMPLE';
            $rejectionReason = 'sample_size_below_threshold';
        } elseif ($conservativeNetR >= 0.30 && $score >= 80) {
            $grade = QualityClass::A;
            $eligibility = 'ELIGIBLE';
            $rejectionReason = null;
        } elseif ($conservativeNetR >= 0.15 && $score >= 70) {
            $grade = QualityClass::B;
            $eligibility = 'ELIGIBLE';
            $rejectionReason = null;
        } elseif ($conservativeNetR >= 0.00 && $score >= 60) {
            $grade = QualityClass::C;
            $eligibility = 'ELIGIBLE';
            $rejectionReason = null;
        } elseif ($conservativeNetR >= -0.15) {
            $grade = QualityClass::D;
            $eligibility = 'MARGINAL';
            $rejectionReason = 'suboptimal_expected_return';
        } else {
            $grade = QualityClass::E;
            $eligibility = 'SUB_PAR';
            $rejectionReason = 'negative_expected_return';
        }

        // If strategy chose to SKIP (e.g. risk or secondary filter), keep the technical grade
        // but mark eligibility as SKIPPED and record the skip reason
        if ($side === SignalSide::Skip) {
            $eligibility = 'SKIPPED';
            $rejectionReason = ! empty($decision->reasons) ? implode(';', $decision->reasons) : ($rejectionReason ?? 'skip_decision');
        }

        return [
            'decision_id' => $decisionId,
            'direction' => $side->value,
            'original_proposal' => $side->value,
            'quality_grade' => $grade->value,
            'quality_policy_version' => $this->version(),
            'eligibility_status' => $eligibility,
            'rejection_reason' => $rejectionReason,
            'expected_net_r' => round($expectedNetR, 4),
            'confidence_or_uncertainty' => round($uncertainty, 4),
            'model_artifact_id' => $context['model_artifact_id'] ?? null,
            'data_snapshot_id' => $context['data_snapshot_id'] ?? null,
        ];
    }

    private function approximateExpectedNetR(SignalDecision $decision): float
    {
        $rr1 = $decision->pricePlan !== null ? (float) $decision->pricePlan->riskReward1 : 1.5;
        $winRate = min(0.85, max(0.30, $decision->score / 100.0));
        $lossRate = 1.0 - $winRate;
        $costR = 0.08; // Estimated roundtrip taker fee + slippage in R units

        return ($winRate * $rr1) - $lossRate - $costR;
    }
}
