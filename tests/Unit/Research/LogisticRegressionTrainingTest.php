<?php

declare(strict_types=1);

namespace Tests\Unit\Research;

use App\Research\Application\LearningPredictorService;
use App\Research\Infrastructure\Persistence\LearningFeatureSnapshotRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LogisticRegressionTrainingTest extends TestCase
{
    use RefreshDatabase;

    private LearningPredictorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(LearningPredictorService::class);
    }

    public function test_train_model_optimizes_weights_via_gradient_descent_not_hardcoded(): void
    {
        $symbol = 'BTCUSDT';
        $base = CarbonImmutable::parse('2026-10-18 00:00:00');

        // Seed 30 observations with high positive correlation between hl_spread_pct and TP outcome
        for ($i = 0; $i < 30; $i++) {
            $isTp = $i % 2 === 0;
            // When isTp is true, spread is high; when false, spread is low
            $spread = $isTp ? 3.5 : 0.5;

            LearningFeatureSnapshotRecord::query()->create([
                'id' => (string) Str::uuid(),
                'symbol' => $symbol,
                'timeframe' => '15',
                'decision_time' => $base->addHours($i * 2), // 2 hours apart so they mature cleanly
                'available_at' => $base->addHours($i * 2)->addMinutes(15),
                'feature_schema_version' => '1.0.0',
                'strategy_id' => 'baseline',
                'regime' => 'BULLISH_TREND',
                'features' => [
                    'close' => 60000.0 + ($i * 10),
                    'hl_spread_pct' => $spread,
                    'body_spread_pct' => 0.5,
                    'recent_volatility' => 0.8,
                    'rsi_14' => $isTp ? 65.0 : 35.0,
                    'volume_ratio' => $isTp ? 1.8 : 0.6,
                ],
                'label_status' => 'MATURED',
                'label_outcome' => $isTp ? 'TP' : 'SL',
                'label_r_multiple' => $isTp ? 1.87 : -1.13,
                'outcome_at' => $base->addHours($i * 2)->addMinutes(60),
                'data_quality' => [
                    'candles_evaluated' => 20,
                    'missing_bars' => 0,
                    'no_look_ahead_verified' => true,
                ],
            ]);
        }

        $artifact = $this->service->trainModel('TP_BEFORE_SL', $symbol);

        $this->assertNotNull($artifact);
        $this->assertSame('LOGISTIC_REGRESSION', $artifact->model_type);
        $this->assertSame('BATCH_GRADIENT_DESCENT_L2', $artifact->parameters['algorithm']);

        $weights = (array) $artifact->weights;
        $this->assertArrayHasKey('coefficients', $weights);
        $this->assertArrayHasKey('means', $weights);
        $this->assertArrayHasKey('stds', $weights);

        // Coefficients must be optimized from data: since high hl_spread and RSI correlate with TP,
        // their trained coefficients must be strictly positive, not arbitrary constants like 0.05
        $this->assertGreaterThan(0.0, (float) $weights['coefficients']['hl_spread_pct']);
        $this->assertGreaterThan(0.0, (float) $weights['coefficients']['rsi_14']);

        // Check calibrated probability prediction uses optimized weights
        $predHigh = $this->service->predictCalibrated('TP_BEFORE_SL', [
            'hl_spread_pct' => 4.0,
            'rsi_14' => 70.0,
            'recent_volatility' => 0.8,
            'volume_ratio' => 2.0,
        ]);
        $predLow = $this->service->predictCalibrated('TP_BEFORE_SL', [
            'hl_spread_pct' => 0.2,
            'rsi_14' => 30.0,
            'recent_volatility' => 0.8,
            'volume_ratio' => 0.4,
        ]);

        $this->assertSame('CALIBRATED', $predHigh['status']);
        $this->assertSame('CALIBRATED', $predLow['status']);
        $this->assertGreaterThan($predLow['probability'], $predHigh['probability']);
    }

    public function test_train_model_fails_closed_when_data_is_insufficient(): void
    {
        // 5 observations is under the minimum threshold
        $symbol = 'ETHUSDT';
        $base = CarbonImmutable::parse('2026-10-18 00:00:00');
        for ($i = 0; $i < 5; $i++) {
            LearningFeatureSnapshotRecord::query()->create([
                'id' => (string) Str::uuid(),
                'symbol' => $symbol,
                'timeframe' => '15',
                'decision_time' => $base->addHours($i),
                'available_at' => $base->addHours($i)->addMinutes(15),
                'feature_schema_version' => '1.0.0',
                'strategy_id' => 'baseline',
                'regime' => 'BULLISH_TREND',
                'features' => ['hl_spread_pct' => 1.0, 'recent_volatility' => 0.5, 'rsi_14' => 50.0, 'volume_ratio' => 1.0],
                'label_status' => 'MATURED',
                'label_outcome' => 'TP',
                'label_r_multiple' => 1.87,
                'outcome_at' => $base->addHours($i)->addMinutes(30),
                'data_quality' => ['no_look_ahead_verified' => true],
            ]);
        }

        $artifact = $this->service->trainModel('TP_BEFORE_SL', $symbol);
        $this->assertNull($artifact);
    }
}
