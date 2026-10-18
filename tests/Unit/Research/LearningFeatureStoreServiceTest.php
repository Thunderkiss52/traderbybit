<?php

declare(strict_types=1);

namespace Tests\Unit\Research;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\Research\Application\LearningFeatureStoreService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LearningFeatureStoreServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_assembles_point_in_time_snapshot_and_matures_label(): void
    {
        $service = new LearningFeatureStoreService;
        $now = CarbonImmutable::now();
        $t0 = $now->subHours(5);

        // Seed 12 closed candles strictly <= t0
        for ($i = 0; $i < 12; $i++) {
            CandleRecord::query()->create([
                'exchange' => 'bybit',
                'category' => 'linear',
                'symbol' => 'BTCUSDT',
                'interval' => '15',
                'open_time' => $t0->subMinutes((12 - $i) * 15),
                'open' => '60000',
                'high' => '60500',
                'low' => '59800',
                'close' => (string) (60000 + ($i * 20)),
                'volume' => '150',
                'turnover' => '9000000',
                'is_closed' => true,
            ]);
        }

        $snapshot = $service->assembleSnapshot('BTCUSDT', '15', $t0, 'trend_pullback', 'TREND_BULLISH');

        self::assertNotNull($snapshot);
        self::assertSame('PENDING', $snapshot->label_status);
        self::assertSame('1.0.0', $snapshot->feature_schema_version);
        self::assertArrayHasKey('hl_spread_pct', $snapshot->features);
        self::assertArrayHasKey('recent_volatility', $snapshot->features);

        // Seed subsequent closed candle hitting TP (+2%)
        CandleRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '15',
            'open_time' => $t0->addMinutes(15),
            'open' => '60300',
            'high' => '61600', // Hits 2% TP (> 61400)
            'low' => '60200',
            'close' => '61500',
            'volume' => '200',
            'turnover' => '12000000',
            'is_closed' => true,
        ]);

        $matured = $service->maturePendingLabels();
        self::assertSame(1, $matured);

        $snapshot->refresh();
        self::assertSame('MATURED', $snapshot->label_status);
        self::assertSame('TP', $snapshot->label_outcome);
        self::assertSame('1.8700', (string) $snapshot->label_r_multiple);
    }
}
