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

    public function test_f17_learning_feature_store_isolates_exchanges_without_cross_contamination(): void
    {
        $service = new LearningFeatureStoreService;
        $now = CarbonImmutable::now();
        $t0 = $now->subHours(5);

        // Seed 12 closed candles for Bybit (bullish close 60000..60240)
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
                'volume' => '100',
                'turnover' => '6000000',
                'is_closed' => true,
            ]);
        }

        // Seed 12 closed candles for Binance at identical timestamps with opposing prices (bearish close 50000..49780)
        for ($i = 0; $i < 12; $i++) {
            CandleRecord::query()->create([
                'exchange' => 'binance',
                'category' => 'linear',
                'symbol' => 'BTCUSDT',
                'interval' => '15',
                'open_time' => $t0->subMinutes((12 - $i) * 15),
                'open' => '50000',
                'high' => '50100',
                'low' => '49500',
                'close' => (string) (50000 - ($i * 20)),
                'volume' => '500',
                'turnover' => '25000000',
                'is_closed' => true,
            ]);
        }

        $bybitSnapshot = $service->assembleSnapshot('BTCUSDT', '15', $t0, 'bybit_strat', 'BULLISH', 'bybit');
        $binanceSnapshot = $service->assembleSnapshot('BTCUSDT', '15', $t0, 'binance_strat', 'BEARISH', 'binance');

        self::assertNotNull($bybitSnapshot);
        self::assertNotNull($binanceSnapshot);

        // Assert Bybit snapshot contains Bybit values, strictly isolated from Binance
        self::assertSame('bybit', $bybitSnapshot->data_quality['exchange']);
        self::assertSame(60220.0, (float) $bybitSnapshot->features['close']);
        self::assertSame(100.0, (float) $bybitSnapshot->features['volume']);

        // Assert Binance snapshot contains Binance values, strictly isolated from Bybit
        self::assertSame('binance', $binanceSnapshot->data_quality['exchange']);
        self::assertSame(49780.0, (float) $binanceSnapshot->features['close']);
        self::assertSame(500.0, (float) $binanceSnapshot->features['volume']);

        // Seed subsequent candle at t0 + 15m: Bybit hits TP (> 60220 * 1.02 = 61424), Binance hits SL (< 49780 * 0.99 = 49282)
        CandleRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '15',
            'open_time' => $t0->addMinutes(15),
            'open' => '60220',
            'high' => '62000', // hits TP
            'low' => '60100',
            'close' => '61900',
            'volume' => '150',
            'turnover' => '9000000',
            'is_closed' => true,
        ]);

        CandleRecord::query()->create([
            'exchange' => 'binance',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '15',
            'open_time' => $t0->addMinutes(15),
            'open' => '49780',
            'high' => '49900',
            'low' => '48000', // hits SL
            'close' => '48100',
            'volume' => '600',
            'turnover' => '29000000',
            'is_closed' => true,
        ]);

        $matured = $service->maturePendingLabels();
        self::assertSame(2, $matured);

        $bybitSnapshot->refresh();
        $binanceSnapshot->refresh();

        self::assertSame('MATURED', $bybitSnapshot->label_status);
        self::assertSame('TP', $bybitSnapshot->label_outcome);

        self::assertSame('MATURED', $binanceSnapshot->label_status);
        self::assertSame('SL', $binanceSnapshot->label_outcome);
    }
}
