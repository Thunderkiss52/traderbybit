<?php

declare(strict_types=1);

namespace Tests\Unit\Exchanges;

use App\Exchanges\Binance\BinancePublicAdapter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class BinancePublicAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_public_futures_candle_preserves_precise_decimal_strings_without_authentication(): void
    {
        Http::fake(['https://fapi.binance.com/fapi/v1/klines*' => Http::response([
            [1699999800000, '30000.12345678', '30100', '29900', '30050.00000001', '2.50000001', 1700000099999, '75000.12345678', 10, '1', '35000', '0'],
        ])]);
        $rows = app(BinancePublicAdapter::class)->fetchCandles('BTCUSDT', '5m', 1);
        self::assertCount(1, $rows);
        self::assertSame('binance', $rows[0]['exchange_id']);
        self::assertSame('30050.00000001', $rows[0]['close']);
        self::assertSame('2.50000001', $rows[0]['volume']);
        self::assertIsString($rows[0]['raw_source_hash']);
        Http::assertSent(fn ($req) => ! $req->hasHeader('Authorization') && ! $req->hasHeader('X-MBX-APIKEY')
            && str_contains($req->url(), '/fapi/v1/klines'));
    }

    public function test_funding_and_open_interest_preserve_signed_and_nullable_values(): void
    {
        Http::fake([
            'https://fapi.binance.com/fapi/v1/fundingRate*' => Http::response([
                ['symbol' => 'BTCUSDT', 'fundingRate' => '-0.00012345', 'fundingTime' => 1700000000000],
            ]),
            'https://fapi.binance.com/futures/data/openInterestHist*' => Http::response([
                ['symbol' => 'BTCUSDT', 'sumOpenInterest' => '1234.00000001', 'timestamp' => 1700000000000],
            ]),
        ]);
        $adapter = app(BinancePublicAdapter::class);
        self::assertSame('-0.00012345', $adapter->fetchFundingHistory('BTCUSDT')[0]['funding_rate']);
        self::assertNull($adapter->fetchOpenInterest('BTCUSDT', '5m')[0]['open_interest_value_usdt']);
    }

    public function test_negative_open_interest_or_nonpositive_funding_mark_price_is_rejected(): void
    {
        Http::fake([
            'https://fapi.binance.com/fapi/v1/fundingRate*' => Http::response([
                ['symbol' => 'BTCUSDT', 'fundingRate' => '0.0001', 'fundingTime' => 1700000000000, 'markPrice' => '-1'],
            ]),
            'https://fapi.binance.com/futures/data/openInterestHist*' => Http::response([
                ['symbol' => 'BTCUSDT', 'sumOpenInterest' => '-100', 'timestamp' => 1700000000000],
            ]),
        ]);
        foreach (['funding', 'open_interest'] as $kind) {
            try {
                if ($kind === 'funding') {
                    app(BinancePublicAdapter::class)->fetchFundingHistory('BTCUSDT');
                } else {
                    app(BinancePublicAdapter::class)->fetchOpenInterest('BTCUSDT', '5m');
                }
                self::fail('Invalid public Binance amount accepted: '.$kind);
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('Invalid Binance', $exception->getMessage());
            }
        }
    }

    public function test_malformed_candle_and_invalid_symbol_are_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        BinancePublicAdapter::normalizeCandles([[1700000000000, 'NaN']], 'BTCUSDT', '5m');
    }

    public function test_invalid_ohlc_negative_volume_and_duplicate_candles_fail_closed(): void
    {
        $row = [1699999800000, '100.000000000000000001', '105', '99', '102', '1', 1700000099999, '102'];
        foreach ([
            'crossed high and low' => [2 => '98', 3 => '106'],
            'high below open' => [2 => '99'],
            'low above close' => [3 => '103'],
            'zero price' => [1 => '0'],
            'negative volume' => [5 => '-1'],
            'negative turnover' => [7 => '-1'],
        ] as $label => $changes) {
            try {
                BinancePublicAdapter::normalizeCandles([array_replace($row, $changes)], 'BTCUSDT', '5m');
                self::fail("{$label} was accepted as valid Binance market data.");
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('Invalid Binance candle', $e->getMessage(), $label);
            }
        }
        $precise = $row;
        $precise[1] = '99999999999999999999.000000000000000001';
        $precise[2] = '99999999999999999999.000000000000000002';
        $precise[3] = '99999999999999999999.000000000000000000';
        $precise[4] = $precise[1];
        self::assertCount(1, BinancePublicAdapter::normalizeCandles([$precise], 'BTCUSDT', '5m'));
        $precise[2] = $precise[3];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid Binance candle OHLC values.');
        BinancePublicAdapter::normalizeCandles([$precise], 'BTCUSDT', '5m');
    }

    public function test_misaligned_or_wrong_duration_candles_are_rejected(): void
    {
        $open = 1_699_999_800_000; // UTC-aligned five-minute open
        $valid = [$open, '100', '105', '99', '102', '10', $open + 299999, '1010'];
        self::assertCount(1, BinancePublicAdapter::normalizeCandles([$valid], 'BTCUSDT', '5m'));
        foreach ([
            'misaligned open' => [0 => $open + 60000, 6 => $open + 359999],
            'shortened candle' => [6 => $open + 60000 - 1],
            '15m candle labelled 5m' => [6 => $open + 900000 - 1],
        ] as $label => $values) {
            try {
                BinancePublicAdapter::normalizeCandles([array_replace($valid, $values)], 'BTCUSDT', '5m');
                self::fail($label.' was accepted.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('candle interval alignment', $e->getMessage());
            }
        }
    }

    public function test_real_public_book_context_is_rejected_if_crossed_or_invalid(): void
    {
        $metadata = [
            'exchange_id' => 'binance', 'symbol' => 'BTCUSDT', 'is_active' => true,
            'tick_size' => '0.01', 'lot_size' => '0.001',
            'min_order_qty' => '0.001', 'max_order_qty' => '1000', 'min_notional' => '5',
        ];
        $book = ['symbol' => 'BTCUSDT', 'bidPrice' => '30000.00000001', 'askPrice' => '30000.00000002', 'bidQty' => '10', 'askQty' => '20'];
        $ticker = ['symbol' => 'BTCUSDT', 'quoteVolume' => '1000000.00'];
        $premium = ['symbol' => 'BTCUSDT', 'lastFundingRate' => '-0.0001'];
        self::assertSame('30000.00000001', BinancePublicAdapter::normalizeContext('BTCUSDT', $book, $ticker, $premium, $metadata)['bid']);
        foreach ([
            [array_replace($book, ['askPrice' => '29999']), $ticker, $premium, $metadata],
            [array_replace($book, ['bidQty' => '-1']), $ticker, $premium, $metadata],
            [$book, array_replace($ticker, ['quoteVolume' => '-5']), $premium, $metadata],
            [$book, $ticker, $premium, array_replace($metadata, ['tick_size' => '0'])],
            [$book, $ticker, $premium, array_replace($metadata, ['exchange_id' => 'bybit'])],
        ] as [$badBook, $badTicker, $badPremium, $badMetadata]) {
            try {
                BinancePublicAdapter::normalizeContext('BTCUSDT', $badBook, $badTicker, $badPremium, $badMetadata);
                self::fail('Untrusted Binance quote/metadata must not enter PAPER risk calculations.');
            } catch (\RuntimeException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_symbols_missing_exchange_precision_are_not_fabricated(): void
    {
        Http::fake(['https://fapi.binance.com/fapi/v1/exchangeInfo*' => Http::response(['symbols' => [
            ['symbol' => 'BTCUSDT', 'contractType' => 'PERPETUAL', 'quoteAsset' => 'USDT', 'filters' => []],
        ]])]);
        self::assertSame([], app(BinancePublicAdapter::class)->fetchSymbols());
    }
}
