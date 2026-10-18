<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\Research\Infrastructure\Persistence\LearningModelArtifactRecord;
use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\SignalSide;

final class ModelAdvisoryService
{
    public function __construct(
        private readonly LearningPredictorService $predictor,
        private readonly AutonomyPermissionService $autonomy,
        private readonly array $config = []
    ) {}

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

        // Query active champion model for this target event & symbol
        $model = LearningModelArtifactRecord::query()
            ->where('target_event', $targetEvent)
            ->where('name', "Logistic_{$targetEvent}_{$decision->symbol}")
            ->where('status', 'ACTIVE')
            ->latest('created_at')
            ->first();

        if (! $model) {
            $snapshot = $decision->indicatorSnapshot;
            $snapshot['model_advisory'] = [
                'status' => 'NO_ACTIVE_MODEL',
                'mode' => $mode,
            ];

            return new SignalDecision(
                $decision->id,
                $decision->exchange,
                $decision->category,
                $decision->symbol,
                $decision->timeframe,
                $decision->decisionTime,
                $decision->candleCloseTime,
                $decision->side,
                $decision->qualityClass,
                $decision->score,
                $decision->pricePlan,
                $decision->modelRiskPercent,
                $decision->expiresAt,
                $decision->maximumHoldingMinutes,
                $decision->reasons,
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

        // Predict calibrated probability using model weights
        $pred = $this->predictor->predictCalibrated($targetEvent, $features);
        $prob = $pred['probability'] !== null ? (float) $pred['probability'] : 0.50;
        $recommendation = $prob >= $threshold ? 'ALLOW' : 'VETO_LOW_PROBABILITY';

        $altered = false;
        $finalSide = $decision->side;
        $finalReasons = $decision->reasons;
        $action = 'MODEL_ADVISORY_SHADOW';
        $reasonText = "Shadow evaluation: P={$prob} vs threshold {$threshold} ({$recommendation}). Trade unaltered.";

        if ($mode === 'ACTIVE' && $decision->side !== SignalSide::Skip) {
            if ($gatesPassed && $recommendation === 'VETO_LOW_PROBABILITY') {
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
            'status' => 'CALIBRATED',
            'mode' => $mode,
            'model_id' => $model->id,
            'model_version' => $model->version,
            'calibrated_probability' => $prob,
            'threshold' => $threshold,
            'recommendation' => $recommendation,
            'model_brier' => $brier,
            'model_ece' => $ece,
            'validation_gates_passed' => $gatesPassed,
            'altered_decision' => $altered,
        ];

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
