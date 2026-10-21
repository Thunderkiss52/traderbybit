<?php

declare(strict_types=1);

namespace Tests\Unit\Research;

use App\Research\Application\RiskCapitalMetricsService;
use Tests\TestCase;

final class RiskCapitalMetricsServiceTest extends TestCase
{
    public function test_bartlett_ess_handles_short_series(): void
    {
        $service = new RiskCapitalMetricsService;
        [$ess, $clamped] = $service->calculateBartlettEss([0.1, -0.2, 0.05]);
        $this->assertNotNull($ess);
        $this->assertFalse($clamped);
    }

    public function test_bartlett_ess_clamps_on_negative_denom_or_high_autocorrelation(): void
    {
        $service = new RiskCapitalMetricsService;
        // Highly autocorrelated constant-like series
        $series = [1.0, 1.0, 1.0, 1.0, 1.0, 1.0];
        [$ess, $clamped] = $service->calculateBartlettEss($series);
        $this->assertNotNull($ess);
        $this->assertGreaterThanOrEqual(1.0, $ess);
        $this->assertLessThanOrEqual(6.0, $ess);
        $this->assertIsBool($clamped);
    }

    public function test_bartlett_ess_returns_null_for_empty(): void
    {
        $service = new RiskCapitalMetricsService;
        [$ess, $clamped] = $service->calculateBartlettEss([]);
        $this->assertNull($ess);
        $this->assertFalse($clamped);
    }

    /**
     * The estimator must match an independently calculated reference.
     *
     * The reference below is a separate, naive implementation of the normative
     * formula (PROFIT-1 Rev 2.1 §8.2) written directly from the definition:
     *
     *   rho_k = SUM_{t=0}^{n-k-1} (x_t - x_bar)(x_{t+k} - x_bar) / SUM_t (x_t - x_bar)^2
     *   ESS   = n / (1 + 2 * SUM_{k=1..floor(n/4)} rho_k)
     */
    public function test_bartlett_ess_matches_independently_calculated_reference(): void
    {
        $service = new RiskCapitalMetricsService;
        $n = 44;
        $series = [];
        // Deterministic, autocorrelated, non-constant series.
        for ($i = 0; $i < $n; $i++) {
            $series[] = sin($i / 3.0) * 2.0 + cos($i / 7.0) + (($i % 5) - 2) * 0.1;
        }

        $mean = array_sum($series) / $n;
        $sumSquares = 0.0;
        foreach ($series as $value) {
            $sumSquares += ($value - $mean) ** 2;
        }
        $rhoSum = 0.0;
        for ($k = 1; $k <= intdiv($n, 4); $k++) {
            $covariance = 0.0;
            for ($t = 0; $t < $n - $k; $t++) {
                $covariance += ($series[$t] - $mean) * ($series[$t + $k] - $mean);
            }
            $rhoSum += $covariance / $sumSquares;
        }
        $expected = $n / (1 + 2 * $rhoSum);

        [$ess, $clamped] = $service->calculateBartlettEss($series);

        $this->assertNotNull($ess);
        $this->assertFalse($clamped);
        $this->assertEqualsWithDelta($expected, $ess, 1e-9);
        $this->assertLessThan((float) $n, $ess, 'Autocorrelated series must yield an ESS below the raw sample size.');
    }

    public function test_bartlett_ess_of_uncorrelated_series_is_clamped_to_sample_size(): void
    {
        $service = new RiskCapitalMetricsService;
        mt_srand(42);
        $n = 64;
        $series = [];
        for ($i = 0; $i < $n; $i++) {
            $series[] = mt_rand() / mt_getrandmax() - 0.5;
        }

        [$ess, $clamped] = $service->calculateBartlettEss($series);

        $this->assertNotNull($ess);
        // The raw Bartlett value for this series exceeds n and must be clamped to [1, n]
        // with the clamping explicitly recorded (PROFIT-1 Rev 2.1 §8.2).
        $this->assertTrue($clamped);
        $this->assertSame((float) $n, $ess);
    }

    public function test_bartlett_ess_of_mildly_autocorrelated_series_is_below_sample_size(): void
    {
        $service = new RiskCapitalMetricsService;
        mt_srand(7);
        $n = 64;
        $series = [];
        $value = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $noise = (mt_rand() / mt_getrandmax() - 0.5) * 2.0;
            $value = 0.5 * $value + $noise;
            $series[] = $value;
        }

        [$ess, $clamped] = $service->calculateBartlettEss($series);

        $this->assertNotNull($ess);
        $this->assertFalse($clamped);
        $this->assertGreaterThan(1.0, $ess);
        $this->assertLessThan((float) $n, $ess);
    }

    public function test_bartlett_ess_reports_clamped_for_constant_series(): void
    {
        $service = new RiskCapitalMetricsService;
        [$ess, $clamped] = $service->calculateBartlettEss([2.0, 2.0, 2.0, 2.0, 2.0, 2.0, 2.0, 2.0]);
        $this->assertSame(1.0, $ess);
        $this->assertTrue($clamped);
    }
}
