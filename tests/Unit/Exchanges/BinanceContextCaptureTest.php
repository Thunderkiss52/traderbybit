<?php

declare(strict_types=1);

namespace Tests\Unit\Exchanges;

use App\Exchanges\Binance\BinanceContextCapture;
use App\Exchanges\Binance\BinancePublicAdapter;
use App\Strategy\Contracts\MarketContextRepository;
use App\Strategy\Domain\MarketContext;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class BinanceContextCaptureTest extends TestCase
{
    public function test_real_public_oi_history_reaches_context_without_forward_execution(): void
    {
        config()->set('market_data.symbols', ['BTCUSDT', 'ETHUSDT']);
        $now = time();
        Http::fake([
            'https://fapi.binance.com/fapi/v1/ticker/bookTicker*' => Http::response([
                'symbol' => 'BTCUSDT', 'bidPrice' => '100', 'askPrice' => '100.01', 'bidQty' => '10', 'askQty' => '11',
            ]),
            'https://fapi.binance.com/fapi/v1/ticker/24hr*' => Http::response(['symbol' => 'BTCUSDT', 'quoteVolume' => '1000000']),
            'https://fapi.binance.com/fapi/v1/premiumIndex*' => Http::response(['symbol' => 'BTCUSDT', 'lastFundingRate' => '-0.0001']),
            'https://fapi.binance.com/fapi/v1/exchangeInfo*' => Http::response(['symbols' => [[
                'symbol' => 'BTCUSDT', 'contractType' => 'PERPETUAL', 'quoteAsset' => 'USDT',
                'baseAsset' => 'BTC', 'status' => 'TRADING', 'filters' => [
                    ['filterType' => 'PRICE_FILTER', 'tickSize' => '0.01'],
                    ['filterType' => 'LOT_SIZE', 'stepSize' => '0.001', 'minQty' => '0.001', 'maxQty' => '1000'],
                    ['filterType' => 'MIN_NOTIONAL', 'notional' => '5'],
                ],
            ]]]),
            'https://fapi.binance.com/futures/data/openInterestHist*' => Http::response([
                ['symbol' => 'BTCUSDT', 'timestamp' => ($now - 600) * 1000, 'sumOpenInterest' => '100'],
                ['symbol' => 'BTCUSDT', 'timestamp' => ($now - 300) * 1000, 'sumOpenInterest' => '110'],
            ]),
        ]);
        $repo = new class implements MarketContextRepository
        {
            public ?MarketContext $last = null;

            public function latestAt(string $exchange, string $category, string $symbol, DateTimeImmutable $at): ?MarketContext
            {
                return $this->last;
            }

            public function store(string $exchange, string $category, string $symbol, MarketContext $context): void
            {
                $this->last = $context;
            }
        };
        $result = (new BinanceContextCapture(app(BinancePublicAdapter::class), $repo))->capture('BTCUSDT');
        self::assertSame('VERIFIED_SOURCE_WINDOW', $result['open_interest_status']);
        self::assertNotNull($repo->last);
        self::assertTrue(BigDecimal::of($repo->last->openInterest)->isEqualTo('110'));
        self::assertTrue(BigDecimal::of($repo->last->openInterestChange)->isEqualTo('0.1'));
        self::assertSame('binance', $result['exchange_id']);
    }
}
