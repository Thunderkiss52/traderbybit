<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\Research\Infrastructure\Persistence\LearningFeatureSnapshotRecord;
use App\Research\Infrastructure\Persistence\LearningModelArtifactRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class LearningPredictorService
{
    private readonly AutonomyPermissionService $autonomy;

    public function __construct(
        ?AutonomyPermissionService $autonomy = null
    ) {
        $this->autonomy = $autonomy ?? app(AutonomyPermissionService::class);
    }

    private function assertTrainingAllowed(): void
    {
        if (! $this->autonomy->isAllowed('retrain_models', persist: false)) {
            throw new \DomainException('Model training is blocked by effective autonomy permissions.');
        }
    }

    /**
     * Train and calibrate an outcome prediction model on matured labels.
     * Uses a purged temporal train/validation split and executes champion/challenger evaluation.
     */
    public function trainModel(string $targetEvent = 'TP_BEFORE_SL', string $symbol = 'BTCUSDT', string $exchange = 'bybit', string $strategyId = 'baseline', string $timeframe = '15', string $side = 'BUY', string $category = 'linear', string $strategyVersion = 'UNVERSIONED'): ?LearningModelArtifactRecord
    {
        $this->assertTrainingAllowed();
        $modelName = self::scopedModelName($targetEvent, $symbol, $exchange, $strategyId, $timeframe, $side, $category, $strategyVersion);

        // Legacy snapshots without a verified exchange are deliberately excluded.
        // They must not be presumed Bybit when the same symbol exists on Binance.
        $snapshots = LearningFeatureSnapshotRecord::query()
            ->where('symbol', $symbol)
            ->where('data_quality->exchange', $exchange)
            ->where('data_quality->category', $category)
            ->where('data_quality->side', $side)
            ->where('data_quality->label_policy_version', 'SIGNAL_PROXY_V2')
            ->where('data_quality->strategy_version', $strategyVersion)
            ->where('strategy_id', $strategyId)
            ->where('timeframe', $timeframe)
            ->where('label_status', 'MATURED')
            ->orderBy('decision_time')
            ->get();

        if ($snapshots->count() < 12) {
            return null; // Minimum 12 matured observations required
        }

        $allData = [];
        foreach ($snapshots as $snap) {
            $row = $this->maturedObservation($snap);
            if ($row === null) {
                return null; // Incomplete, immature or noncausal labels cannot train a model.
            }
            $allData[] = $row;
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
        $purgedCount = 0;
        foreach ($rawTrain as $item) {
            $outcomeTime = $item['outcome_at'] ?? $item['decision_time']->addMinutes(60);
            if ($outcomeTime <= $valStartTime) {
                $trainSlice[] = $item;
            } else {
                $purgedCount++;
            }
        }

        $fallbackActivated = false;
        if (count($trainSlice) < 5) {
            $fallbackActivated = true;
            // Purging fallback: rather than leaking future labels into training, select the initial 5 training samples
            // and dynamically shift the validation set to start strictly AFTER the latest outcome of the training slice.
            $fallbackTrain = array_slice($allData, 0, 5);
            $latestTrainOutcome = null;
            foreach ($fallbackTrain as $tItem) {
                $ot = $tItem['outcome_at'] ?? $tItem['decision_time']->addMinutes(60);
                if ($latestTrainOutcome === null || $ot > $latestTrainOutcome) {
                    $latestTrainOutcome = $ot;
                }
            }

            $fallbackVal = [];
            for ($k = 5; $k < $totalCount; $k++) {
                if ($allData[$k]['decision_time'] >= $latestTrainOutcome) {
                    $fallbackVal[] = $allData[$k];
                }
            }

            if (count($fallbackVal) >= 3) {
                $trainSlice = $fallbackTrain;
                $valSlice = $fallbackVal;
                $valStartTime = $valSlice[0]['decision_time'];
            }
        }

        // Untouched validation data must be distinct and non-empty; NEVER reuse training slice
        if (count($valSlice) < 3 || count($trainSlice) < 5) {
            return null;
        }

        // Strict guard: verify zero future label leakage
        foreach ($trainSlice as $tItem) {
            $ot = $tItem['outcome_at'] ?? $tItem['decision_time']->addMinutes(60);
            if ($ot > $valStartTime) {
                return null;
            }
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
            if (! is_finite($mean) || ! is_finite($std)) {
                return null;
            }
            if ($std < 1e-4) {
                $std = 1.0;
            }
            $means[$k] = $mean;
            $stds[$k] = $std;
        }

        // Fit logistic model via Batch Gradient Descent with L2 regularization
        $trainY = array_column($trainSlice, 'y');
        $positives = array_sum($trainY);
        // A one-class training set cannot identify predictive coefficients.
        // Fail closed rather than training/promoting an uninformative model.
        if ($positives <= 0.0 || $positives >= $m) {
            return null;
        }
        $prior = $positives / $m;
        $boundedPrior = max(0.05, min(0.95, $prior));
        $w0 = log($boundedPrior / (1.0 - $boundedPrior));
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

        $artifactHash = hash('sha256', json_encode($weights).':'.$totalCount.':'.$modelName);
        $version = 'v'.date('Ymd.His');

        // Check if active champion model exists
        $champion = LearningModelArtifactRecord::query()
            ->where('target_event', $targetEvent)
            ->where('name', $modelName)
            ->where('parameters->exchange', $exchange)
            ->where('status', 'ACTIVE')
            ->latest('created_at')
            ->first();

        // Compute training-only class prevalence (constant forecast baseline)
        $trainPositives = count(array_filter($trainSlice, fn ($r) => (int) round((float) ($r['y'] ?? $r['label'] ?? 0)) === 1));
        $trainCount = count($trainSlice);
        $trainingPrevalence = $trainCount > 0 ? $trainPositives / $trainCount : 0.5;

        // Evaluate frozen constant forecast baseline on chronological OOS validation slice
        $baselineOosLossSum = 0.0;
        foreach ($valSlice as $vRow) {
            $yTrue = (float) ($vRow['y'] ?? $vRow['label'] ?? 0);
            $baselineOosLossSum += ($trainingPrevalence - $yTrue) ** 2;
        }
        $baselineBrier = count($valSlice) > 0 ? round($baselineOosLossSum / count($valSlice), 4) : 0.25;
        $brierReductionVsBaseline = round($baselineBrier - $valMetrics['brier_score'], 4);
        $brierSkillScore = $baselineBrier > 0.0 ? round(1.0 - ($valMetrics['brier_score'] / $baselineBrier), 4) : 0.0;
        $improvesBaseline = $brierReductionVsBaseline >= 0.0;

        $this->assertTrainingAllowed(); // Recheck after computation, before any champion/artifact writes.
        $status = 'CANDIDATE';
        $promotionReason = null;

        if (! $champion) {
            // First validated model becomes champion if Brier <= 0.28, ECE is acceptable, and beats/matches constant baseline
            $maxEce = count($valSlice) < 10 ? 0.30 : 0.15;
            if ($valMetrics['brier_score'] <= 0.28 && $valMetrics['ece'] <= $maxEce && $improvesBaseline) {
                $status = 'ACTIVE';
                $promotionReason = "Initial champion model provisioned (validation Brier {$valMetrics['brier_score']} <= 0.28, baseline {$baselineBrier}).";
            } else {
                $status = 'CANDIDATE_REJECTED';
                $promotionReason = "Initial model failed validation gates: Brier {$valMetrics['brier_score']}, Baseline {$baselineBrier}, ECE {$valMetrics['ece']}.";
            }
        } else {
            // Challenger evaluation against existing champion on the same untouched validation slice
            $champWeights = (array) $champion->weights;
            $champValMetrics = $this->evaluateDataset($valSlice, $champWeights);

            $improvesBrier = $valMetrics['brier_score'] < $champValMetrics['brier_score'];
            $improvesLogLoss = $valMetrics['log_loss'] <= $champValMetrics['log_loss'];

            if ($improvesBrier && $improvesLogLoss && $improvesBaseline) {
                $status = 'ACTIVE';
                $promotionReason = "Challenger outperformed champion on holdout: Brier {$valMetrics['brier_score']} vs {$champValMetrics['brier_score']}, beats baseline {$baselineBrier}.";
                // Proxy labels are not executed ledger evidence. Never retire
                // an active model using only hypothetical signal outcomes.
            } else {
                $status = 'CHALLENGER_REJECTED';
                $promotionReason = "Challenger failed to beat champion and baseline on holdout: Brier {$valMetrics['brier_score']} vs {$champValMetrics['brier_score']} (baseline {$baselineBrier}).";
            }
        }

        if ($status === 'ACTIVE') {
            $status = 'CANDIDATE';
            $promotionReason = 'Directional proxy-label models require independent executed-ledger validation before activation.';
        }
        $candidate = LearningModelArtifactRecord::query()->create([
            'id' => (string) Str::uuid(),
            'name' => $modelName,
            'model_type' => 'LOGISTIC_REGRESSION',
            'target_event' => $targetEvent,
            'version' => $version,
            'parameters' => [
                'target_event' => $targetEvent,
                'symbol' => $symbol,
                'exchange' => $exchange,
                'label_source' => 'DIRECTIONAL_SIGNAL_PROXY_V2',
                'strategy_id' => $strategyId,
                'strategy_version' => $strategyVersion,
                'timeframe' => $timeframe,
                'side' => $side,
                'category' => $category,
                'feature_schema_version' => '1.0.0',
                'total_sample_size' => $totalCount,
                'train_sample_size' => count($trainSlice),
                'validation_sample_size' => count($valSlice),
                'features_used' => $featureKeys,
                'evaluation_notes' => $promotionReason,
                'algorithm' => 'BATCH_GRADIENT_DESCENT_L2',
                'purging_diagnostics' => [
                    'purged_count' => $purgedCount,
                    'fallback_activated' => $fallbackActivated,
                    'future_label_leakage_detected' => false,
                ],
            ],
            'weights' => $weights,
            'metrics' => [
                'brier_score' => $valMetrics['brier_score'],
                'log_loss' => $valMetrics['log_loss'],
                'ece' => $valMetrics['ece'],
                'accuracy' => $valMetrics['accuracy'],
                'prior_positive_rate' => round($prior, 4),
                'training_prevalence' => round($trainingPrevalence, 4),
                'baseline_brier_score' => $baselineBrier,
                'brier_reduction_vs_baseline' => $brierReductionVsBaseline,
                'brier_skill_score' => $brierSkillScore,
                'validation_r_multiple' => $valMetrics['expected_r'],
            ],
            'artifact_hash' => $artifactHash,
            'status' => $status,
        ]);

        if ($promotionReason !== null && $status !== 'CANDIDATE') {
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

    /** @return array<string, mixed>|null */
    private function maturedObservation(LearningFeatureSnapshotRecord $snapshot): ?array
    {
        $rMultiple = $snapshot->getRawOriginal('label_r_multiple');
        if ($snapshot->feature_schema_version !== '1.0.0'
            || ($snapshot->data_quality['no_look_ahead_verified'] ?? false) !== true
            || ! in_array($snapshot->label_outcome, ['TP', 'SL', 'TIMEOUT'], true)
            || $snapshot->decision_time === null || $snapshot->available_at === null
            || $snapshot->outcome_at === null
            || $snapshot->available_at->greaterThan($snapshot->decision_time)
            || $snapshot->outcome_at->lessThan($snapshot->decision_time)
            || $snapshot->outcome_at->greaterThan(CarbonImmutable::now('UTC'))
            || ! is_numeric($rMultiple)
            || ! is_finite((float) $rMultiple)) {
            return null;
        }
        $features = (array) $snapshot->features;
        $row = [
            'decision_time' => $snapshot->decision_time,
            'outcome_at' => $snapshot->outcome_at,
            'y' => $snapshot->label_outcome === 'TP' ? 1.0 : 0.0,
            'r_multiple' => (float) $rMultiple,
        ];
        foreach (['hl_spread_pct', 'recent_volatility', 'rsi_14', 'volume_ratio'] as $key) {
            if (! isset($features[$key]) || ! is_numeric($features[$key]) || ! is_finite((float) $features[$key])) {
                return null;
            }
            $row[$key] = (float) $features[$key];
        }

        return $row;
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
    public function detectConceptDrift(string $symbol = 'BTCUSDT', string $targetEvent = 'TP_BEFORE_SL', string $exchange = 'bybit', string $strategyId = 'baseline', string $timeframe = '15', string $side = 'BUY', string $category = 'linear', string $strategyVersion = 'UNVERSIONED'): array
    {
        $modelName = self::scopedModelName($targetEvent, $symbol, $exchange, $strategyId, $timeframe, $side, $category, $strategyVersion);
        $champion = LearningModelArtifactRecord::query()
            ->where('target_event', $targetEvent)
            ->where('name', $modelName)
            ->where('parameters->exchange', $exchange)
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
            ->where('data_quality->exchange', $exchange)
            ->where('data_quality->category', $category)
            ->where('data_quality->side', $side)
            ->where('data_quality->label_policy_version', 'SIGNAL_PROXY_V2')
            ->where('data_quality->strategy_version', $strategyVersion)
            ->where('strategy_id', $strategyId)
            ->where('timeframe', $timeframe)
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
            $row = $this->maturedObservation($snap);
            if ($row !== null) {
                $data[] = $row;
            }
        }
        if (count($data) < 10) {
            return [
                'drift_detected' => false,
                'status' => 'INSUFFICIENT_RECENT_DATA',
                'sample_size' => count($data),
                'recommendation' => 'WAIT',
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
    public function predictCalibrated(string $targetEvent, array $featureVector, string $symbol = 'BTCUSDT', string $exchange = 'bybit', string $strategyId = 'baseline', string $timeframe = '15', string $side = 'BUY', string $category = 'linear', string $strategyVersion = 'UNVERSIONED'): array
    {
        $modelName = self::scopedModelName($targetEvent, $symbol, $exchange, $strategyId, $timeframe, $side, $category, $strategyVersion);
        $model = LearningModelArtifactRecord::query()
            ->where('target_event', $targetEvent)
            ->where('name', $modelName)
            ->where('parameters->exchange', $exchange)
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

        if (! isset($weights['intercept']) || ! is_numeric($weights['intercept']) || ! is_finite($w0)) {
            throw new \DomainException('Model intercept must be finite.');
        }
        $requiredCoefficients = $coefficients !== [] ? $coefficients : array_intersect_key($weights, array_flip(['hl_spread_pct', 'recent_volatility']));
        foreach ($requiredCoefficients as $key => $coefficient) {
            if (! is_numeric($coefficient) || ! is_finite((float) $coefficient)
                || ! isset($featureVector[$key]) || ! is_numeric($featureVector[$key]) || ! is_finite((float) $featureVector[$key])
                || (isset($means[$key]) && (! is_numeric($means[$key]) || ! is_finite((float) $means[$key])))
                || (isset($stds[$key]) && (! is_numeric($stds[$key]) || ! is_finite((float) $stds[$key]) || (float) $stds[$key] <= 0.0))) {
                throw new \DomainException('Model inference requires finite features and valid scaling.');
            }
        }

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

        if (! is_finite($logit)) {
            throw new \DomainException('Model inference produced a nonfinite score.');
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

    /**
     * Rollback the active champion model to the previous champion (or retire it if none exists).
     */
    public function rollbackModel(string $targetEvent = 'TP_BEFORE_SL', string $symbol = 'BTCUSDT', string $reason = 'Manual rollback or concept drift detected', string $exchange = 'bybit', string $strategyId = 'baseline', string $timeframe = '15', string $side = 'BUY', string $category = 'linear', string $strategyVersion = 'UNVERSIONED'): ?LearningModelArtifactRecord
    {
        $modelName = self::scopedModelName($targetEvent, $symbol, $exchange, $strategyId, $timeframe, $side, $category, $strategyVersion);
        $current = LearningModelArtifactRecord::query()
            ->where('target_event', $targetEvent)
            ->where('name', $modelName)
            ->where('parameters->exchange', $exchange)
            ->where('status', 'ACTIVE')
            ->latest('created_at')
            ->first();

        if ($current === null) {
            return null;
        }

        $previous = LearningModelArtifactRecord::query()
            ->where('target_event', $targetEvent)
            ->where('name', $modelName)
            ->where('parameters->exchange', $exchange)
            ->where('id', '!=', $current->id)
            ->whereIn('status', ['SUPERSEDED', 'RETIRED', 'STANDBY'])
            ->latest('created_at')
            ->first();

        $current->update(['status' => 'ROLLED_BACK']);

        $this->recordAutonomyEvent(
            action: 'MODEL_ROLLBACK',
            targetId: $current->id,
            prevState: 'ACTIVE',
            newState: 'ROLLED_BACK',
            reason: $reason,
            evidence: ['target_event' => $targetEvent, 'symbol' => $symbol, 'exchange' => $exchange, 'previous_model_id' => $previous?->id]
        );

        if ($previous !== null) {
            $previous->update(['status' => 'ACTIVE']);
            $this->recordAutonomyEvent(
                action: 'CHAMPION_RESTORED',
                targetId: $previous->id,
                prevState: 'SUPERSEDED',
                newState: 'ACTIVE',
                reason: "Restored champion after rollback of {$current->id}: {$reason}",
                evidence: ['target_event' => $targetEvent, 'symbol' => $symbol, 'exchange' => $exchange]
            );
        }

        return $previous;
    }

    /**
     * Scope model identity to its exchange. Legacy Bybit names remain visible
     * for audit, but unscoped artifacts cannot be used for calibration.
     */
    public static function scopedModelName(string $targetEvent, string $symbol, string $exchange, string $strategyId = 'baseline', string $timeframe = '15', string $side = 'BUY', string $category = 'linear', string $strategyVersion = 'UNVERSIONED'): string
    {
        $identity = [$targetEvent, $symbol, $exchange, $strategyId, $strategyVersion, $timeframe, $side, $category, 'SIGNAL_PROXY_V2'];
        foreach ($identity as $field) {
            if (! preg_match('/^[a-zA-Z0-9._-]{1,64}$/D', $field)) {
                throw new \InvalidArgumentException('Invalid ML cohort identity.');
            }
        }
        $prefix = substr('Logistic_'.$targetEvent.'_'.$symbol.'_'.$exchange, 0, 44);

        return $prefix.'_'.substr(hash('sha256', implode('|', $identity)), 0, 16);
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
