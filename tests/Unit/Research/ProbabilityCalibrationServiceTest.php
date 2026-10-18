<?php

declare(strict_types=1);

namespace Tests\Unit\Research;

use App\Research\Application\ProbabilityCalibrationService;
use App\Strategy\Infrastructure\Persistence\LevelInteractionForecastRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProbabilityCalibrationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_computes_brier_ece_and_reliability_bins(): void
    {
        $service = new ProbabilityCalibrationService;

        // Seed 10 forecasts
        for ($i = 0; $i < 10; $i++) {
            LevelInteractionForecastRecord::query()->create([
                'id' => (string) Str::uuid(),
                'exchange' => 'bybit',
                'category' => 'linear',
                'symbol' => 'BTCUSDT',
                'timeframe' => '15',
                'event' => 'BOUNCE',
                'side' => 'BUY',
                'triggered_at' => now('UTC')->subHours(10 - $i),
                'horizon_ends_at' => now('UTC')->subHours(6 - $i),
                'model_version' => '1.0.0',
                'configuration_hash' => 'hash_test',
                'sample_size' => 50,
                'validation_status' => 'IN_SAMPLE',
                'outcome' => $i % 2 === 0 ? 'BOUNCE' : 'REJECTION',
                'outcome_at' => now('UTC')->subHours(6 - $i),
                'success_probability' => '0.70',
                'failure_probability' => '0.30',
                'unresolved_probability' => '0.00',
                'features' => ['level_price' => '60000'],
            ]);
        }

        $result = $service->evaluateCalibration('BTCUSDT');

        self::assertSame('CALIBRATED', $result['status']);
        self::assertSame(10, $result['total_samples']);
        self::assertNotNull($result['brier_score']);
        self::assertNotNull($result['log_loss']);
        self::assertNotNull($result['ece']);
        self::assertNotEmpty($result['reliability_bins']);

        // Empty bins should have null metrics so frontend renders '—' instead of NaN%
        $emptyBins = array_filter($result['reliability_bins'], fn ($b) => $b['sample_count'] === 0);
        foreach ($emptyBins as $bin) {
            self::assertNull($bin['predicted_mean']);
            self::assertNull($bin['observed_frequency']);
            self::assertNull($bin['calibration_gap']);
        }
    }

    public function test_handles_success_failure_outcomes_and_filters_null_probabilities(): void
    {
        $service = new ProbabilityCalibrationService;

        // Seed 6 forecasts with SUCCESS/FAILURE outcomes
        for ($i = 0; $i < 6; $i++) {
            LevelInteractionForecastRecord::query()->create([
                'id' => (string) Str::uuid(),
                'exchange' => 'bybit',
                'category' => 'linear',
                'symbol' => 'ETHUSDT',
                'timeframe' => '15',
                'event' => 'resistance_rejection',
                'side' => 'SELL',
                'triggered_at' => now('UTC')->subHours(10 - $i),
                'horizon_ends_at' => now('UTC')->subHours(6 - $i),
                'model_version' => '1.0.0',
                'configuration_hash' => 'hash_eth',
                'sample_size' => 40,
                'validation_status' => 'IN_SAMPLE',
                'outcome' => $i % 2 === 0 ? 'SUCCESS' : 'FAILURE',
                'outcome_at' => now('UTC')->subHours(6 - $i),
                'success_probability' => '0.65',
                'failure_probability' => '0.35',
                'unresolved_probability' => '0.00',
                'features' => ['level_price' => '2400'],
            ]);
        }

        // Seed 2 forecasts with null success_probability (sample_size < 30)
        for ($i = 0; $i < 2; $i++) {
            LevelInteractionForecastRecord::query()->create([
                'id' => (string) Str::uuid(),
                'exchange' => 'bybit',
                'category' => 'linear',
                'symbol' => 'ETHUSDT',
                'timeframe' => '15',
                'event' => 'resistance_rejection',
                'side' => 'SELL',
                'triggered_at' => now('UTC')->subHours(3 - $i),
                'horizon_ends_at' => now('UTC')->subHours(1 - $i),
                'model_version' => '1.0.0',
                'configuration_hash' => 'hash_eth',
                'sample_size' => 14,
                'validation_status' => 'INSUFFICIENT_DATA',
                'outcome' => 'SUCCESS',
                'outcome_at' => now('UTC')->subHours(1 - $i),
                'success_probability' => null,
                'failure_probability' => null,
                'unresolved_probability' => null,
                'features' => ['level_price' => '2400'],
            ]);
        }

        $result = $service->evaluateCalibration('ETHUSDT');

        self::assertSame('CALIBRATED', $result['status']);
        self::assertSame(6, $result['total_samples']); // Only the 6 with valid probability evaluated
        self::assertNotNull($result['brier_score']);
        self::assertNotNull($result['ece']);
    }

    public function test_returns_insufficient_data_when_fewer_than_five_matured_forecasts(): void
    {
        $service = new ProbabilityCalibrationService;

        $result = $service->evaluateCalibration('BTCUSDT');

        self::assertSame('INSUFFICIENT_DATA', $result['status']);
        self::assertSame(0, $result['total_samples']);
        self::assertNull($result['brier_score']);
        self::assertEmpty($result['reliability_bins']);
    }
}
