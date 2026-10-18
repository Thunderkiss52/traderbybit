<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MarketTodayFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_market_today_mode_bounds_strictly_to_trading_day_timestamps(): void
    {
        $symbol = 'BTCUSDT';
        $now = CarbonImmutable::now('UTC');
        $todayStart = $now->startOfDay();
        $yesterday = $todayStart->subHours(5);
        $threeDaysAgo = $todayStart->subDays(3);

        // 2 candles from 3 days ago (gap between days)
        CandleRecord::query()->create([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => $symbol, 'interval' => '15',
            'open_time' => $threeDaysAgo, 'open' => '60000', 'high' => '60100', 'low' => '59900', 'close' => '60050',
            'volume' => '10', 'turnover' => '600000', 'is_closed' => true,
        ]);
        CandleRecord::query()->create([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => $symbol, 'interval' => '15',
            'open_time' => $threeDaysAgo->addMinutes(15), 'open' => '60050', 'high' => '60150', 'low' => '59950', 'close' => '60100',
            'volume' => '10', 'turnover' => '601000', 'is_closed' => true,
        ]);

        // 2 candles from yesterday
        CandleRecord::query()->create([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => $symbol, 'interval' => '15',
            'open_time' => $yesterday, 'open' => '61000', 'high' => '61100', 'low' => '60900', 'close' => '61050',
            'volume' => '10', 'turnover' => '610000', 'is_closed' => true,
        ]);
        CandleRecord::query()->create([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => $symbol, 'interval' => '15',
            'open_time' => $yesterday->addMinutes(15), 'open' => '61050', 'high' => '61150', 'low' => '60950', 'close' => '61100',
            'volume' => '10', 'turnover' => '611000', 'is_closed' => true,
        ]);

        // 3 contiguous candles today (starting after 00:00 UTC)
        $t1 = $todayStart->addHours(1);
        CandleRecord::query()->create([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => $symbol, 'interval' => '15',
            'open_time' => $t1, 'open' => '62000', 'high' => '62100', 'low' => '61900', 'close' => '62050',
            'volume' => '10', 'turnover' => '620000', 'is_closed' => true,
        ]);
        CandleRecord::query()->create([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => $symbol, 'interval' => '15',
            'open_time' => $t1->addMinutes(15), 'open' => '62050', 'high' => '62150', 'low' => '61950', 'close' => '62100',
            'volume' => '10', 'turnover' => '621000', 'is_closed' => true,
        ]);
        CandleRecord::query()->create([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => $symbol, 'interval' => '15',
            'open_time' => $t1->addMinutes(30), 'open' => '62100', 'high' => '62200', 'low' => '62000', 'close' => '62150',
            'volume' => '10', 'turnover' => '622000', 'is_closed' => true,
        ]);

        // 1. Request today view on candles endpoint
        $response = $this->getJson("/api/v1/market/{$symbol}/candles?view_mode=today&interval=15");
        $response->assertOk();

        $data = $response->json('data');
        $this->assertCount(3, $data); // Strictly the 3 candles from today
        foreach ($data as $candle) {
            $candleTime = CarbonImmutable::parse($candle['open_time']);
            $this->assertGreaterThanOrEqual($todayStart->timestamp, $candleTime->timestamp);
            $this->assertLessThanOrEqual($now->timestamp, $candleTime->timestamp);
        }

        // 2. Request 7d view on candles endpoint: includes 2 from 3 days ago, 2 from yesterday, and 3 from today = 7
        $response7d = $this->getJson("/api/v1/market/{$symbol}/candles?view_mode=7d&interval=15");
        $response7d->assertOk();

        $data7d = $response7d->json('data');
        $this->assertCount(7, $data7d);

        // 3. Request today view on analysis endpoint (contiguous candles allow indicator evaluation)
        $analysisResponse = $this->getJson("/api/v1/market/{$symbol}/analysis?view_mode=today&interval=15");
        $analysisResponse->assertOk();
        $this->assertCount(3, $analysisResponse->json('data.candles'));
    }

    public function test_bounded_7d_view_obeys_maximum_limit(): void
    {
        $symbol = 'BTCUSDT';
        $now = CarbonImmutable::now('UTC');
        $start = $now->subDays(2)->startOfDay();

        // Seed 30 strictly contiguous 15m candles
        for ($i = 0; $i < 30; $i++) {
            CandleRecord::query()->create([
                'exchange' => 'bybit', 'category' => 'linear', 'symbol' => $symbol, 'interval' => '15',
                'open_time' => $start->addMinutes($i * 15), 'open' => '60000', 'high' => '60100', 'low' => '59900', 'close' => '60050',
                'volume' => '10', 'turnover' => '600000', 'is_closed' => true,
            ]);
        }

        $response = $this->getJson("/api/v1/market/{$symbol}/analysis?view_mode=7d&limit=10&interval=15");
        $response->assertOk();

        $candles = $response->json('data.candles');
        $this->assertCount(10, $candles); // Bounded strictly to limit 10
    }
}
