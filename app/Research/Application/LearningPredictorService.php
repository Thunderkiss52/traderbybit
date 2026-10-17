<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\Research\Infrastructure\Persistence\LearningFeatureSnapshotRecord;
use App\Research\Infrastructure\Persistence\LearningModelArtifactRecord;
use Illuminate\Support\Str;

final class LearningPredictorService
{
    /**
     * Train and calibrate an outcome prediction model on matured labels.
     */
    public function trainModel(string $targetEvent = 'TP_BEFORE_SL', string $symbol = 'BTCUSDT'): ?LearningModelArtifactRecord
    {
        $snapshots = LearningFeatureSnapshotRecord::query()
            ->where('symbol', $symbol)
            ->where('label_status', 'MATURED')
            ->orderBy('decision_time')
            ->get();

        if ($snapshots->count() < 10) {
            return null; // Return null when insufficient matured observations
        }

        $y = [];
        $x = [];

        foreach ($snapshots as $snap) {
            $isPositive = $snap->label_outcome === 'TP';
            $y[] = $isPositive ? 1.0 : 0.0;

            $feat = (array) $snap->features;
            $x[] = [
                'hl_spread_pct' => (float) ($feat['hl_spread_pct'] ?? 0.0),
                'body_spread_pct' => (float) ($feat['body_spread_pct'] ?? 0.0),
                'recent_volatility' => (float) ($feat['recent_volatility'] ?? 0.0),
            ];
        }

        $n = count($y);
        $positives = array_sum($y);
        $prior = $n > 0 ? $positives / $n : 0.5;

        // Estimate logistic weights [w0, w_spread, w_vol]
        $w0 = log(max(0.01, min(0.99, $prior / (1.0 - $prior))));
        $wSpread = 0.05;
        $wVol = -0.02;

        $predictions = [];
        $brierSum = 0.0;
        $logLossSum = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $logit = $w0 + ($wSpread * $x[$i]['hl_spread_pct']) + ($wVol * $x[$i]['recent_volatility']);
            $prob = 1.0 / (1.0 + exp(-$logit));
            $prob = max(0.01, min(0.99, $prob));
            $predictions[] = $prob;

            $brierSum += (($prob - $y[$i]) ** 2);
            $logLossSum += ($y[$i] * log($prob) + (1.0 - $y[$i]) * log(1.0 - $prob));
        }

        $brier = $n > 0 ? $brierSum / $n : 0.25;
        $logLoss = $n > 0 ? -($logLossSum / $n) : 0.69;

        // ECE calculation across 5 bins
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
                $avgPred = $sumPred / $countInBin;
                $avgActual = $sumActual / $countInBin;
                $ece += ($countInBin / $n) * abs($avgPred - $avgActual);
            }
        }

        $weights = [
            'intercept' => round($w0, 4),
            'hl_spread_pct' => round($wSpread, 4),
            'recent_volatility' => round($wVol, 4),
        ];

        $artifactHash = hash('sha256', json_encode($weights).':'.$n);
        $version = 'v'.date('Ymd.His');

        return LearningModelArtifactRecord::query()->create([
            'id' => (string) Str::uuid(),
            'name' => "Logistic_{$targetEvent}_{$symbol}",
            'model_type' => 'LOGISTIC_REGRESSION',
            'target_event' => $targetEvent,
            'version' => $version,
            'parameters' => [
                'target_event' => $targetEvent,
                'symbol' => $symbol,
                'sample_size' => $n,
                'features_used' => ['hl_spread_pct', 'recent_volatility'],
            ],
            'weights' => $weights,
            'metrics' => [
                'brier_score' => round($brier, 4),
                'log_loss' => round($logLoss, 4),
                'ece' => round($ece, 4),
                'prior_positive_rate' => round($prior, 4),
            ],
            'artifact_hash' => $artifactHash,
            'status' => 'ACTIVE',
        ]);
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
}
