<?php

declare(strict_types=1);

namespace Tests\Feature\Exchanges;

use App\Exchanges\Bitget\BitgetAdapter;
use App\Exchanges\Coinbase\CoinbaseAdapter;
use App\Exchanges\Domain\CapabilityStatus;
use App\Exchanges\Domain\MarketCapability;
use App\Exchanges\Domain\MarketType;
use App\Exchanges\Okx\OkxAdapter;
use App\Exchanges\Upbit\UpbitAdapter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class GroupAExchangesTest extends TestCase
{
    // =========================================================================
    // COINBASE EXCHANGE TESTS
    // =========================================================================

    public function test_coinbase_adapter_identity_and_market_types(): void
    {
        $adapter = new CoinbaseAdapter;

        self::assertSame('coinbase', $adapter->id());
        self::assertSame('Coinbase Exchange', $adapter->name());
        self::assertSame([MarketType::Spot], $adapter->supportedMarketTypes());
    }

    public function test_coinbase_capabilities_matrix(): void
    {
        $adapter = new CoinbaseAdapter;

        $spotCaps = $adapter->getMarketCapabilities(MarketType::Spot);
        self::assertSame(CapabilityStatus::Supported, $spotCaps[MarketCapability::Candles->value]);
        self::assertSame(CapabilityStatus::Supported, $spotCaps[MarketCapability::OrderBookL2->value]);
        self::assertSame(CapabilityStatus::Supported, $spotCaps[MarketCapability::Trades->value]);
        self::assertSame(CapabilityStatus::Supported, $spotCaps[MarketCapability::Ticker->value]);
        self::assertSame(CapabilityStatus::Unsupported, $spotCaps[MarketCapability::FundingHistory->value]);
        self::assertSame(CapabilityStatus::Unsupported, $spotCaps[MarketCapability::OpenInterest->value]);
        self::assertSame(CapabilityStatus::Unsupported, $spotCaps[MarketCapability::MarkPrice->value]);
        self::assertSame(CapabilityStatus::Unsupported, $spotCaps[MarketCapability::Liquidations->value]);
        self::assertSame(CapabilityStatus::Unsupported, $spotCaps[MarketCapability::OptionsChain->value]);

        $perpCaps = $adapter->getMarketCapabilities(MarketType::Perpetual);
        foreach ($perpCaps as $status) {
            self::assertSame(CapabilityStatus::Unsupported, $status);
        }
    }

    public function test_coinbase_instruments_discovery(): void
    {
        Http::fake([
            'https://api.exchange.coinbase.com/products' => Http::response([
                [
                    'id' => 'BTC-USD',
                    'base_currency' => 'BTC',
                    'quote_currency' => 'USD',
                    'quote_increment' => '0.01',
                    'base_increment' => '0.00000001',
                    'base_min_size' => '0.00001',
                    'base_max_size' => '1000',
                    'min_market_funds' => '1.0',
                    'status' => 'online',
                    'trading_disabled' => false,
                ],
                [
                    'id' => 'ETH-USD',
                    'base_currency' => 'ETH',
                    'quote_currency' => 'USD',
                    'quote_increment' => '0.01',
                    'base_increment' => '0.000001',
                    'base_min_size' => '0.001',
                    'base_max_size' => '5000',
                    'min_market_funds' => '1.0',
                    'status' => 'online',
                    'trading_disabled' => false,
                ],
            ], 200),
        ]);

        $adapter = new CoinbaseAdapter;
        $instruments = $adapter->fetchInstruments(MarketType::Spot);

        self::assertCount(2, $instruments);
        self::assertSame('BTC-USD', $instruments[0]['symbol']);
        self::assertSame('BTC', $instruments[0]['base_asset']);
        self::assertSame('USD', $instruments[0]['quote_asset']);
        self::assertSame('spot', $instruments[0]['market_type']);
        self::assertSame('0.01', $instruments[0]['tick_size']);
        self::assertSame('0.00000001', $instruments[0]['lot_size']);
        self::assertSame('0.00001', $instruments[0]['min_order_qty']);
        self::assertSame('1000', $instruments[0]['max_order_qty']);
        self::assertSame('1.0', $instruments[0]['min_notional']);
        self::assertTrue($instruments[0]['is_active']);
        self::assertNotEmpty($instruments[0]['raw_source_hash']);

        // Check unsupported market type returns empty list
        self::assertSame([], $adapter->fetchInstruments(MarketType::Perpetual));
    }

    public function test_coinbase_candle_normalization(): void
    {
        Http::fake([
            'https://api.exchange.coinbase.com/products/BTC-USD/candles*' => Http::response([
                // Coinbase returns newest first: [time, low, high, open, close, volume]
                [1704067500, 42100.0, 42300.0, 42150.0, 42250.0, 15.2],
                [1704067200, 42000.0, 42200.0, 42050.0, 42150.0, 12.5],
            ], 200),
        ]);

        $adapter = new CoinbaseAdapter;
        $candles = $adapter->fetchCandles('BTC-USD', '5m', 2);

        self::assertCount(2, $candles);
        // Assert chronologically sorted (oldest first)
        self::assertSame('coinbase', $candles[0]['exchange_id']);
        self::assertSame('BTC-USD', $candles[0]['symbol']);
        self::assertSame('5m', $candles[0]['interval']);
        self::assertSame('2024-01-01T00:00:00Z', $candles[0]['open_time_utc']);
        self::assertSame('2024-01-01T00:04:59Z', $candles[0]['close_time_utc']);
        self::assertSame('42050', $candles[0]['open']);
        self::assertSame('42200', $candles[0]['high']);
        self::assertSame('42000', $candles[0]['low']);
        self::assertSame('42150', $candles[0]['close']);
        self::assertSame('12.5', $candles[0]['volume']);
        self::assertNull($candles[0]['turnover']);
        self::assertTrue($candles[0]['is_closed']);

        self::assertSame('2024-01-01T00:05:00Z', $candles[1]['open_time_utc']);
        self::assertSame('42250', $candles[1]['close']);
    }

    public function test_coinbase_order_book_normalization(): void
    {
        Http::fake([
            'https://api.exchange.coinbase.com/products/BTC-USD/book*' => Http::response([
                'sequence' => 999999,
                'bids' => [
                    ['42150.00', '1.25', 3],
                    ['42140.00', '2.50', 1],
                ],
                'asks' => [
                    ['42160.00', '0.75', 2],
                    ['42170.00', '1.80', 4],
                ],
                'time' => '2024-01-01T00:00:00Z',
            ], 200),
        ]);

        $adapter = new CoinbaseAdapter;
        $book = $adapter->fetchOrderBook('BTC-USD', 2);

        self::assertSame('coinbase', $book['exchange_id']);
        self::assertSame('BTC-USD', $book['symbol']);
        self::assertSame('2024-01-01T00:00:00Z', $book['timestamp_utc']);
        self::assertSame([
            ['42150.00', '1.25'],
            ['42140.00', '2.50'],
        ], $book['bids']);
        self::assertSame([
            ['42160.00', '0.75'],
            ['42170.00', '1.80'],
        ], $book['asks']);
        self::assertNotEmpty($book['raw_source_hash']);
    }

    public function test_coinbase_ticker_normalization(): void
    {
        Http::fake([
            'https://api.exchange.coinbase.com/products/BTC-USD/ticker' => Http::response([
                'trade_id' => 123456,
                'price' => '42155.50',
                'size' => '0.5',
                'bid' => '42150.00',
                'ask' => '42160.00',
                'volume' => '1250.75',
                'time' => '2024-01-01T00:00:00Z',
            ], 200),
        ]);

        $adapter = new CoinbaseAdapter;
        $ticker = $adapter->fetchTicker('BTC-USD');

        self::assertSame('coinbase', $ticker['exchange_id']);
        self::assertSame('BTC-USD', $ticker['symbol']);
        self::assertSame('42155.50', $ticker['last_price']);
        self::assertSame('42150.00', $ticker['bid_price']);
        self::assertSame('42160.00', $ticker['ask_price']);
        self::assertSame('1250.75', $ticker['volume_24h']);
        self::assertNull($ticker['turnover_24h']);
        self::assertSame('2024-01-01T00:00:00Z', $ticker['timestamp_utc']);
    }

    public function test_coinbase_health_check(): void
    {
        Http::fake([
            'https://api.exchange.coinbase.com/products/BTC-USDT/ticker' => Http::response([
                'price' => '42000.00',
                'volume' => '500.00',
                'time' => '2024-01-01T00:00:00Z',
            ], 200),
        ]);

        $adapter = new CoinbaseAdapter;
        $health = $adapter->healthCheck();

        self::assertSame('HEALTHY', $health['status']);
        self::assertGreaterThanOrEqual(0, $health['latency_ms']);
        self::assertSame('https://api.exchange.coinbase.com', $health['endpoint']);
        self::assertNull($health['error']);
    }

    public function test_coinbase_derivatives_methods_return_empty(): void
    {
        $adapter = new CoinbaseAdapter;
        self::assertSame([], $adapter->fetchFundingHistory('BTC-USD'));
        self::assertSame([], $adapter->fetchOpenInterest('BTC-USD', '5m'));
    }

    // =========================================================================
    // UPBIT TESTS
    // =========================================================================

    public function test_upbit_adapter_identity_and_market_types(): void
    {
        $adapter = new UpbitAdapter;

        self::assertSame('upbit', $adapter->id());
        self::assertSame('Upbit', $adapter->name());
        self::assertSame([MarketType::Spot], $adapter->supportedMarketTypes());
    }

    public function test_upbit_capabilities_matrix(): void
    {
        $adapter = new UpbitAdapter;

        $spotCaps = $adapter->getMarketCapabilities(MarketType::Spot);
        self::assertSame(CapabilityStatus::Supported, $spotCaps[MarketCapability::Candles->value]);
        self::assertSame(CapabilityStatus::Supported, $spotCaps[MarketCapability::OrderBookL2->value]);
        self::assertSame(CapabilityStatus::Supported, $spotCaps[MarketCapability::Trades->value]);
        self::assertSame(CapabilityStatus::Supported, $spotCaps[MarketCapability::Ticker->value]);
        self::assertSame(CapabilityStatus::Unsupported, $spotCaps[MarketCapability::FundingHistory->value]);
        self::assertSame(CapabilityStatus::Unsupported, $spotCaps[MarketCapability::OpenInterest->value]);

        $perpCaps = $adapter->getMarketCapabilities(MarketType::Perpetual);
        foreach ($perpCaps as $status) {
            self::assertSame(CapabilityStatus::Unsupported, $status);
        }
    }

    public function test_upbit_instruments_discovery(): void
    {
        Http::fake([
            'https://api.upbit.com/v1/market/all*' => Http::response([
                [
                    'market' => 'KRW-BTC',
                    'korean_name' => '비트코인',
                    'english_name' => 'Bitcoin',
                    'market_warning' => 'NONE',
                ],
                [
                    'market' => 'USDT-BTC',
                    'korean_name' => '비트코인',
                    'english_name' => 'Bitcoin',
                    'market_warning' => 'NONE',
                ],
            ], 200),
        ]);

        $adapter = new UpbitAdapter;
        $instruments = $adapter->fetchInstruments(MarketType::Spot);

        self::assertCount(2, $instruments);
        self::assertSame('KRW-BTC', $instruments[0]['symbol']);
        self::assertSame('BTC', $instruments[0]['base_asset']);
        self::assertSame('KRW', $instruments[0]['quote_asset']);
        self::assertSame('spot', $instruments[0]['market_type']);
        self::assertTrue($instruments[0]['is_active']);
    }

    public function test_upbit_candle_normalization(): void
    {
        Http::fake([
            'https://api.upbit.com/v1/candles/minutes/5*' => Http::response([
                // Upbit returns newest first
                [
                    'market' => 'KRW-BTC',
                    'candle_date_time_utc' => '2024-01-01T00:05:00',
                    'candle_date_time_kst' => '2024-01-01T09:05:00',
                    'opening_price' => 55000000.0,
                    'high_price' => 55500000.0,
                    'low_price' => 54900000.0,
                    'trade_price' => 55200000.0,
                    'timestamp' => 1704067500000,
                    'candle_acc_trade_price' => 2000000000.0,
                    'candle_acc_trade_volume' => 36.2,
                ],
                [
                    'market' => 'KRW-BTC',
                    'candle_date_time_utc' => '2024-01-01T00:00:00',
                    'candle_date_time_kst' => '2024-01-01T09:00:00',
                    'opening_price' => 54500000.0,
                    'high_price' => 55100000.0,
                    'low_price' => 54400000.0,
                    'trade_price' => 55000000.0,
                    'timestamp' => 1704067200000,
                    'candle_acc_trade_price' => 1500000000.0,
                    'candle_acc_trade_volume' => 27.5,
                ],
            ], 200),
        ]);

        $adapter = new UpbitAdapter;
        $candles = $adapter->fetchCandles('KRW-BTC', '5m', 2);

        self::assertCount(2, $candles);
        // Chronological order: oldest first
        self::assertSame('upbit', $candles[0]['exchange_id']);
        self::assertSame('KRW-BTC', $candles[0]['symbol']);
        self::assertSame('54500000', $candles[0]['open']);
        self::assertSame('55100000', $candles[0]['high']);
        self::assertSame('54400000', $candles[0]['low']);
        self::assertSame('55000000', $candles[0]['close']);
        self::assertSame('27.5', $candles[0]['volume']);
        self::assertSame('1500000000', $candles[0]['turnover']);
        self::assertTrue($candles[0]['is_closed']);

        self::assertSame('55200000', $candles[1]['close']);
    }

    public function test_upbit_order_book_normalization(): void
    {
        Http::fake([
            'https://api.upbit.com/v1/orderbook*' => Http::response([
                [
                    'market' => 'KRW-BTC',
                    'timestamp' => 1704067200000,
                    'orderbook_units' => [
                        [
                            'ask_price' => 55010000.0,
                            'bid_price' => 55000000.0,
                            'ask_size' => 1.5,
                            'bid_size' => 2.0,
                        ],
                    ],
                ],
            ], 200),
        ]);

        $adapter = new UpbitAdapter;
        $book = $adapter->fetchOrderBook('KRW-BTC', 1);

        self::assertSame('upbit', $book['exchange_id']);
        self::assertSame('KRW-BTC', $book['symbol']);
        self::assertSame([['55000000', '2']], $book['bids']);
        self::assertSame([['55010000', '1.5']], $book['asks']);
    }

    public function test_upbit_ticker_normalization(): void
    {
        Http::fake([
            'https://api.upbit.com/v1/ticker*' => Http::response([
                [
                    'market' => 'KRW-BTC',
                    'trade_price' => 55000000.0,
                    'acc_trade_volume_24h' => 1500.5,
                    'acc_trade_price_24h' => 82500000000.0,
                    'timestamp' => 1704067200000,
                ],
            ], 200),
        ]);

        $adapter = new UpbitAdapter;
        $ticker = $adapter->fetchTicker('KRW-BTC');

        self::assertSame('upbit', $ticker['exchange_id']);
        self::assertSame('KRW-BTC', $ticker['symbol']);
        self::assertSame('55000000', $ticker['last_price']);
        self::assertNull($ticker['bid_price']);
        self::assertNull($ticker['ask_price']);
        self::assertSame('1500.5', $ticker['volume_24h']);
        self::assertSame('82500000000', $ticker['turnover_24h']);
    }

    public function test_upbit_health_check(): void
    {
        Http::fake([
            'https://api.upbit.com/v1/ticker*' => Http::response([
                [
                    'market' => 'USDT-BTC',
                    'trade_price' => 42000.0,
                    'acc_trade_volume_24h' => 100.0,
                    'timestamp' => 1704067200000,
                ],
            ], 200),
        ]);

        $adapter = new UpbitAdapter;
        $health = $adapter->healthCheck();

        self::assertSame('HEALTHY', $health['status']);
        self::assertGreaterThanOrEqual(0, $health['latency_ms']);
        self::assertSame('https://api.upbit.com', $health['endpoint']);
    }

    public function test_upbit_derivatives_methods_return_empty(): void
    {
        $adapter = new UpbitAdapter;
        self::assertSame([], $adapter->fetchFundingHistory('KRW-BTC'));
        self::assertSame([], $adapter->fetchOpenInterest('KRW-BTC', '5m'));
    }

    // =========================================================================
    // OKX TESTS
    // =========================================================================

    public function test_okx_adapter_identity_and_market_types(): void
    {
        $adapter = new OkxAdapter;

        self::assertSame('okx', $adapter->id());
        self::assertSame('OKX', $adapter->name());
        self::assertSame([
            MarketType::Spot,
            MarketType::Perpetual,
            MarketType::Futures,
            MarketType::Options,
        ], $adapter->supportedMarketTypes());
    }

    public function test_okx_capabilities_matrix(): void
    {
        $adapter = new OkxAdapter;

        $spotCaps = $adapter->getMarketCapabilities(MarketType::Spot);
        self::assertSame(CapabilityStatus::Supported, $spotCaps[MarketCapability::Candles->value]);
        self::assertSame(CapabilityStatus::Unsupported, $spotCaps[MarketCapability::FundingHistory->value]);

        $perpCaps = $adapter->getMarketCapabilities(MarketType::Perpetual);
        self::assertSame(CapabilityStatus::Supported, $perpCaps[MarketCapability::Candles->value]);
        self::assertSame(CapabilityStatus::Supported, $perpCaps[MarketCapability::FundingHistory->value]);
        self::assertSame(CapabilityStatus::Supported, $perpCaps[MarketCapability::OpenInterest->value]);
        self::assertSame(CapabilityStatus::Supported, $perpCaps[MarketCapability::MarkPrice->value]);
        self::assertSame(CapabilityStatus::Supported, $perpCaps[MarketCapability::Liquidations->value]);
        self::assertSame(CapabilityStatus::Unsupported, $perpCaps[MarketCapability::OptionsChain->value]);

        $optCaps = $adapter->getMarketCapabilities(MarketType::Options);
        self::assertSame(CapabilityStatus::Supported, $optCaps[MarketCapability::OptionsChain->value]);
        self::assertSame(CapabilityStatus::Unsupported, $optCaps[MarketCapability::FundingHistory->value]);
    }

    public function test_okx_instruments_discovery(): void
    {
        Http::fake([
            'https://www.okx.com/api/v5/public/instruments?instType=SWAP' => Http::response([
                'code' => '0',
                'msg' => '',
                'data' => [
                    [
                        'instType' => 'SWAP',
                        'instId' => 'BTC-USDT-SWAP',
                        'ctValCcy' => 'BTC',
                        'settleCcy' => 'USDT',
                        'tickSz' => '0.1',
                        'lotSz' => '1',
                        'minSz' => '1',
                        'maxLmtSz' => '10000',
                        'state' => 'live',
                    ],
                ],
            ], 200),
        ]);

        $adapter = new OkxAdapter;
        $instruments = $adapter->fetchInstruments(MarketType::Perpetual);

        self::assertCount(1, $instruments);
        self::assertSame('BTC-USDT-SWAP', $instruments[0]['symbol']);
        self::assertSame('perpetual', $instruments[0]['market_type']);
        self::assertSame('0.1', $instruments[0]['tick_size']);
        self::assertSame('1', $instruments[0]['lot_size']);
        self::assertTrue($instruments[0]['is_active']);
    }

    public function test_okx_candle_normalization(): void
    {
        Http::fake([
            'https://www.okx.com/api/v5/market/candles*' => Http::response([
                'code' => '0',
                'msg' => '',
                'data' => [
                    // ts, o, h, l, c, vol, volCcy, volCcyQuote, confirm
                    ['1704067500000', '42100.5', '42300.0', '42050.0', '42250.0', '150.2', '6340000', '6340000', '1'],
                    ['1704067200000', '42000.0', '42150.0', '41950.0', '42100.0', '120.5', '5070000', '5070000', '1'],
                ],
            ], 200),
        ]);

        $adapter = new OkxAdapter;
        $candles = $adapter->fetchCandles('BTC-USDT', '5m', 2);

        self::assertCount(2, $candles);
        self::assertSame('okx', $candles[0]['exchange_id']);
        self::assertSame('42000.0', $candles[0]['open']);
        self::assertSame('42150.0', $candles[0]['high']);
        self::assertSame('41950.0', $candles[0]['low']);
        self::assertSame('42100.0', $candles[0]['close']);
        self::assertSame('120.5', $candles[0]['volume']);
        self::assertSame('5070000', $candles[0]['turnover']);
        self::assertTrue($candles[0]['is_closed']);

        self::assertSame('42250.0', $candles[1]['close']);
    }

    public function test_okx_order_book_normalization(): void
    {
        Http::fake([
            'https://www.okx.com/api/v5/market/books*' => Http::response([
                'code' => '0',
                'msg' => '',
                'data' => [
                    [
                        'asks' => [['42160.0', '2.5', '0', '3']],
                        'bids' => [['42150.0', '1.8', '0', '2']],
                        'ts' => '1704067200000',
                    ],
                ],
            ], 200),
        ]);

        $adapter = new OkxAdapter;
        $book = $adapter->fetchOrderBook('BTC-USDT', 1);

        self::assertSame('okx', $book['exchange_id']);
        self::assertSame([['42150.0', '1.8']], $book['bids']);
        self::assertSame([['42160.0', '2.5']], $book['asks']);
    }

    public function test_okx_ticker_normalization(): void
    {
        Http::fake([
            'https://www.okx.com/api/v5/market/ticker*' => Http::response([
                'code' => '0',
                'msg' => '',
                'data' => [
                    [
                        'last' => '42155.0',
                        'bidPx' => '42150.0',
                        'askPx' => '42160.0',
                        'vol24h' => '5432.1',
                        'volCcy24h' => '228000000',
                        'ts' => '1704067200000',
                    ],
                ],
            ], 200),
        ]);

        $adapter = new OkxAdapter;
        $ticker = $adapter->fetchTicker('BTC-USDT');

        self::assertSame('okx', $ticker['exchange_id']);
        self::assertSame('42155.0', $ticker['last_price']);
        self::assertSame('42150.0', $ticker['bid_price']);
        self::assertSame('42160.0', $ticker['ask_price']);
        self::assertSame('5432.1', $ticker['volume_24h']);
        self::assertSame('228000000', $ticker['turnover_24h']);
    }

    public function test_okx_funding_history_normalization(): void
    {
        Http::fake([
            'https://www.okx.com/api/v5/public/funding-rate-history*' => Http::response([
                'code' => '0',
                'msg' => '',
                'data' => [
                    [
                        'instType' => 'SWAP',
                        'instId' => 'BTC-USDT-SWAP',
                        'fundingRate' => '0.0001',
                        'realizedRate' => '0.000098',
                        'fundingTime' => '1704067200000',
                    ],
                ],
            ], 200),
        ]);

        $adapter = new OkxAdapter;
        $funding = $adapter->fetchFundingHistory('BTC-USDT');

        self::assertCount(1, $funding);
        self::assertSame('okx', $funding[0]['exchange_id']);
        self::assertSame('0.0001', $funding[0]['funding_rate']);
        self::assertSame('0.000098', $funding[0]['mark_price']);
        self::assertNotEmpty($funding[0]['raw_source_hash']);
    }

    public function test_okx_open_interest_normalization(): void
    {
        Http::fake([
            'https://www.okx.com/api/v5/public/open-interest*' => Http::response([
                'code' => '0',
                'msg' => '',
                'data' => [
                    [
                        'instType' => 'SWAP',
                        'instId' => 'BTC-USDT-SWAP',
                        'oi' => '54321',
                        'oiCcy' => '228000000',
                        'ts' => '1704067200000',
                    ],
                ],
            ], 200),
        ]);

        $adapter = new OkxAdapter;
        $oi = $adapter->fetchOpenInterest('BTC-USDT', '5m');

        self::assertCount(1, $oi);
        self::assertSame('okx', $oi[0]['exchange_id']);
        self::assertSame('54321', $oi[0]['open_interest']);
        self::assertSame('228000000', $oi[0]['open_interest_value_usdt']);
    }

    public function test_okx_health_check(): void
    {
        Http::fake([
            'https://www.okx.com/api/v5/market/ticker*' => Http::response([
                'code' => '0',
                'msg' => '',
                'data' => [
                    [
                        'last' => '42000.0',
                        'vol24h' => '1000.0',
                        'ts' => '1704067200000',
                    ],
                ],
            ], 200),
        ]);

        $adapter = new OkxAdapter;
        $health = $adapter->healthCheck();

        self::assertSame('HEALTHY', $health['status']);
        self::assertGreaterThanOrEqual(0, $health['latency_ms']);
        self::assertSame('https://www.okx.com', $health['endpoint']);
    }

    public function test_okx_api_error_response(): void
    {
        Http::fake([
            'https://www.okx.com/api/v5/market/ticker*' => Http::response([
                'code' => '51001',
                'msg' => 'Instrument ID does not exist',
                'data' => [],
            ], 200),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OKX API error [51001]: Instrument ID does not exist');

        $adapter = new OkxAdapter;
        $adapter->fetchTicker('INVALID');
    }

    // =========================================================================
    // BITGET TESTS
    // =========================================================================

    public function test_bitget_adapter_identity_and_market_types(): void
    {
        $adapter = new BitgetAdapter;

        self::assertSame('bitget', $adapter->id());
        self::assertSame('Bitget', $adapter->name());
        self::assertSame([
            MarketType::Spot,
            MarketType::Perpetual,
            MarketType::Futures,
        ], $adapter->supportedMarketTypes());
    }

    public function test_bitget_capabilities_matrix(): void
    {
        $adapter = new BitgetAdapter;

        $spotCaps = $adapter->getMarketCapabilities(MarketType::Spot);
        self::assertSame(CapabilityStatus::Supported, $spotCaps[MarketCapability::Candles->value]);
        self::assertSame(CapabilityStatus::Unsupported, $spotCaps[MarketCapability::FundingHistory->value]);

        $perpCaps = $adapter->getMarketCapabilities(MarketType::Perpetual);
        self::assertSame(CapabilityStatus::Supported, $perpCaps[MarketCapability::Candles->value]);
        self::assertSame(CapabilityStatus::Supported, $perpCaps[MarketCapability::FundingHistory->value]);
        self::assertSame(CapabilityStatus::Supported, $perpCaps[MarketCapability::OpenInterest->value]);
        self::assertSame(CapabilityStatus::Supported, $perpCaps[MarketCapability::MarkPrice->value]);
        self::assertSame(CapabilityStatus::Supported, $perpCaps[MarketCapability::Liquidations->value]);

        $optCaps = $adapter->getMarketCapabilities(MarketType::Options);
        foreach ($optCaps as $status) {
            self::assertSame(CapabilityStatus::Unsupported, $status);
        }
    }

    public function test_bitget_instruments_discovery(): void
    {
        Http::fake([
            'https://api.bitget.com/api/v2/spot/public/symbols' => Http::response([
                'code' => '00000',
                'msg' => 'success',
                'data' => [
                    [
                        'symbol' => 'BTCUSDT',
                        'baseCoin' => 'BTC',
                        'quoteCoin' => 'USDT',
                        'pricePrecision' => '2',
                        'quantityPrecision' => '4',
                        'minOrderQty' => '0.0001',
                        'maxOrderQty' => '1000',
                        'status' => 'online',
                    ],
                ],
            ], 200),
            'https://api.bitget.com/api/v2/mix/market/contracts?productType=USDT-FUTURES' => Http::response([
                'code' => '00000',
                'msg' => 'success',
                'data' => [
                    [
                        'symbol' => 'BTCUSDT',
                        'baseCoin' => 'BTC',
                        'quoteCoin' => 'USDT',
                        'pricePrecision' => '2',
                        'volumePlace' => '3',
                        'minOrderNum' => '0.001',
                        'maxOrderNum' => '1000',
                        'symbolStatus' => 'normal',
                    ],
                ],
            ], 200),
        ]);

        $adapter = new BitgetAdapter;

        $spot = $adapter->fetchInstruments(MarketType::Spot);
        self::assertCount(1, $spot);
        self::assertSame('BTCUSDT', $spot[0]['symbol']);
        self::assertSame('0.01', $spot[0]['tick_size']);
        self::assertSame('0.0001', $spot[0]['lot_size']);
        self::assertTrue($spot[0]['is_active']);

        $perp = $adapter->fetchInstruments(MarketType::Perpetual);
        self::assertCount(1, $perp);
        self::assertSame('BTCUSDT', $perp[0]['symbol']);
        self::assertSame('0.01', $perp[0]['tick_size']);
        self::assertSame('0.001', $perp[0]['lot_size']);
        self::assertTrue($perp[0]['is_active']);
    }

    public function test_bitget_candle_normalization(): void
    {
        Http::fake([
            'https://api.bitget.com/api/v2/spot/market/candles*' => Http::response([
                'code' => '00000',
                'msg' => 'success',
                'data' => [
                    // ts, open, high, low, close, volume, quoteVolume
                    ['1704067500000', '42100.0', '42300.0', '42050.0', '42250.0', '50.5', '2130000'],
                    ['1704067200000', '42000.0', '42150.0', '41950.0', '42100.0', '40.2', '1690000'],
                ],
            ], 200),
        ]);

        $adapter = new BitgetAdapter;
        $candles = $adapter->fetchCandles('BTCUSDT', '5m', 2);

        self::assertCount(2, $candles);
        self::assertSame('bitget', $candles[0]['exchange_id']);
        self::assertSame('42000.0', $candles[0]['open']);
        self::assertSame('42150.0', $candles[0]['high']);
        self::assertSame('41950.0', $candles[0]['low']);
        self::assertSame('42100.0', $candles[0]['close']);
        self::assertSame('40.2', $candles[0]['volume']);
        self::assertSame('1690000', $candles[0]['turnover']);
        self::assertTrue($candles[0]['is_closed']);

        self::assertSame('42250.0', $candles[1]['close']);
    }

    public function test_bitget_order_book_normalization(): void
    {
        Http::fake([
            'https://api.bitget.com/api/v2/spot/market/orderbook*' => Http::response([
                'code' => '00000',
                'msg' => 'success',
                'data' => [
                    'asks' => [['42160.0', '1.5']],
                    'bids' => [['42150.0', '2.0']],
                    'ts' => '1704067200000',
                ],
            ], 200),
        ]);

        $adapter = new BitgetAdapter;
        $book = $adapter->fetchOrderBook('BTCUSDT', 1);

        self::assertSame('bitget', $book['exchange_id']);
        self::assertSame([['42150.0', '2.0']], $book['bids']);
        self::assertSame([['42160.0', '1.5']], $book['asks']);
    }

    public function test_bitget_ticker_normalization(): void
    {
        Http::fake([
            'https://api.bitget.com/api/v2/spot/market/tickers*' => Http::response([
                'code' => '00000',
                'msg' => 'success',
                'data' => [
                    [
                        'symbol' => 'BTCUSDT',
                        'lastPr' => '42155.0',
                        'bidPr' => '42150.0',
                        'askPr' => '42160.0',
                        'baseVolume' => '1200.5',
                        'quoteVolume' => '50600000',
                        'ts' => '1704067200000',
                    ],
                ],
            ], 200),
        ]);

        $adapter = new BitgetAdapter;
        $ticker = $adapter->fetchTicker('BTCUSDT');

        self::assertSame('bitget', $ticker['exchange_id']);
        self::assertSame('42155.0', $ticker['last_price']);
        self::assertSame('42150.0', $ticker['bid_price']);
        self::assertSame('42160.0', $ticker['ask_price']);
        self::assertSame('1200.5', $ticker['volume_24h']);
        self::assertSame('50600000', $ticker['turnover_24h']);
    }

    public function test_bitget_funding_history_normalization(): void
    {
        Http::fake([
            'https://api.bitget.com/api/v2/mix/market/history-fund-rate*' => Http::response([
                'code' => '00000',
                'msg' => 'success',
                'data' => [
                    [
                        'symbol' => 'BTCUSDT',
                        'fundingRate' => '0.00012',
                        'fundingTime' => '1704067200000',
                    ],
                ],
            ], 200),
        ]);

        $adapter = new BitgetAdapter;
        $funding = $adapter->fetchFundingHistory('BTCUSDT');

        self::assertCount(1, $funding);
        self::assertSame('bitget', $funding[0]['exchange_id']);
        self::assertSame('0.00012', $funding[0]['funding_rate']);
        self::assertNull($funding[0]['mark_price']);
        self::assertNotEmpty($funding[0]['raw_source_hash']);
    }

    public function test_bitget_open_interest_normalization(): void
    {
        Http::fake([
            'https://api.bitget.com/api/v2/mix/market/open-interest*' => Http::response([
                'code' => '00000',
                'msg' => 'success',
                'data' => [
                    [
                        'symbol' => 'BTCUSDT',
                        'openInterestList' => [
                            [
                                'size' => '32100',
                                'amount' => '1350000000',
                                'ts' => '1704067200000',
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $adapter = new BitgetAdapter;
        $oi = $adapter->fetchOpenInterest('BTCUSDT', '5m');

        self::assertCount(1, $oi);
        self::assertSame('bitget', $oi[0]['exchange_id']);
        self::assertSame('32100', $oi[0]['open_interest']);
        self::assertSame('1350000000', $oi[0]['open_interest_value_usdt']);
    }

    public function test_bitget_health_check(): void
    {
        Http::fake([
            'https://api.bitget.com/api/v2/spot/market/tickers*' => Http::response([
                'code' => '00000',
                'msg' => 'success',
                'data' => [
                    [
                        'symbol' => 'BTCUSDT',
                        'lastPr' => '42000.0',
                        'baseVolume' => '1000.0',
                        'ts' => '1704067200000',
                    ],
                ],
            ], 200),
        ]);

        $adapter = new BitgetAdapter;
        $health = $adapter->healthCheck();

        self::assertSame('HEALTHY', $health['status']);
        self::assertGreaterThanOrEqual(0, $health['latency_ms']);
        self::assertSame('https://api.bitget.com', $health['endpoint']);
    }

    public function test_bitget_api_error_response(): void
    {
        Http::fake([
            'https://api.bitget.com/api/v2/spot/market/tickers*' => Http::response([
                'code' => '40001',
                'msg' => 'Invalid symbol',
                'data' => [],
            ], 200),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Bitget API error [40001]: Invalid symbol');

        $adapter = new BitgetAdapter;
        $adapter->fetchTicker('INVALID');
    }

    // =========================================================================
    // INVARIANTS & SAFETY TESTS
    // =========================================================================

    public function test_strictly_public_read_only_invariants(): void
    {
        Http::fake([
            'https://api.exchange.coinbase.com/*' => Http::response([], 200),
            'https://api.upbit.com/*' => Http::response([], 200),
            'https://www.okx.com/*' => Http::response(['code' => '0', 'msg' => '', 'data' => []], 200),
            'https://api.bitget.com/*' => Http::response(['code' => '00000', 'msg' => 'success', 'data' => []], 200),
        ]);

        $adapters = [
            new CoinbaseAdapter,
            new UpbitAdapter,
            new OkxAdapter,
            new BitgetAdapter,
        ];

        foreach ($adapters as $adapter) {
            $adapter->fetchSymbols();
        }

        Http::assertSent(function (Request $request): bool {
            // Assert only GET requests are ever executed
            self::assertSame('GET', $request->method(), 'Only GET HTTP requests are permitted for public market data.');
            // Assert no private keys or trade authorization headers are present
            self::assertFalse($request->hasHeader('CB-ACCESS-KEY'));
            self::assertFalse($request->hasHeader('CB-ACCESS-SIGN'));
            self::assertFalse($request->hasHeader('OK-ACCESS-KEY'));
            self::assertFalse($request->hasHeader('OK-ACCESS-SIGN'));
            self::assertFalse($request->hasHeader('ACCESS-KEY'));
            self::assertFalse($request->hasHeader('ACCESS-SIGN'));
            self::assertFalse($request->hasHeader('Authorization'));

            return true;
        });
    }

    public function test_rate_limit_retry_exhaustion(): void
    {
        Http::fake([
            'https://api.exchange.coinbase.com/products' => Http::response(['message' => 'rate limit'], 429),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Rate limit exceeded (HTTP 429)');

        $adapter = new CoinbaseAdapter;
        $adapter->fetchSymbols();
    }
}
