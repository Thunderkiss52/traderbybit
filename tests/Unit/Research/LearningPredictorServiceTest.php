<?php

declare(strict_types=1);

namespace Tests\Unit\Research;

use App\Research\Application\LearningPredictorService;
use App\Research\Infrastructure\Persistence\LearningFeatureSnapshotRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LearningPredictorServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_trains_model_and_predicts_calibrated_probability(): void
    {
        $service = new LearningPredictorService;
        $now = CarbonImmutable::now();

        // Insufficient data check
        $res = $service->trainModel('TP_BEFORE_SL', 'BTCUSDT');
        self::assertNull($res);

        // Seed 12 matured snapshots
        for ($i = 0; $i < 12; $i++) {
            LearningFeatureSnapshotRecord::query()->create([
                'id' => (string) Str::uuid(),
                'symbol' => 'BTCUSDT',
                'timeframe' => '15',
                'decision_time' => $now->subDays(10 - $i),
                'available_at' => $now->subDays(10 - $i),
                'feature_schema_version' => '1.0.0',
                'strategy_id' => 'trend_pullback',
                'regime' => 'TREND_BULLISH',
                'features' => [
                    'close' => 60000.0,
                    'hl_spread_pct' => 1.5,
                    'body_spread_pct' => 0.8,
                    'recent_volatility' => 1.2,
                ],
                'label_status' => 'MATURED',
                'label_outcome' => $i % 2 === 0 ? 'TP' : 'SL',
                'label_r_multiple' => $i % 2 === 0 ? 2.0 : -1.0,
                'outcome_at' => $now->subDays(10 - $i)->addHours(2),
                'data_quality' => [
                    'exchange' => 'bybit', 'verified' => true],
            ]);
        }

        $model = $service->trainModel('TP_BEFORE_SL', 'BTCUSDT');
        self::assertNotNull($model);
        self::assertSame('ACTIVE', $model->status);
        self::assertArrayHasKey('brier_score', $model->metrics);
        self::assertArrayHasKey('ece', $model->metrics);
        self::assertNotEmpty($model->artifact_hash);

        // Predict calibrated probability
        $pred = $service->predictCalibrated('TP_BEFORE_SL', [
            'hl_spread_pct' => 1.6,
            'recent_volatility' => 1.1,
        ]);

        self::assertSame('CALIBRATED', $pred['status']);
        self::assertGreaterThanOrEqual(0.01, $pred['probability']);
        self::assertLessThanOrEqual(0.99, $pred['probability']);
    }
}
