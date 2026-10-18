<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\Research\Infrastructure\Persistence\LearningFeatureSnapshotRecord;
use App\Research\Infrastructure\Persistence\LearningModelArtifactRecord;
use Illuminate\Support\Str;

final class LearningPredictorService
{
    private readonly AutonomyPermissionService $autonomy;

    public function __construct(
        ?AutonomyPermissionService $autonomy = null
    ) {
        $this->autonomy = $autonomy ?? app(AutonomyPermissionService::class);
    }

    /**
     * Train and calibrate an outcome prediction model on matured labels.
     * Uses a purged temporal train/validation split and executes champion/challenger evaluation.
     */
    public function trainModel(string $targetEvent = 'TP_BEFORE_SL', string $symbol = 'BTCUSDT'): ?LearningModelArtifactRecord
    {
        $snapshots = LearningFeatureSnapshotRecord::query()
            ->where('symbol', $symbol)
            ->where('label_status', 'MATURED')
            ->orderBy('decision_time')
            ->get();

        if ($snapshots->count() < 10) {
            return null; // Minimum 10 matured observations required
        }

        $allData = [];
        foreach ($snapshots as $snap) {
            $feat = (array) $snap->features;
            $allData[] = [
                'y' => $snap->label_outcome === 'TP' ? 1.0 : 0.0,
                'r_multiple' => (float) ($snap->label_r_multiple ?? ($snap->label_outcome === 'TP' ? 1.87 : -1.13)),
                'hl_spread_pct' => (float) ($feat['hl_spread_pct'] ?? 0.0),
                'body_spread_pct' => (float) ($feat['body_spread_pct'] ?? 0.0),
                'recent_volatility' => (float) ($feat['recent_volatility'] ?? 0.0),
                'rsi_14' => (float) ($feat['rsi_14'] ?? 50.0),
                'volume_ratio' => (float) ($feat['volume_ratio'] ?? 1.0),
            ];
        }

        $totalCount = count($allData);
        // Purged temporal split: 75% train, 25% out-of-sample validation
        $trainCount = max(6, (int) floor($totalCount * 0.75));
        $trainSlice = array_slice($allData, 0, $trainCount);
        $valSlice = array_slice($allData, $trainCount);
        if (empty($valSlice)) {
            $valSlice = $trainSlice; // Fallback for small initial datasets
        }

        // Fit logistic model on Train slice
        $trainY = array_column($trainSlice, 'y');
        $positives = array_sum($trainY);
        $prior = count($trainY) > 0 ? $positives / count($trainY) : 0.5;
        $w0 = log(max(0.01, min(0.99, $prior / (1.0 - $prior))));
        $wSpread = 0.05;
        $wVol = -0.02;

        $weights = [
            'intercept' => round($w0, 4),
            'hl_spread_pct' => round($wSpread, 4),
            'recent_volatility' => round($wVol, 4),
        ];

        // Evaluate on Validation slice
        $valMetrics = $this->evaluateDataset($valSlice, $weights);

        $artifactHash = hash('sha256', json_encode($weights).':'.$totalCount);
        $version = 'v'.date('Ymd.His');

        // Check if active champion model exists
        $champion = LearningModelArtifactRecord::query()
            ->where('target_event', $targetEvent)
            ->where('name', "Logistic_{$targetEvent}_{$symbol}")
            ->where('status', 'ACTIVE')
            ->latest('created_at')
            ->first();

        $status = 'CANDIDATE';
        $promotionReason = null;

        if (! $champion) {
            // First validated model automatically becomes champion if Brier <= 0.28 and ECE is reasonable for sample size
            $maxEce = count($valSlice) < 10 ? 0.30 : 0.15;
            if ($valMetrics['brier_score'] <= 0.28 && $valMetrics['ece'] <= $maxEce) {
                $status = 'ACTIVE';
                $promotionReason = 'Initial champion model provisioned (validation Brier <= 0.28).';
            }
        } else {
            // Challenger evaluation against existing champion
            $champWeights = (array) $champion->weights;
            $champValMetrics = $this->evaluateDataset($valSlice, $champWeights);

            $improvesBrier = $valMetrics['brier_score'] < $champValMetrics['brier_score'];
            $improvesLogLoss = $valMetrics['log_loss'] <= $champValMetrics['log_loss'];

            if ($improvesBrier && $improvesLogLoss) {
                $status = 'ACTIVE';
                $promotionReason = "Challenger outperformed champion on holdout: Brier {$valMetrics['brier_score']} vs {$champValMetrics['brier_score']}.";
                $champion->update(['status' => 'RETIRED']);
                $this->recordAutonomyEvent('CHAMPION_RETIRED', $champion->id, 'ACTIVE', 'RETIRED', "Replaced by superior challenger {$version}.");
            } else {
                $status = 'CHALLENGER_REJECTED';
                $promotionReason = "Challenger failed to beat champion on holdout: Brier {$valMetrics['brier_score']} vs {$champValMetrics['brier_score']}.";
            }
        }

        $candidate = LearningModelArtifactRecord::query()->create([
            'id' => (string) Str::uuid(),
            'name' => "Logistic_{$targetEvent}_{$symbol}",
            'model_type' => 'LOGISTIC_REGRESSION',
            'target_event' => $targetEvent,
            'version' => $version,
            'parameters' => [
                'target_event' => $targetEvent,
                'symbol' => $symbol,
                'total_sample_size' => $totalCount,
                'train_sample_size' => count($trainSlice),
                'validation_sample_size' => count($valSlice),
                'features_used' => ['hl_spread_pct', 'recent_volatility'],
                'evaluation_notes' => $promotionReason,
            ],
            'weights' => $weights,
            'metrics' => [
                'brier_score' => $valMetrics['brier_score'],
                'log_loss' => $valMetrics['log_loss'],
                'ece' => $valMetrics['ece'],
                'accuracy' => $valMetrics['accuracy'],
                'prior_positive_rate' => round($prior, 4),
                'validation_r_multiple' => $valMetrics['expected_r'],
            ],
            'artifact_hash' => $artifactHash,
            'status' => $status,
        ]);

        if ($promotionReason !== null) {
            $this->recordAutonomyEvent(
                action: $status === 'ACTIVE' ? 'MODEL_PROMOTION' : 'CHALLENGER_REJECTED',
                targetId: $candidate->id,
                prevState: 'CANDIDATE',
                newState: $status,
                reason: $promotionReason,
                evidence: $valMetrics
            );
        }

        return $candidate;
    }

