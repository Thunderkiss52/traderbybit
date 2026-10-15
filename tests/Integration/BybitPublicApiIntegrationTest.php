<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\MarketData\Contracts\BybitMarketDataClient;
use App\MarketData\Domain\CandleQuery;
use App\MarketData\Domain\Interval;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('integration')]
final class BybitPublicApiIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (env('RUN_BYBIT_INTEGRATION') !== '1') {
            self::markTestSkipped('Set RUN_BYBIT_INTEGRATION=1 to call the public Bybit API.');
        }
    }

    public function test_public_linear_market_endpoints_match_the_documented_contract(): void
    {
        $client = app(BybitMarketDataClient::class);
        $to = (int) floor(microtime(true) * 1000);
        $from = $to - 3_600_000;

        self::assertNotEmpty($client->candles(new CandleQuery('linear', 'BTCUSDT', Interval::FiveMinutes, $from, $to)));
        self::assertSame('BTCUSDT', $client->ticker('linear', 'BTCUSDT')['symbol']);
        self::assertSame('BTCUSDT', $client->orderBook('linear', 'BTCUSDT', 1)['s']);
        self::assertIsArray($client->fundingRates('linear', 'BTCUSDT'));
        self::assertIsArray($client->openInterest('linear', 'BTCUSDT', '5min')['list']);
    }
}
