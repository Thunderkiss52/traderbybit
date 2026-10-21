<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\Strategy\Infrastructure\Persistence\LevelInteractionForecastRecord;

final class ProbabilityCalibrationService
{
    /**
     * Compute reliability diagram bins, Brier score, ECE, and log loss for matured probability forecasts.
     *
     * @return array<string, mixed>
     */
    public function evaluateCalibration(?string $symbol = null, int $binCount = 10): array
    {
        $query = LevelInteractionForecastRecord::query()
            ->whereNotNull('outcome')
            ->whereNotNull('success_probability')
            ->whereIn('outcome', ['SUCCESS', 'FAILURE', 'BOUNCE', 'REJECTION']);

        if ($symbol) {
            $query->where('symbol', $symbol);
        }

        $forecasts = $query->get(['id', 'symbol', 'event', 'outcome', 'success_probability', 'outcome_at']);
        $excluded = 0;
        $forecasts = $forecasts->filter(function ($forecast) use (&$excluded): bool {
            $p = $forecast->success_probability;
            if (! is_numeric($p) || ! is_finite((float) $p) || (float) $p < 0.0 || (float) $p > 1.0) {
                $excluded++;

                return false;
            }

            return true;
        })->values();
        $total = $forecasts->count();

        if ($total < 5) {
            return [
                'status' => 'INSUFFICIENT_DATA',
                'scope' => 'ALL_MATURED_LEVEL_INTERACTION_FORECASTS',
                'total_samples' => $total,
                'excluded_samples' => $excluded,
                'message' => 'At least 5 matured probability forecasts are required for calibration.',
                'brier_score' => null,
                'log_loss' => null,
                'ece' => null,
                'base_rate' => null,
                'reliability_bins' => [],
            ];
        }

        // Initialize bins: [0.0 - 0.1), [0.1 - 0.2), ..., [0.9 - 1.0]
        $bins = [];
        $step = 1.0 / $binCount;
        for ($i = 0; $i < $binCount; $i++) {
            $bins[$i] = [
                'bin_index' => $i,
                'bin_lower' => round($i * $step, 2),
                'bin_upper' => round(($i + 1) * $step, 2),
                'count' => 0,
                'prob_sum' => 0.0,
                'positive_sum' => 0,
            ];
        }

        $brierSum = 0.0;
        $logLossSum = 0.0;
        $positiveCount = 0;
        $byEvent = [];

        foreach ($forecasts as $f) {
            $p = max(0.0001, min(0.9999, (float) $f->success_probability));
            $outcomeUpper = strtoupper((string) $f->outcome);
            $y = in_array($outcomeUpper, ['SUCCESS', 'BOUNCE', 'BREAKOUT'], true) ? 1 : 0;
            $eventKey = (string) ($f->event ?? 'ALL');
            $byEvent[$eventKey] ??= ['samples' => 0, 'positive_outcomes' => 0, 'brier_sum' => 0.0];
            $byEvent[$eventKey]['samples']++;
            $byEvent[$eventKey]['positive_outcomes'] += $y;
            $byEvent[$eventKey]['brier_sum'] += ($p - $y) ** 2;

            if ($y === 1) {
                $positiveCount++;
            }

            // Brier error: (p - y)^2
            $brierSum += ($p - $y) ** 2;

            // Log loss: -(y * ln(p) + (1 - y) * ln(1 - p))
            $logLossSum += -($y * log($p) + (1 - $y) * log(1.0 - $p));

            // Bin assignment
            $binIdx = min($binCount - 1, (int) floor($p / $step));
            $bins[$binIdx]['count']++;
            $bins[$binIdx]['prob_sum'] += $p;
            $bins[$binIdx]['positive_sum'] += $y;
        }

        $brierScore = round($brierSum / $total, 6);
        $logLoss = round($logLossSum / $total, 6);
        $baseRate = round($positiveCount / $total, 4);

        // Expected Calibration Error (ECE)
        $eceSum = 0.0;
        $reliabilityBins = [];

        foreach ($bins as $b) {
            $count = $b['count'];
            $meanProb = $count > 0 ? round($b['prob_sum'] / $count, 4) : null;
            $empiricalFreq = $count > 0 ? round($b['positive_sum'] / $count, 4) : null;
            $calGap = $count > 0 && $meanProb !== null && $empiricalFreq !== null ? round(abs($empiricalFreq - $meanProb), 4) : null;

            if ($count > 0 && $meanProb !== null && $empiricalFreq !== null) {
                $eceSum += ($count / $total) * abs($empiricalFreq - $meanProb);
            }

            $reliabilityBins[] = [
                'bin_range' => "{$b['bin_lower']} - {$b['bin_upper']}",
                'bin' => "{$b['bin_lower']} - {$b['bin_upper']}",
                'sample_count' => $count,
                'count' => $count,
                'predicted_mean' => $meanProb,
                'mean_predicted' => $meanProb,
                'observed_frequency' => $empiricalFreq,
                'empirical_rate' => $empiricalFreq,
                'calibration_gap' => $calGap,
                'gap' => $calGap,
            ];
        }

        $ece = round($eceSum, 6);

        $byEvent = array_map(static fn (array $row): array => [
            'samples' => $row['samples'],
            'positive_outcomes' => $row['positive_outcomes'],
            'base_rate' => round($row['positive_outcomes'] / max(1, $row['samples']), 4),
            'brier_score' => round($row['brier_sum'] / max(1, $row['samples']), 6),
        ], $byEvent);

        return [
            'status' => 'CALIBRATED',
            'scope' => 'ALL_MATURED_LEVEL_INTERACTION_FORECASTS',
            'population_note' => 'Live location-forecast calibration. Model artifact validation metrics are a separate holdout population and must not be compared one-to-one.',
            'total_samples' => $total,
            'excluded_samples' => $excluded,
            'positive_outcomes' => $positiveCount,
            'brier_score' => $brierScore,
            'log_loss' => $logLoss,
            'ece' => $ece,
            'base_rate' => $baseRate,
            'by_event' => $byEvent,
            'reliability_bins' => $reliabilityBins,
        ];
    }
}
