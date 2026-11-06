<?php

declare(strict_types=1);

namespace Tests\Feature\Exchanges;

use App\Exchanges\Contracts\ExtendedExchangeAdapter;
use App\Exchanges\Domain\CapabilityStatus;
use App\Exchanges\Domain\MarketCapability;
use App\Exchanges\Domain\MarketType;
use App\Exchanges\Gate\GateAdapter;
use App\Exchanges\Htx\HtxAdapter;
use App\Exchanges\Infrastructure\AbstractExchangeAdapter;
use App\Exchanges\Kraken\KrakenAdapter;
use App\Exchanges\KuCoin\KuCoinAdapter;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class GroupBExchangesTest extends TestCase
{
    private GateAdapter $gate;

    private KuCoinAdapter $kucoin;

    private KrakenAdapter $kraken;

    private HtxAdapter $htx;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gate = new GateAdapter;
        $this->kucoin = new KuCoinAdapter;
        $this->kraken = new KrakenAdapter;
        $this->htx = new HtxAdapter;
    }

    public function test_adapters_implement_extended_exchange_adapter_and_abstract(): void
    {
        $adapters = [$this->gate, $this->kucoin, $this->kraken, $this->htx];

        foreach ($adapters as $adapter) {
            self::assertInstanceOf(AbstractExchangeAdapter::class, $adapter);
            self::assertInstanceOf(ExtendedExchangeAdapter::class, $adapter);
        }

        self::assertSame('gate', $this->gate->id());
        self::assertSame('Gate.io', $this->gate->name());

        self::assertSame('kucoin', $this->kucoin->id());
        self::assertSame('KuCoin', $this->kucoin->name());

        self::assertSame('kraken', $this->kraken->id());
        self::assertSame('Kraken', $this->kraken->name());

        self::assertSame('htx', $this->htx->id());
        self::assertSame('HTX (Huobi)', $this->htx->name());
    }

    public function test_market_types_discovery(): void
    {
        $gateTypes = $this->gate->supportedMarketTypes();
        self::assertContains(MarketType::Spot, $gateTypes);
        self::assertContains(MarketType::Perpetual, $gateTypes);
        self::assertContains(MarketType::Futures, $gateTypes);
        self::assertContains(MarketType::Options, $gateTypes);

        $kucoinTypes = $this->kucoin->supportedMarketTypes();
        self::assertContains(MarketType::Spot, $kucoinTypes);
        self::assertContains(MarketType::Perpetual, $kucoinTypes);
        self::assertContains(MarketType::Futures, $kucoinTypes);

        $krakenTypes = $this->kraken->supportedMarketTypes();
        self::assertContains(MarketType::Spot, $krakenTypes);
        self::assertContains(MarketType::Perpetual, $krakenTypes);
        self::assertContains(MarketType::Futures, $krakenTypes);

        $htxTypes = $this->htx->supportedMarketTypes();
        self::assertContains(MarketType::Spot, $htxTypes);
        self::assertContains(MarketType::Perpetual, $htxTypes);
        self::assertContains(MarketType::Futures, $htxTypes);
    }

    public function test_capabilities_matrix_completeness(): void
    {
        $adapters = [
            'gate' => $this->gate,
            'kucoin' => $this->kucoin,
            'kraken' => $this->kraken,
            'htx' => $this->htx,
        ];

        foreach ($adapters as $name => $adapter) {
            foreach ($adapter->supportedMarketTypes() as $marketType) {
                $matrix = $adapter->getMarketCapabilities($marketType);

                // Verify every MarketCapability has an assessment
                foreach (MarketCapability::cases() as $cap) {
                    self::assertArrayHasKey(
                        $cap->value,
                        $matrix,
                        "Adapter {$name} missing capability {$cap->value} for market {$marketType->value}"
                    );
                    self::assertInstanceOf(
                        CapabilityStatus::class,
                        $matrix[$cap->value],
                        "Capability status for {$name}:{$cap->value} must be an enum instance"
                    );
                }

                // Domain invariants:
                // Candles, OrderBookL2, Trades, Ticker must be Supported for Spot, Perpetual, and Futures
                if ($marketType !== MarketType::Options) {
                    self::assertSame(CapabilityStatus::Supported, $matrix[MarketCapability::Candles->value]);
                } else {
                    self::assertSame(CapabilityStatus::PartiallySupported, $matrix[MarketCapability::Candles->value]);
                }
                self::assertSame(CapabilityStatus::Supported, $matrix[MarketCapability::OrderBookL2->value]);
                self::assertSame(CapabilityStatus::Supported, $matrix[MarketCapability::Trades->value]);
                self::assertSame(CapabilityStatus::Supported, $matrix[MarketCapability::Ticker->value]);

                if ($marketType === MarketType::Spot) {
                    self::assertSame(CapabilityStatus::Unsupported, $matrix[MarketCapability::FundingHistory->value]);
                    self::assertSame(CapabilityStatus::Unsupported, $matrix[MarketCapability::OpenInterest->value]);
                }

                if ($marketType === MarketType::Perpetual) {
                    self::assertSame(CapabilityStatus::Supported, $matrix[MarketCapability::FundingHistory->value]);
                    self::assertSame(CapabilityStatus::Supported, $matrix[MarketCapability::OpenInterest->value]);
                }
            }
        }
    }

    public function test_gate_candle_normalization(): void
    {
        Http::fake([
            'https://api.gateio.ws/api/v4/futures/usdt/candlesticks*' => Http::response([
                [
                    't' => 1700000000,
                    'v' => 1500,
                    'c' => '65000.5',
                    'h' => '65200.0',
                    'l' => '64800.0',
                    'o' => '64900.0',
                    'sum' => '97500750.0',
                ],
            ]),
        ]);

        $candles = $this->gate->fetchCandles('BTCUSDT', '15m', 10);

        self::assertCount(1, $candles);
        $c = $candles[0];
        self::assertSame('gate', $c['exchange_id']);
        self::assertSame('BTCUSDT', $c['symbol']);
        self::assertSame('15m', $c['interval']);
        self::assertSame('2023-11-14T22:13:20Z', $c['open_time_utc']);
        self::assertSame('64900.0', $c['open']);
        self::assertSame('65200.0', $c['high']);
        self::assertSame('64800.0', $c['low']);
        self::assertSame('65000.5', $c['close']);
        self::assertSame('1500', $c['volume']);
        self::assertSame('97500750.0', $c['turnover']);
        self::assertTrue($c['is_closed']);
        self::assertSame(64, strlen($c['raw_source_hash']));
    }

    public function test_gate_funding_history_normalization(): void
    {
        Http::fake([
            'https://api.gateio.ws/api/v4/futures/usdt/funding_rate*' => Http::response([
                [
                    't' => 1700000000,
                    'r' => '0.0001',
                    'mark_price' => '65000.0',
                ],
            ]),
        ]);

        $events = $this->gate->fetchFundingHistory('BTCUSDT');

        self::assertCount(1, $events);
        $e = $events[0];
        self::assertSame('gate', $e['exchange_id']);
        self::assertSame('BTCUSDT', $e['symbol']);
        self::assertSame('2023-11-14T22:13:20Z', $e['funding_time_utc']);
        self::assertSame('0.0001', $e['funding_rate']);
        self::assertSame('65000.0', $e['mark_price']);
        self::assertSame(64, strlen($e['raw_source_hash']));
    }

    public function test_gate_open_interest_normalization(): void
    {
        Http::fake([
            'https://api.gateio.ws/api/v4/futures/usdt/contract_stats*' => Http::response([
                [
                    'time' => 1700000000,
                    'open_interest' => 123456,
                    'open_interest_usd' => '8024640000.0',
                ],
            ]),
        ]);

        $oiRows = $this->gate->fetchOpenInterest('BTCUSDT', '15m');

        self::assertCount(1, $oiRows);
        $row = $oiRows[0];
        self::assertSame('gate', $row['exchange_id']);
        self::assertSame('BTCUSDT', $row['symbol']);
        self::assertSame('2023-11-14T22:13:20Z', $row['timestamp_utc']);
        self::assertSame('123456', $row['open_interest']);
        self::assertSame('8024640000.0', $row['open_interest_value_usdt']);
        self::assertSame(64, strlen($row['raw_source_hash']));
    }

    public function test_gate_order_book_normalization(): void
    {
        Http::fake([
            'https://api.gateio.ws/api/v4/futures/usdt/order_book*' => Http::response([
                'current' => 1700000000.123,
                'bids' => [['p' => '65000.0', 's' => '10']],
                'asks' => [['p' => '65001.0', 's' => '20']],
            ]),
        ]);

        $book = $this->gate->fetchOrderBook('BTCUSDT', 20);

        self::assertSame('gate', $book['exchange_id']);
        self::assertSame('BTCUSDT', $book['symbol']);
        self::assertSame([['65000.0', '10']], $book['bids']);
        self::assertSame([['65001.0', '20']], $book['asks']);
        self::assertSame(64, strlen($book['raw_source_hash']));
    }

    public function test_gate_ticker_normalization(): void
    {
        Http::fake([
            'https://api.gateio.ws/api/v4/futures/usdt/tickers*' => Http::response([
                [
                    'contract' => 'BTC_USDT',
                    'last' => '65000.5',
                    'highest_bid' => '65000.0',
                    'lowest_ask' => '65001.0',
                    'volume_24h' => '12345.6',
                    'volume_24h_quote' => '802464000.0',
                ],
            ]),
        ]);

        $ticker = $this->gate->fetchTicker('BTCUSDT');

        self::assertSame('gate', $ticker['exchange_id']);
        self::assertSame('BTCUSDT', $ticker['symbol']);
        self::assertSame('65000.5', $ticker['last_price']);
        self::assertSame('65000.0', $ticker['bid_price']);
        self::assertSame('65001.0', $ticker['ask_price']);
        self::assertSame('12345.6', $ticker['volume_24h']);
        self::assertSame('802464000.0', $ticker['turnover_24h']);
        self::assertSame(64, strlen($ticker['raw_source_hash']));
    }

    public function test_kucoin_candle_normalization(): void
    {
        Http::fake([
            'https://api-futures.kucoin.com/api/v1/kline/query*' => Http::response([
                'code' => '200000',
                'data' => [
                    [1700000000000, 65000.0, 65200.0, 64800.0, 65100.0, 500.0],
                ],
            ]),
        ]);

        $candles = $this->kucoin->fetchCandles('BTCUSDT', '15m', 10);

        self::assertCount(1, $candles);
        $c = $candles[0];
        self::assertSame('kucoin', $c['exchange_id']);
        self::assertSame('BTCUSDT', $c['symbol']);
        self::assertSame('15m', $c['interval']);
        self::assertSame('2023-11-14T22:13:20.000Z', $c['open_time_utc']);
        self::assertSame('65000', $c['open']);
        self::assertSame('65200', $c['high']);
        self::assertSame('64800', $c['low']);
        self::assertSame('65100', $c['close']);
        self::assertSame('500', $c['volume']);
        self::assertTrue($c['is_closed']);
        self::assertSame(64, strlen($c['raw_source_hash']));
    }

    public function test_kucoin_funding_history_normalization(): void
    {
        Http::fake([
            'https://api-futures.kucoin.com/api/v1/funding-history*' => Http::response([
                'code' => '200000',
                'data' => [
                    'dataList' => [
                        [
                            'id' => 101,
                            'symbol' => 'XBTUSDTM',
                            'timePoint' => 1700000000000,
                            'fundingRate' => 0.00015,
                            'markPrice' => 65050.0,
                        ],
                    ],
                ],
            ]),
        ]);

        $events = $this->kucoin->fetchFundingHistory('BTCUSDT');

        self::assertCount(1, $events);
        $e = $events[0];
        self::assertSame('kucoin', $e['exchange_id']);
        self::assertSame('BTCUSDT', $e['symbol']);
        self::assertSame('2023-11-14T22:13:20.000Z', $e['funding_time_utc']);
        self::assertSame('0.00015', $e['funding_rate']);
        self::assertSame('65050', $e['mark_price']);
        self::assertSame(64, strlen($e['raw_source_hash']));
    }

    public function test_kucoin_open_interest_normalization(): void
    {
        Http::fake([
            'https://api-futures.kucoin.com/api/v1/openInterest/query*' => Http::response([
                'code' => '200000',
                'data' => [
                    'symbol' => 'XBTUSDTM',
                    'value' => '45678',
                    'timePoint' => 1700000000000,
                ],
            ]),
        ]);

        $oiRows = $this->kucoin->fetchOpenInterest('BTCUSDT', '15m');

        self::assertCount(1, $oiRows);
        $row = $oiRows[0];
        self::assertSame('kucoin', $row['exchange_id']);
        self::assertSame('BTCUSDT', $row['symbol']);
        self::assertSame('2023-11-14T22:13:20.000Z', $row['timestamp_utc']);
        self::assertSame('45678', $row['open_interest']);
        self::assertNull($row['open_interest_value_usdt']);
        self::assertSame(64, strlen($row['raw_source_hash']));
    }

    public function test_kucoin_order_book_normalization(): void
    {
        Http::fake([
            'https://api-futures.kucoin.com/api/v1/level2/depth20*' => Http::response([
                'code' => '200000',
                'data' => [
                    'time' => 1700000000000,
                    'bids' => [['65000.0', 15]],
                    'asks' => [['65001.0', 25]],
                ],
            ]),
        ]);

        $book = $this->kucoin->fetchOrderBook('BTCUSDT', 20);

        self::assertSame('kucoin', $book['exchange_id']);
        self::assertSame('BTCUSDT', $book['symbol']);
        self::assertSame([['65000.0', '15']], $book['bids']);
        self::assertSame([['65001.0', '25']], $book['asks']);
        self::assertSame(64, strlen($book['raw_source_hash']));
    }

    public function test_kucoin_ticker_normalization(): void
    {
        Http::fake([
            'https://api-futures.kucoin.com/api/v1/ticker*' => Http::response([
                'code' => '200000',
                'data' => [
                    'symbol' => 'XBTUSDTM',
                    'price' => '65000.0',
                    'bestBidPrice' => '64999.0',
                    'bestAskPrice' => '65001.0',
                    'volume' => 34567,
                ],
            ]),
        ]);

        $ticker = $this->kucoin->fetchTicker('BTCUSDT');

        self::assertSame('kucoin', $ticker['exchange_id']);
        self::assertSame('BTCUSDT', $ticker['symbol']);
        self::assertSame('65000.0', $ticker['last_price']);
        self::assertSame('64999.0', $ticker['bid_price']);
        self::assertSame('65001.0', $ticker['ask_price']);
        self::assertSame('34567', $ticker['volume_24h']);
        self::assertSame(64, strlen($ticker['raw_source_hash']));
    }

    public function test_kraken_candle_normalization(): void
    {
        Http::fake([
            'https://futures.kraken.com/derivatives/api/v3/charts/v1/trade/*' => Http::response([
                'result' => 'success',
                'candles' => [
                    [
                        'time' => 1700000000000,
                        'open' => '65000.0',
                        'high' => '65300.0',
                        'low' => '64900.0',
                        'close' => '65200.0',
                        'volume' => '120.5',
                    ],
                ],
            ]),
        ]);

        $candles = $this->kraken->fetchCandles('BTCUSDT', '15m', 10);

        self::assertCount(1, $candles);
        $c = $candles[0];
        self::assertSame('kraken', $c['exchange_id']);
        self::assertSame('BTCUSDT', $c['symbol']);
        self::assertSame('15m', $c['interval']);
        self::assertSame('2023-11-14T22:13:20.000Z', $c['open_time_utc']);
        self::assertSame('65000.0', $c['open']);
        self::assertSame('65300.0', $c['high']);
        self::assertSame('64900.0', $c['low']);
        self::assertSame('65200.0', $c['close']);
        self::assertSame('120.5', $c['volume']);
        self::assertTrue($c['is_closed']);
        self::assertSame(64, strlen($c['raw_source_hash']));
    }

    public function test_kraken_funding_history_normalization(): void
    {
        Http::fake([
            'https://futures.kraken.com/derivatives/api/v3/historicalfundingrates*' => Http::response([
                'result' => 'success',
                'rates' => [
                    [
                        'timestamp' => '2023-11-14T22:13:20.000Z',
                        'fundingRate' => 0.00008,
                        'relativeFundingRate' => 0.00008,
                    ],
                ],
            ]),
        ]);

        $events = $this->kraken->fetchFundingHistory('BTCUSDT');

        self::assertCount(1, $events);
        $e = $events[0];
        self::assertSame('kraken', $e['exchange_id']);
        self::assertSame('BTCUSDT', $e['symbol']);
        self::assertSame('2023-11-14T22:13:20Z', $e['funding_time_utc']);
        self::assertSame('8.0E-5', $e['funding_rate']);
        self::assertSame(64, strlen($e['raw_source_hash']));
    }

    public function test_kraken_open_interest_normalization(): void
    {
        Http::fake([
            'https://futures.kraken.com/derivatives/api/v3/tickers*' => Http::response([
                'result' => 'success',
                'tickers' => [
                    [
                        'symbol' => 'PI_XBTUSD',
                        'last' => 65000.0,
                        'bid' => 64999.0,
                        'ask' => 65001.0,
                        'openInterest' => 98765,
                        'vol24h' => 12345678,
                        'lastTime' => '2023-11-14T22:13:20.000Z',
                    ],
                ],
            ]),
        ]);

        $oiRows = $this->kraken->fetchOpenInterest('BTCUSDT', '15m');

        self::assertCount(1, $oiRows);
        $row = $oiRows[0];
        self::assertSame('kraken', $row['exchange_id']);
        self::assertSame('BTCUSDT', $row['symbol']);
        self::assertSame('2023-11-14T22:13:20Z', $row['timestamp_utc']);
        self::assertSame('98765', $row['open_interest']);
        self::assertSame(64, strlen($row['raw_source_hash']));
    }

    public function test_kraken_order_book_normalization(): void
    {
        Http::fake([
            'https://futures.kraken.com/derivatives/api/v3/orderbook*' => Http::response([
                'result' => 'success',
                'serverTime' => '2023-11-14T22:13:20.000Z',
                'orderBook' => [
                    'bids' => [[65000.0, 50]],
                    'asks' => [[65001.0, 75]],
                ],
            ]),
        ]);

        $book = $this->kraken->fetchOrderBook('BTCUSDT', 20);

        self::assertSame('kraken', $book['exchange_id']);
        self::assertSame('BTCUSDT', $book['symbol']);
        self::assertSame([['65000', '50']], $book['bids']);
        self::assertSame([['65001', '75']], $book['asks']);
        self::assertSame(64, strlen($book['raw_source_hash']));
    }

    public function test_kraken_ticker_normalization(): void
    {
        Http::fake([
            'https://futures.kraken.com/derivatives/api/v3/tickers*' => Http::response([
                'result' => 'success',
                'tickers' => [
                    [
                        'symbol' => 'PI_XBTUSD',
                        'last' => 65000.0,
                        'bid' => 64999.0,
                        'ask' => 65001.0,
                        'vol24h' => 45678,
                    ],
                ],
            ]),
        ]);

        $ticker = $this->kraken->fetchTicker('BTCUSDT');

        self::assertSame('kraken', $ticker['exchange_id']);
        self::assertSame('BTCUSDT', $ticker['symbol']);
        self::assertSame('65000', $ticker['last_price']);
        self::assertSame('64999', $ticker['bid_price']);
        self::assertSame('65001', $ticker['ask_price']);
        self::assertSame('45678', $ticker['volume_24h']);
        self::assertSame(64, strlen($ticker['raw_source_hash']));
    }

    public function test_htx_candle_normalization(): void
    {
        Http::fake([
            'https://api.hbdm.com/linear-swap-ex/market/history/kline*' => Http::response([
                'status' => 'ok',
                'data' => [
                    [
                        'id' => 1700000000,
                        'open' => 65000.0,
                        'high' => 65250.0,
                        'low' => 64950.0,
                        'close' => 65150.0,
                        'amount' => 850.5,
                        'vol' => 55400000.0,
                    ],
                ],
            ]),
        ]);

        $candles = $this->htx->fetchCandles('BTCUSDT', '15m', 10);

        self::assertCount(1, $candles);
        $c = $candles[0];
        self::assertSame('htx', $c['exchange_id']);
        self::assertSame('BTCUSDT', $c['symbol']);
        self::assertSame('15m', $c['interval']);
        self::assertSame('2023-11-14T22:13:20Z', $c['open_time_utc']);
        self::assertSame('65000', $c['open']);
        self::assertSame('65250', $c['high']);
        self::assertSame('64950', $c['low']);
        self::assertSame('65150', $c['close']);
        self::assertSame('850.5', $c['volume']);
        self::assertSame('55400000', $c['turnover']);
        self::assertTrue($c['is_closed']);
        self::assertSame(64, strlen($c['raw_source_hash']));
    }

    public function test_htx_funding_history_normalization(): void
    {
        Http::fake([
            'https://api.hbdm.com/linear-swap-api/v1/swap_historical_funding_rate*' => Http::response([
                'status' => 'ok',
                'data' => [
                    'data' => [
                        [
                            'contract_code' => 'BTC-USDT',
                            'funding_rate' => '0.00012',
                            'funding_time' => 1700000000000,
                        ],
                    ],
                ],
            ]),
        ]);

        $events = $this->htx->fetchFundingHistory('BTCUSDT');

        self::assertCount(1, $events);
        $e = $events[0];
        self::assertSame('htx', $e['exchange_id']);
        self::assertSame('BTCUSDT', $e['symbol']);
        self::assertSame('2023-11-14T22:13:20.000Z', $e['funding_time_utc']);
        self::assertSame('0.00012', $e['funding_rate']);
        self::assertSame(64, strlen($e['raw_source_hash']));
    }

    public function test_htx_open_interest_normalization(): void
    {
        Http::fake([
            'https://api.hbdm.com/linear-swap-api/v1/swap_open_interest*' => Http::response([
                'status' => 'ok',
                'data' => [
                    [
                        'contract_code' => 'BTC-USDT',
                        'volume' => 76543,
                        'amount' => 76.543,
                    ],
                ],
            ]),
        ]);

        $oiRows = $this->htx->fetchOpenInterest('BTCUSDT', '15m');

        self::assertCount(1, $oiRows);
        $row = $oiRows[0];
        self::assertSame('htx', $row['exchange_id']);
        self::assertSame('BTCUSDT', $row['symbol']);
        self::assertSame('76543', $row['open_interest']);
        self::assertSame('76.543', $row['open_interest_value_usdt']);
        self::assertSame(64, strlen($row['raw_source_hash']));
    }

    public function test_htx_order_book_normalization(): void
    {
        Http::fake([
            'https://api.hbdm.com/linear-swap-ex/market/depth*' => Http::response([
                'status' => 'ok',
                'tick' => [
                    'ts' => 1700000000000,
                    'bids' => [[65000.0, 12.5]],
                    'asks' => [[65001.0, 18.0]],
                ],
            ]),
        ]);

        $book = $this->htx->fetchOrderBook('BTCUSDT', 20);

        self::assertSame('htx', $book['exchange_id']);
        self::assertSame('BTCUSDT', $book['symbol']);
        self::assertSame([['65000', '12.5']], $book['bids']);
        self::assertSame([['65001', '18']], $book['asks']);
        self::assertSame(64, strlen($book['raw_source_hash']));
    }

    public function test_htx_ticker_normalization(): void
    {
        Http::fake([
            'https://api.hbdm.com/linear-swap-ex/market/detail/merged*' => Http::response([
                'status' => 'ok',
                'tick' => [
                    'close' => 65000.0,
                    'bid' => [64999.0, 5.0],
                    'ask' => [65001.0, 4.0],
                    'amount' => 25000.0,
                    'vol' => 1625000000.0,
                    'ts' => 1700000000000,
                ],
            ]),
        ]);

        $ticker = $this->htx->fetchTicker('BTCUSDT');

        self::assertSame('htx', $ticker['exchange_id']);
        self::assertSame('BTCUSDT', $ticker['symbol']);
        self::assertSame('65000', $ticker['last_price']);
        self::assertSame('64999', $ticker['bid_price']);
        self::assertSame('65001', $ticker['ask_price']);
        self::assertSame('25000', $ticker['volume_24h']);
        self::assertSame('1625000000', $ticker['turnover_24h']);
        self::assertSame(64, strlen($ticker['raw_source_hash']));
    }

    public function test_instruments_discovery_spot_and_perpetual(): void
    {
        Http::fake([
            'https://api.gateio.ws/api/v4/spot/currency_pairs*' => Http::response([
                ['id' => 'BTC_USDT', 'base' => 'BTC', 'quote' => 'USDT', 'precision' => 2, 'amount_precision' => 4, 'trade_status' => 'tradable'],
            ]),
            'https://api.gateio.ws/api/v4/futures/usdt/contracts*' => Http::response([
                ['name' => 'BTC_USDT', 'order_price_round' => '0.1', 'order_size_min' => 1, 'in_delisting' => false],
            ]),
            'https://api.kucoin.com/api/v1/symbols*' => Http::response([
                'code' => '200000',
                'data' => [
                    ['symbol' => 'BTC-USDT', 'baseCurrency' => 'BTC', 'quoteCurrency' => 'USDT', 'enableTrading' => true],
                ],
            ]),
            'https://api-futures.kucoin.com/api/v1/contracts/active*' => Http::response([
                'code' => '200000',
                'data' => [
                    ['symbol' => 'XBTUSDTM', 'baseCurrency' => 'XBT', 'quoteCurrency' => 'USDT', 'status' => 'Open'],
                ],
            ]),
            'https://api.kraken.com/0/public/AssetPairs*' => Http::response([
                'result' => [
                    'XXBTZUSD' => ['altname' => 'XBTUSD', 'base' => 'XXBT', 'quote' => 'ZUSD', 'status' => 'online'],
                ],
            ]),
            'https://futures.kraken.com/derivatives/api/v3/instruments*' => Http::response([
                'result' => 'success',
                'instruments' => [
                    ['symbol' => 'PI_XBTUSD', 'tradeable' => true],
                ],
            ]),
            'https://api.huobi.pro/v1/common/symbols*' => Http::response([
                'status' => 'ok',
                'data' => [
                    ['symbol' => 'btcusdt', 'base-currency' => 'btc', 'quote-currency' => 'usdt', 'state' => 'online'],
                ],
            ]),
            'https://api.hbdm.com/linear-swap-api/v1/swap_contract_info*' => Http::response([
                'status' => 'ok',
                'data' => [
                    ['contract_code' => 'BTC-USDT', 'contract_status' => 1],
                ],
            ]),
        ]);

        $gateSpot = $this->gate->fetchInstruments(MarketType::Spot);
        self::assertCount(1, $gateSpot);
        self::assertSame('BTC_USDT', $gateSpot[0]['symbol']);

        $gatePerp = $this->gate->fetchInstruments(MarketType::Perpetual);
        self::assertCount(1, $gatePerp);
        self::assertSame('BTC_USDT', $gatePerp[0]['symbol']);

        $kucoinSpot = $this->kucoin->fetchInstruments(MarketType::Spot);
        self::assertCount(1, $kucoinSpot);
        self::assertSame('BTC-USDT', $kucoinSpot[0]['symbol']);

        $kucoinPerp = $this->kucoin->fetchInstruments(MarketType::Perpetual);
        self::assertCount(1, $kucoinPerp);
        self::assertSame('XBTUSDTM', $kucoinPerp[0]['symbol']);

        $krakenSpot = $this->kraken->fetchInstruments(MarketType::Spot);
        self::assertCount(1, $krakenSpot);
        self::assertSame('XBTUSD', $krakenSpot[0]['symbol']);

        $krakenPerp = $this->kraken->fetchInstruments(MarketType::Perpetual);
        self::assertCount(1, $krakenPerp);
        self::assertSame('PI_XBTUSD', $krakenPerp[0]['symbol']);

        $htxSpot = $this->htx->fetchInstruments(MarketType::Spot);
        self::assertCount(1, $htxSpot);
        self::assertSame('btcusdt', $htxSpot[0]['symbol']);

        $htxPerp = $this->htx->fetchInstruments(MarketType::Perpetual);
        self::assertCount(1, $htxPerp);
        self::assertSame('BTC-USDT', $htxPerp[0]['symbol']);
    }

    public function test_health_check_healthy(): void
    {
        Http::fake([
            'https://api.gateio.ws/api/v4/futures/usdt/tickers*' => Http::response([
                ['contract' => 'BTC_USDT', 'last' => '65000.0'],
            ]),
        ]);

        $health = $this->gate->healthCheck();
        self::assertSame('HEALTHY', $health['status']);
        self::assertNull($health['error']);
        self::assertGreaterThanOrEqual(0, $health['latency_ms']);
    }

    public function test_health_check_degraded_on_failure(): void
    {
        Http::fake([
            'https://api.gateio.ws/api/v4/futures/usdt/tickers*' => Http::response('Server Error', 500),
        ]);

        $healthDegraded = $this->gate->healthCheck();
        self::assertSame('DEGRADED', $healthDegraded['status']);
        self::assertNotNull($healthDegraded['error']);
    }

    public function test_rate_limit_429_exhaustion_throws_runtime_exception(): void
    {
        Http::fake([
            'https://api.gateio.ws/api/v4/futures/usdt/candlesticks*' => Http::response('Too Many Requests', 429),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Rate limit exceeded (HTTP 429)');

        $this->gate->fetchCandles('BTCUSDT', '15m', 10);
    }
}
