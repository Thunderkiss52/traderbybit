<?php

declare(strict_types=1);

namespace Tests\Unit\Research;

use App\Research\Application\LearningFeatureStoreService;
use App\Research\Application\LearningPredictorService;
use PHPUnit\Framework\TestCase;

final class LearningSafetyInvariantsTest extends TestCase
{
    public function test_rsi_is_directionally_correct(): void
    {
        self::assertSame(100.0, LearningFeatureStoreService::rsiFromAverages(10.0, 0.0));
        self::assertSame(0.0, LearningFeatureStoreService::rsiFromAverages(0.0, 10.0));
        self::assertSame(50.0, LearningFeatureStoreService::rsiFromAverages(0.0, 0.0));
    }

    public function test_model_identity_is_isolated_by_exchange_side_strategy_and_version(): void
    {
        $args = ['TP_BEFORE_SL', 'BTCUSDT', 'bybit', 'baseline', '15', 'BUY', 'linear', '1.2.0'];
        $original = LearningPredictorService::scopedModelName(...$args);
        self::assertLessThanOrEqual(64, strlen($original));
        foreach ([2 => 'binance', 3 => 'trend_pullback', 4 => '5', 5 => 'SELL', 6 => 'spot', 7 => '1.3.0'] as $index => $replacement) {
            $alternative = $args;
            $alternative[$index] = $replacement;
            self::assertNotSame($original, LearningPredictorService::scopedModelName(...$alternative));
        }
    }

    public function test_rejects_unrecognized_candle_interval(): void
    {
        self::assertSame(15, LearningFeatureStoreService::intervalMinutes('15'));
        self::assertSame(60, LearningFeatureStoreService::intervalMinutes('1h'));
        self::assertNull(LearningFeatureStoreService::intervalMinutes('1month'));
        self::assertNull(LearningFeatureStoreService::intervalMinutes('0'));
    }
}