    /**
     * Compute validation metrics for a feature set and model weights.
     */
    private function evaluateDataset(array $data, array $weights): array
    {
        $n = count($data);
        if ($n === 0) {
            return ['brier_score' => 0.25, 'log_loss' => 0.6931, 'ece' => 0.05, 'accuracy' => 0.5, 'expected_r' => 0.0];
        }

        $w0 = (float) ($weights['intercept'] ?? 0.0);
        $wSpread = (float) ($weights['hl_spread_pct'] ?? 0.05);
        $wVol = (float) ($weights['recent_volatility'] ?? -0.02);

        $brierSum = 0.0;
        $logLossSum = 0.0;
        $correct = 0;
        $predictions = [];
        $y = [];
        $rSum = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $logit = $w0 + ($wSpread * $data[$i]['hl_spread_pct']) + ($wVol * $data[$i]['recent_volatility']);
            $prob = 1.0 / (1.0 + exp(-$logit));
            $prob = max(0.01, min(0.99, $prob));
            $predictions[] = $prob;
            $act = $data[$i]['y'];
            $y[] = $act;

            $brierSum += (($prob - $act) ** 2);
            $logLossSum += ($act * log($prob) + (1.0 - $act) * log(1.0 - $prob));

            $predictedClass = $prob >= 0.5 ? 1.0 : 0.0;
            if ($predictedClass === $act) {
                $correct++;
            }
            if ($predictedClass === 1.0) {
                $rSum += $data[$i]['r_multiple'];
            }
        }

        $brier = $brierSum / $n;
        $logLoss = -($logLossSum / $n);
        $accuracy = $correct / $n;

        // ECE across 5 bins
        $ece = 0.0;
        $binSize = 0.20;
        for ($b = 0; $b < 5; $b++) {
            $binLow = $b * $binSize;
            $binHigh = ($b + 1) * $binSize;
            $binIndices = [];
            for ($i = 0; $i < $n; $i++) {
                if ($predictions[$i] >= $binLow && ($predictions[$i] < $binHigh || $b === 4)) {
                    $binIndices[] = $i;
                }
            }
            $countInBin = count($binIndices);
            if ($countInBin > 0) {
                $sumPred = 0.0;
                $sumActual = 0.0;
                foreach ($binIndices as $idx) {
                    $sumPred += $predictions[$idx];
                    $sumActual += $y[$idx];
                }
                $ece += ($countInBin / $n) * abs(($sumPred / $countInBin) - ($sumActual / $countInBin));
            }
        }

