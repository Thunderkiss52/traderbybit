<?php

declare(strict_types=1);

namespace Tests\Unit\Research;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\Research\Application\SystemFreshnessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SystemFreshnessServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_freshness_check_detects_lag_and_generates_alerts(): void
    {
        $service = new SystemFreshnessService;

        // Seed an old closed candle for BTCUSDT 15m (lagging > 30 minutes)
        CandleRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '15',
            'open_time' => now('UTC')->subHours(3),
            'open' => '65000',
            'high' => '65500',
            'low' => '64900',
            'close' => '65200',
            'volume' => '100',
            'turnover' => '6500000',
            'is_closed' => true,
        ]);

        $check = $service->check();

        self::assertIsArray($check);
        self::assertArrayHasKey('status', $check);
        self::assertArrayHasKey('candle_freshness', $check);
        self::assertArrayHasKey('alerts', $check);

        // Expect warning because 3 hours lag is >= 2 consecutive 15m intervals
        self::assertSame('WARNING', $check['status']);
        self::assertNotEmpty($check['alerts']);
        self::assertSame('MISSING_CANDLE_INTERVALS', $check['alerts'][0]['code']);
    }
}
