<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\Research\Infrastructure\Persistence\LearningModelArtifactRecord;
use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\SignalSide;

final class ModelAdvisoryService
{
    private PromotionPolicyService $promotionPolicy;

    public function __construct(
        private readonly LearningPredictorService $predictor,
        private readonly AutonomyPermissionService $autonomy,
        ?PromotionPolicyService $promotionPolicy = null,
        private readonly array $config = []
    ) {
        $this->promotionPolicy = $promotionPolicy ?? app(PromotionPolicyService::class);
    }

    /**
     * Evaluate an active ML model advisory on a generated trading decision.
     */
    public function advise(SignalDecision $decision, array $features = []): SignalDecision
    {
        $enabled = (bool) ($this->config['enabled'] ?? config('strategy.model_advisory.enabled', true));
        if (! $enabled) {
            return $decision;
        }

        $mode = strtoupper((string) ($this->config['mode'] ?? config('strategy.model_advisory.mode', 'SHADOW')));
        $targetEvent = (string) ($this->config['target_event'] ?? config('strategy.model_advisory.target_event', 'TP_BEFORE_SL'));
        $threshold = (float) ($this->config['minimum_calibrated_probability'] ?? config('strategy.model_advisory.minimum_calibrated_probability', 0.40));
        $maxBrier = (float) ($this->config['maximum_allowed_brier'] ?? config('strategy.model_advisory.maximum_allowed_brier', 0.28));
        $maxEce = (float) ($this->config['maximum_allowed_ece'] ?? config('strategy.model_advisory.maximum_allowed_ece', 0.15));
        $fallbackPolicy = strtoupper((string) ($this->config['fallback_policy'] ?? config('strategy.model_advisory.fallback_policy', 'FAIL_CLOSED_SKIP')));
        $isAllowUnfiltered = in_array($fallbackPolicy, ['ALLOW_UNFILTERED', 'FALLBACK_BASELINE'], true);

        // Query active champion model for this target event & symbol
        $model = LearningModelArtifactRecord::query()
            ->where('target_event', $targetEvent)
            ->where('name', "Logistic_{$targetEvent}_{$decision->symbol}")
            ->where('status', 'ACTIVE')
            ->latest('created_at')
            ->first();

        if (! $model) {
            $altered = false;
            $finalSide = $decision->side;
            $finalReasons = $decision->reasons;
            $status = 'NO_ACTIVE_MODEL';

            if ($mode === 'ACTIVE' && $decision->side !== SignalSide::Skip) {
                if (! $isAllowUnfiltered) {
                    $finalSide = SignalSide::Skip;
                    $finalReasons = array_merge($finalReasons, ['ml_advisory_no_active_model_fail_closed']);
                    $altered = true;
                    $action = 'MODEL_ADVISORY_FAIL_CLOSED';
                    $reasonText = 'Active mode with no active champion model: fail-closed to SKIP.';
                } else {
                    $action = 'MODEL_ADVISORY_FALLBACK_BASELINE';
                    $reasonText = 'Active mode with no active champion model: fallback to authorized unfiltered execution.';
                }

                $this->autonomy->recordEvent(
                    actor: 'SYSTEM_AUTONOMOUS',
                    action: $action,
                    targetType: 'MODEL_ARTIFACT',
                    targetId: 'none',
                    previousState: $decision->side->value,
                    newState: $finalSide->value,
                    reason: $reasonText,
                    evidence: [
                        'mode' => $mode,
                        'fallback_policy' => $fallbackPolicy,
                        'original_decision_side' => $decision->side->value,
                    ]
                );
            }

            $snapshot = $decision->indicatorSnapshot;
            $snapshot['model_advisory'] = [
                'status' => $status,
                'mode' => $mode,
                'fallback_policy' => $fallbackPolicy,
                'original_decision_side' => $decision->side->value,
                'altered_decision' => $altered,
                'eligible_for_advisory' => false,
            ];

            return new SignalDecision(
                $decision->id,
                $decision->exchange,
                $decision->category,
                $decision->symbol,
                $decision->timeframe,
                $decision->decisionTime,
                $decision->candleCloseTime,
                $finalSide,
                $finalSide === SignalSide::Skip ? null : $decision->qualityClass,
                $decision->score,
                $finalSide === SignalSide::Skip ? null : $decision->pricePlan,
                $finalSide === SignalSide::Skip ? null : $decision->modelRiskPercent,
                $decision->expiresAt,
                $decision->maximumHoldingMinutes,
                $finalReasons,
                $decision->risks,
                $snapshot,
                $decision->strategyVersion,
                $decision->schemaVersion
            );
        }

        // Validate model eligibility under Gate 6 promotion policy
        $eligibility = $this->promotionPolicy->evaluateAdvisoryEligibility($model);
        if (! $eligibility['eligible']) {
            $altered = false;
            $finalSide = $decision->side;
            $finalReasons = $decision->reasons;
            $action = 'MODEL_ADVISORY_SHADOW';
            $reasonText = "Shadow evaluation of ineligible legacy model {$model->version}: bypassed.";

            // Predict probability for shadow observation and research logs only
            $prob = 0.50;
            try {
                $pred = $this->predictor->predictCalibrated($targetEvent, $features);
                $prob = $pred['probability'] !== null ? (float) $pred['probability'] : 0.50;
            } catch (\Throwable) {
                // Ignore prediction errors for ineligible model
            }
            $recommendation = $prob >= $threshold ? 'ALLOW' : 'VETO_LOW_PROBABILITY';

            if ($mode === 'ACTIVE' && $decision->side !== SignalSide::Skip) {
                if (! $isAllowUnfiltered) {
                    $finalSide = SignalSide::Skip;
                    $finalReasons = array_merge($decision->reasons, ['ml_advisory_no_eligible_model_fail_closed']);
                    $altered = true;
                    $action = 'MODEL_ADVISORY_FAIL_CLOSED';
                    $reasonText = "Active mode with ineligible legacy model {$model->version}: fail-closed to SKIP.";
                } else {
                    $action = 'MODEL_ADVISORY_LEGACY_BYPASS';
                    $reasonText = "Active mode with ineligible legacy model {$model->version}: fallback to authorized unfiltered execution.";
                }
            }

            $advisoryData = [
                'status' => 'INELIGIBLE_LEGACY_MODEL',
                'mode' => $mode,
                'fallback_policy' => $fallbackPolicy,
                'model_id' => $model->id,
                'model_version' => $model->version,
                'calibrated_probability' => $prob,
                'threshold' => $threshold,
                'recommendation' => $isAllowUnfiltered ? 'ALLOW_UNFILTERED' : 'FAIL_CLOSED_SKIP',
                'eligible_for_advisory' => false,
                'ineligibility_reason' => $eligibility['reason'],
                'original_decision_side' => $decision->side->value,
                'altered_decision' => $altered,
            ];

            $this->autonomy->recordEvent(
                actor: 'SYSTEM_AUTONOMOUS',
                action: $action,
                targetType: 'MODEL_ARTIFACT',
                targetId: $model->id,
                previousState: $decision->side->value,
                newState: $finalSide->value,
                reason: $reasonText,
                evidence: $advisoryData
            );

            $snapshot = $decision->indicatorSnapshot;
            $snapshot['model_advisory'] = $advisoryData;

            return new SignalDecision(
                $decision->id,
                $decision->exchange,
                $decision->category,
                $decision->symbol,
                $decision->timeframe,
                $decision->decisionTime,
                $decision->candleCloseTime,
                $finalSide,
                $finalSide === SignalSide::Skip ? null : $decision->qualityClass,
                $decision->score,
                $finalSide === SignalSide::Skip ? null : $decision->pricePlan,
                $finalSide === SignalSide::Skip ? null : $decision->modelRiskPercent,
                $decision->expiresAt,
                $decision->maximumHoldingMinutes,
                $finalReasons,
                $decision->risks,
                $snapshot,
                $decision->strategyVersion,
                $decision->schemaVersion
            );
        }

        // Validate model gates
        $brier = (float) ($model->metrics['brier_score'] ?? 1.0);
        $ece = (float) ($model->metrics['ece'] ?? 1.0);
        $gatesPassed = $brier <= $maxBrier && $ece <= $maxEce;

        // Predict calibrated probability using model weights with exception protection
        $inferenceException = null;
        try {
            $pred = $this->predictor->predictCalibrated($targetEvent, $features);
            $prob = $pred['probability'] !== null ? (float) $pred['probability'] : 0.50;
            $recommendation = $prob >= $threshold ? 'ALLOW' : 'VETO_LOW_PROBABILITY';
        } catch (\Throwable $e) {
            $inferenceException = $e;
            $prob = 0.50;
            $recommendation = 'INFERENCE_ERROR';
        }

        $altered = false;
        $finalSide = $decision->side;
        $finalReasons = $decision->reasons;
        $action = 'MODEL_ADVISORY_SHADOW';
        $reasonText = "Shadow evaluation: P={$prob} vs threshold {$threshold} ({$recommendation}). Trade unaltered.";

        if ($mode === 'ACTIVE' && $decision->side !== SignalSide::Skip) {
            if ($inferenceException !== null) {
                if (! $isAllowUnfiltered) {
                    $finalSide = SignalSide::Skip;
                    $finalReasons = array_merge($finalReasons, ['ml_advisory_inference_error_fail_closed']);
                    $altered = true;
                    $action = 'MODEL_ADVISORY_FAIL_CLOSED';
                    $reasonText = "Active model inference exception: {$inferenceException->getMessage()}. Fail-closed to SKIP.";
                } else {
                    $action = 'MODEL_ADVISORY_FALLBACK_BASELINE';
                    $reasonText = "Active model inference exception: {$inferenceException->getMessage()}. Fallback to authorized unfiltered execution.";
                }
            } elseif (! $gatesPassed) {
                if (! $isAllowUnfiltered) {
                    $finalSide = SignalSide::Skip;
                    $finalReasons = array_merge($finalReasons, ['ml_advisory_corrupt_model_fail_closed']);
                    $altered = true;
                    $action = 'MODEL_ADVISORY_FAIL_CLOSED';
                    $reasonText = "Active model validation gates breached (Brier: {$brier}, ECE: {$ece}). Fail-closed to SKIP.";
                } else {
                    $action = 'MODEL_ADVISORY_FALLBACK_BASELINE';
                    $reasonText = 'Active model validation gates breached: fallback to authorized unfiltered execution.';
                }
            } elseif ($recommendation === 'VETO_LOW_PROBABILITY') {
                $finalSide = SignalSide::Skip;
                $finalReasons = array_merge($finalReasons, ['ml_advisory_low_probability']);
                $altered = true;
                $action = 'MODEL_ADVISORY_VETO';
                $reasonText = "Active model vetoed trade: calibrated probability {$prob} < threshold {$threshold}. Converted to SKIP.";
            } else {
                $action = 'MODEL_ADVISORY_PASSED';
                $reasonText = "Active model approved trade: calibrated probability {$prob} >= threshold {$threshold}.";
            }
        }

        $advisoryData = [
            'status' => $inferenceException ? 'INFERENCE_ERROR' : 'CALIBRATED',
            'mode' => $mode,
            'fallback_policy' => $fallbackPolicy,
            'model_id' => $model->id,
            'model_version' => $model->version,
            'calibrated_probability' => $prob,
            'threshold' => $threshold,
            'recommendation' => $recommendation,
            'model_brier' => $brier,
            'model_ece' => $ece,
            'validation_gates_passed' => $gatesPassed,
            'original_decision_side' => $decision->side->value,
            'altered_decision' => $altered,
            'eligible_for_advisory' => true,
        ];
        if ($inferenceException !== null) {
            $advisoryData['inference_error'] = $inferenceException->getMessage();
        }

        // Audit logging
        $this->autonomy->recordEvent(
            actor: 'SYSTEM_AUTONOMOUS',
            action: $action,
            targetType: 'MODEL_ARTIFACT',
            targetId: $model->id,
            previousState: $decision->side->value,
            newState: $finalSide->value,
            reason: $reasonText,
            evidence: $advisoryData
        );

        $snapshot = $decision->indicatorSnapshot;
        $snapshot['model_advisory'] = $advisoryData;

        return new SignalDecision(
            $decision->id,
            $decision->exchange,
            $decision->category,
            $decision->symbol,
            $decision->timeframe,
            $decision->decisionTime,
            $decision->candleCloseTime,
            $finalSide,
            $finalSide === SignalSide::Skip ? null : $decision->qualityClass,
            $decision->score,
            $finalSide === SignalSide::Skip ? null : $decision->pricePlan,
            $finalSide === SignalSide::Skip ? null : $decision->modelRiskPercent,
            $decision->expiresAt,
            $decision->maximumHoldingMinutes,
            $finalReasons,
            $decision->risks,
            $snapshot,
            $decision->strategyVersion,
            $decision->schemaVersion
        );
    }
}