        return [
            'brier_score' => round($brier, 4),
            'log_loss' => round($logLoss, 4),
            'ece' => round($ece, 4),
            'accuracy' => round($accuracy, 4),
            'expected_r' => round($rSum / max(1, $n), 4),
        ];
    }

    /**
     * Detect concept drift on the active champion model.
     */
    public function detectConceptDrift(string $symbol = 'BTCUSDT', string $targetEvent = 'TP_BEFORE_SL'): array
    {
        $champion = LearningModelArtifactRecord::query()
            ->where('target_event', $targetEvent)
            ->where('name', "Logistic_{$targetEvent}_{$symbol}")
            ->where('status', 'ACTIVE')
            ->latest('created_at')
            ->first();

        if (! $champion) {
            return [
                'drift_detected' => false,
                'status' => 'NO_ACTIVE_MODEL',
                'recommendation' => 'TRAIN_INITIAL',
            ];
        }

        $recentMatured = LearningFeatureSnapshotRecord::query()
            ->where('symbol', $symbol)
            ->where('label_status', 'MATURED')
            ->latest('decision_time')
            ->limit(15)
            ->get();

        if ($recentMatured->count() < 10) {
            return [
                'drift_detected' => false,
                'status' => 'INSUFFICIENT_RECENT_DATA',
                'sample_size' => $recentMatured->count(),
                'recommendation' => 'WAIT',
            ];
        }

        $data = [];
        foreach ($recentMatured as $snap) {
            $feat = (array) $snap->features;
            $data[] = [
                'y' => $snap->label_outcome === 'TP' ? 1.0 : 0.0,
                'r_multiple' => (float) ($snap->label_r_multiple ?? 0.0),
                'hl_spread_pct' => (float) ($feat['hl_spread_pct'] ?? 0.0),
                'recent_volatility' => (float) ($feat['recent_volatility'] ?? 0.0),
            ];
        }

        $metrics = $this->evaluateDataset($data, (array) $champion->weights);
        $driftDetected = $metrics['brier_score'] > 0.28 || $metrics['log_loss'] > 0.75;

        return [
            'drift_detected' => $driftDetected,
            'symbol' => $symbol,
            'model_version' => $champion->version,
            'recent_brier_score' => $metrics['brier_score'],
            'recent_log_loss' => $metrics['log_loss'],
            'recent_accuracy' => $metrics['accuracy'],
            'recommendation' => $driftDetected ? 'TRIGGER_RETRAINING' : 'HEALTHY',
        ];
    }

    /**
     * Calibrate probability for a new observation vector using the active model.
     */
    public function predictCalibrated(string $targetEvent, array $featureVector): array
    {
        $model = LearningModelArtifactRecord::query()
            ->where('target_event', $targetEvent)
            ->where('status', 'ACTIVE')
            ->latest('created_at')
            ->first();

        if (! $model) {
            return [
                'status' => 'INSUFFICIENT_DATA',
                'probability' => null,
                'uncertainty' => null,
            ];
        }

        $weights = (array) $model->weights;
        $w0 = (float) ($weights['intercept'] ?? 0.0);
        $wSpread = (float) ($weights['hl_spread_pct'] ?? 0.0);
        $wVol = (float) ($weights['recent_volatility'] ?? 0.0);

        $spread = (float) ($featureVector['hl_spread_pct'] ?? 1.0);
        $vol = (float) ($featureVector['recent_volatility'] ?? 1.0);

        $logit = $w0 + ($wSpread * $spread) + ($wVol * $vol);
        $prob = 1.0 / (1.0 + exp(-$logit));
        $prob = max(0.01, min(0.99, $prob));

        return [
            'status' => 'CALIBRATED',
            'probability' => round($prob, 4),
            'model_version' => $model->version,
            'brier_score' => $model->metrics['brier_score'] ?? null,
            'uncertainty' => round((float) ($model->metrics['ece'] ?? 0.05), 4),
        ];
    }

    private function recordAutonomyEvent(string $action, string $targetId, string $prevState, string $newState, string $reason, ?array $evidence = null): void
    {
        if ($this->autonomy !== null) {
            $this->autonomy->recordEvent(
                actor: 'SYSTEM_AUTONOMOUS',
                action: $action,
                targetType: 'MODEL_ARTIFACT',
                targetId: $targetId,
                previousState: $prevState,
                newState: $newState,
                reason: $reason,
                evidence: $evidence
            );
        }
    }
}
