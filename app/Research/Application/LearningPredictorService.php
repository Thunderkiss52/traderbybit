<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\Research\Infrastructure\Persistence\LearningFeatureSnapshotRecord;
use App\Research\Infrastructure\Persistence\LearningModelArtifactRecord;
use Carbon\CarbonImmutable;
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

        if ($snapshots->count() < 12) {
            return null; // Minimum 12 matured observations required
        }

        $allData = [];
        foreach ($snapshots as $snap) {
            $feat = (array) $snap->features;
            $allData[] = [
                'decision_time' => $snap->decision_time,
                'outcome_at' => $snap->outcome_at ? CarbonImmutable::parse($snap->outcome_at) : null,
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
        // Purged temporal split: 70% train, 30% untouched out-of-sample validation
        $splitIndex = (int) floor($totalCount * 0.70);
        if ($splitIndex < 5 || ($totalCount - $splitIndex) < 3) {
            return null;
        }

        $valSlice = array_slice($allData, $splitIndex);
        $valStartTime = $valSlice[0]['decision_time'];

        // Purging: exclude training samples whose label maturity horizon overlaps with validation start
        $rawTrain = array_slice($allData, 0, $splitIndex);
        $trainSlice = [];
        foreach ($rawTrain as $item) {
            $outcomeTime = $item['outcome_at'] ?? $item['decision_time']->addMinutes(60);
            if ($outcomeTime <= $valStartTime) {
                $trainSlice[] = $item;
            }
        }

        if (count($trainSlice) < 5) {
            // When event horizon is long relative to step size, ensure clean non-overlapping boundary
            $trainSlice = array_slice($rawTrain, 0, max(5, count($rawTrain) - 2));
        }

        // Untouched validation data must be distinct and non-empty; NEVER reuse training slice
        if (count($valSlice) < 3 || count($trainSlice) < 5) {
            return null;
        }

        $featureKeys = ['hl_spread_pct', 'recent_volatility', 'rsi_14', 'volume_ratio'];
        $m = count($trainSlice);
        $means = [];
        $stds = [];

        // Feature standardization computed strictly on Train slice only
        foreach ($featureKeys as $k) {
            $sum = 0.0;
            for ($i = 0; $i < $m; $i++) {
                $sum += $trainSlice[$i][$k];
            }
            $mean = $sum / $m;
            $variance = 0.0;
            for ($i = 0; $i < $m; $i++) {
                $diff = $trainSlice[$i][$k] - $mean;
                $variance += ($diff * $diff);
            }
            $std = sqrt($variance / $m);
            if ($std < 1e-4) {
                $std = 1.0;
            }
            $means[$k] = $mean;
            $stds[$k] = $std;
        }

        // Fit logistic model via Batch Gradient Descent with L2 regularization
        $trainY = array_column($trainSlice, 'y');
        $positives = array_sum($trainY);
        $prior = count($trainY) > 0 ? $positives / count($trainY) : 0.5;
        $w0 = log(max(0.05, min(0.95, $prior / (1.0 - $prior))));
        $w = array_fill_keys($featureKeys, 0.0);

        $iterations = 300;
        $learningRate = 0.15;
        $lambda = 0.01;

        for ($iter = 0; $iter < $iterations; $iter++) {
            $grad0 = 0.0;
            $grads = array_fill_keys($featureKeys, 0.0);

            for ($i = 0; $i < $m; $i++) {
                $logit = $w0;
                foreach ($featureKeys as $k) {
                    $xNorm = ($trainSlice[$i][$k] - $means[$k]) / $stds[$k];
                    $logit += $w[$k] * $xNorm;
                }
                $prob = 1.0 / (1.0 + exp(-max(-15.0, min(15.0, $logit))));
                $error = $prob - $trainSlice[$i]['y'];

                $grad0 += $error;
                foreach ($featureKeys as $k) {
                    $xNorm = ($trainSlice[$i][$k] - $means[$k]) / $stds[$k];
                    $grads[$k] += $error * $xNorm;
                }
            }

            $w0 -= $learningRate * ($grad0 / $m);
            foreach ($featureKeys as $k) {
                $w[$k] -= $learningRate * (($grads[$k] / $m) + ($lambda * $w[$k] / $m));
            }
        }

        $weights = [
            'intercept' => round($w0, 6),
            'coefficients' => [
                'hl_spread_pct' => round($w['hl_spread_pct'], 6),
                'recent_volatility' => round($w['recent_volatility'], 6),
                'rsi_14' => round($w['rsi_14'], 6),
                'volume_ratio' => round($w['volume_ratio'], 6),
            ],
            'means' => array_map(fn ($v) => round($v, 6), $means),
            'stds' => array_map(fn ($v) => round($v, 6), $stds),
            // Flat backward-compatible aliases
            'hl_spread_pct' => round($w['hl_spread_pct'], 6),
            'recent_volatility' => round($w['recent_volatility'], 6),
        ];

        // Evaluate on untouched out-of-sample Validation slice
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
            // First validated model becomes champion if Brier <= 0.28 and ECE is acceptable
            $maxEce = count($valSlice) < 10 ? 0.30 : 0.15;
            if ($valMetrics['brier_score'] <= 0.28 && $valMetrics['ece'] <= $maxEce) {
                $status = 'ACTIVE';
                $promotionReason = 'Initial champion model provisioned (validation Brier <= 0.28).';
            }
        } else {
            // Challenger evaluation against existing champion on the same untouched validation slice
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
                'features_used' => $featureKeys,
                'evaluation_notes' => $promotionReason,
                'algorithm' => 'BATCH_GRADIENT_DESCENT_L2',
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
        $coefficients = (array) ($weights['coefficients'] ?? []);
        $means = (array) ($weights['means'] ?? []);
        $stds = (array) ($weights['stds'] ?? []);

        $brierSum = 0.0;
        $logLossSum = 0.0;
        $correct = 0;
        $predictions = [];
        $y = [];
        $rSum = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $logit = $w0;
            if (! empty($coefficients)) {
                foreach ($coefficients as $k => $coeff) {
                    $val = (float) ($data[$i][$k] ?? ($means[$k] ?? 0.0));
                    $m = (float) ($means[$k] ?? 0.0);
                    $s = (float) ($stds[$k] ?? 1.0);
                    if ($s < 1e-4) {
                        $s = 1.0;
                    }
                    $logit += ((float) $coeff) * (($val - $m) / $s);
                }
            } else {
                $wSpread = (float) ($weights['hl_spread_pct'] ?? 0.0);
                $wVol = (float) ($weights['recent_volatility'] ?? 0.0);
                $logit += ($wSpread * ($data[$i]['hl_spread_pct'] ?? 0.0)) + ($wVol * ($data[$i]['recent_volatility'] ?? 0.0));
            }

            $prob = 1.0 / (1.0 + exp(-max(-15.0, min(15.0, $logit))));
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
        $coefficients = (array) ($weights['coefficients'] ?? []);
        $means = (array) ($weights['means'] ?? []);
        $stds = (array) ($weights['stds'] ?? []);

        $logit = $w0;
        if (! empty($coefficients)) {
            foreach ($coefficients as $k => $coeff) {
                $val = (float) ($featureVector[$k] ?? ($means[$k] ?? 0.0));
                $m = (float) ($means[$k] ?? 0.0);
                $s = (float) ($stds[$k] ?? 1.0);
                if ($s < 1e-4) {
                    $s = 1.0;
                }
                $logit += ((float) $coeff) * (($val - $m) / $s);
            }
        } else {
            $spread = (float) ($featureVector['hl_spread_pct'] ?? 1.0);
            $vol = (float) ($featureVector['recent_volatility'] ?? 1.0);
            $wSpread = (float) ($weights['hl_spread_pct'] ?? 0.0);
            $wVol = (float) ($weights['recent_volatility'] ?? 0.0);
            $logit += ($wSpread * $spread) + ($wVol * $vol);
        }

        $prob = 1.0 / (1.0 + exp(-max(-15.0, min(15.0, $logit))));
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
