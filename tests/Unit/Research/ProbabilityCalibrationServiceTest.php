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
    }
}
