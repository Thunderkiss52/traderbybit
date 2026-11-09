<?php

declare(strict_types=1);

namespace Tests\Unit\Exchanges;

use App\Exchanges\Contracts\ExchangeAdapter;
use App\Exchanges\Contracts\ExtendedExchangeAdapter;
use App\Exchanges\Domain\CapabilityStatus;
use App\Exchanges\Domain\MarketCapability;
use App\Exchanges\Domain\MarketType;
use App\Exchanges\ExchangeRegistry;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ExchangeAdapterContractTest extends TestCase
{
    private const EXPECTED_EXCHANGES = [
        'bybit',
        'binance',
        'coinbase',
        'upbit',
        'okx',
        'bitget',
        'gate',
        'kucoin',
        'kraken',
        'htx',
    ];

    public function test_all_ten_exchanges_are_registered_and_resolvable(): void
    {
        $registry = app(ExchangeRegistry::class);

        foreach (self::EXPECTED_EXCHANGES as $id) {
            self::assertTrue($registry->hasAdapter($id), "ExchangeRegistry must have adapter for '{$id}'.");
            $adapter = $registry->resolve($id);
            self::assertInstanceOf(ExchangeAdapter::class, $adapter);
            self::assertInstanceOf(ExtendedExchangeAdapter::class, $adapter);
            self::assertSame($id, $adapter->id());
            self::assertNotEmpty($adapter->name());
        }
    }

    public function test_all_adapters_conform_to_extended_exchange_adapter_contract(): void
    {
        $registry = app(ExchangeRegistry::class);

        foreach (self::EXPECTED_EXCHANGES as $id) {
            $adapter = $registry->resolve($id);

            // 1. Identity & Name
            self::assertSame($id, $adapter->id(), "Adapter ID must match for {$id}");
            self::assertIsString($adapter->name(), "Adapter name must be a string for {$id}");
            self::assertNotEmpty($adapter->name(), "Adapter name must not be empty for {$id}");

            // 2. Supported Market Types
            $types = $adapter->supportedMarketTypes();
            self::assertIsArray($types, "supportedMarketTypes must return an array for {$id}");
            self::assertNotEmpty($types, "supportedMarketTypes must have at least one type for {$id}");
            foreach ($types as $type) {
                self::assertInstanceOf(MarketType::class, $type);
            }

            // 3. Capabilities Map
            foreach ($types as $type) {
                $capabilities = $adapter->getMarketCapabilities($type);
                self::assertIsArray($capabilities, "getMarketCapabilities must return array for {$id} on {$type->value}");

                foreach (MarketCapability::cases() as $cap) {
                    self::assertArrayHasKey($cap->value, $capabilities, "Capability '{$cap->value}' missing for {$id}");
                    self::assertInstanceOf(CapabilityStatus::class, $capabilities[$cap->value]);
                }
            }

            // 4. Interface method reflection check
            foreach ([
                'id',
                'name',
                'supportedMarketTypes',
                'getMarketCapabilities',
                'fetchCandles',
                'fetchFundingHistory',
                'fetchOpenInterest',
                'fetchSymbols',
                'fetchInstruments',
                'fetchOrderBook',
                'fetchTicker',
                'healthCheck',
            ] as $method) {
                self::assertTrue(method_exists($adapter, $method), "Adapter {$id} must implement {$method}");
            }
        }
    }

    public function test_bybit_and_binance_specifically_implement_extended_methods_without_error(): void
    {
        $registry = app(ExchangeRegistry::class);

        $bybit = $registry->resolve('bybit');
        self::assertSame('bybit', $bybit->id());
        self::assertSame('Bybit Linear Perpetual', $bybit->name());
        self::assertContains(MarketType::Perpetual, $bybit->supportedMarketTypes());
        self::assertContains(MarketType::Spot, $bybit->supportedMarketTypes());

        $binance = $registry->resolve('binance');
        self::assertSame('binance', $binance->id());
        self::assertSame('Binance USD(S)-M Futures', $binance->name());
        self::assertSame([MarketType::Perpetual], $binance->supportedMarketTypes());

        // Verify method execution for Bybit and Binance
        $bybitInstruments = $bybit->fetchInstruments(MarketType::Perpetual);
        self::assertNotEmpty($bybitInstruments);
        self::assertSame('BTCUSDT', $bybitInstruments[0]['symbol']);

        $binanceInstruments = $binance->fetchInstruments(MarketType::Perpetual);
        self::assertNotEmpty($binanceInstruments);
        self::assertSame('BTCUSDT', $binanceInstruments[0]['symbol']);
    }

    public function test_bybit_and_binance_handle_orderbook_and_ticker_calls(): void
    {
        Http::fake([
            'https://api.bybit.com/v5/market/orderbook*' => Http::response([
                'retCode' => 0,
                'retMsg' => 'OK',
                'result' => [
                    's' => 'BTCUSDT',
                    'b' => [['65000.00', '1.5']],
                    'a' => [['65010.00', '2.0']],
                    'ts' => 1700000000000,
                ],
            ]),
            'https://api.bybit.com/v5/market/tickers*' => Http::response([
                'retCode' => 0,
                'retMsg' => 'OK',
                'result' => [
                    'list' => [
                        [
                            'symbol' => 'BTCUSDT',
                            'lastPrice' => '65005.00',
                            'bid1Price' => '65000.00',
                            'ask1Price' => '65010.00',
                            'volume24h' => '12000.5',
                            'turnover24h' => '780000000.0',
                            'fundingRate' => '0.0001',
                            'openInterest' => '1000.0',
                        ],
                    ],
                ],
            ]),
            'https://fapi.binance.com/fapi/v1/depth*' => Http::response([
                'bids' => [['65000.00', '1.5']],
                'asks' => [['65010.00', '2.0']],
            ]),
            'https://fapi.binance.com/fapi/v1/ticker/24hr*' => Http::response([
                'symbol' => 'BTCUSDT',
                'lastPrice' => '65005.00',
                'bidPrice' => '65000.00',
                'askPrice' => '65010.00',
                'volume' => '12000.5',
                'quoteVolume' => '780000000.0',
            ]),
            'https://fapi.binance.com/fapi/v1/time*' => Http::response([
                'serverTime' => 1700000000000,
            ]),
        ]);

        $registry = app(ExchangeRegistry::class);

        $bybit = $registry->resolve('bybit');
        $bybitBook = $bybit->fetchOrderBook('BTCUSDT', 10);
        self::assertSame('bybit', $bybitBook['exchange_id']);
        self::assertSame('BTCUSDT', $bybitBook['symbol']);
        self::assertCount(1, $bybitBook['bids']);

        $bybitTicker = $bybit->fetchTicker('BTCUSDT');
        self::assertSame('bybit', $bybitTicker['exchange_id']);
        self::assertSame('65005.00', $bybitTicker['last_price']);

        $bybitHealth = $bybit->healthCheck();
        self::assertSame('HEALTHY', $bybitHealth['status']);

        $binance = $registry->resolve('binance');
        $binanceBook = $binance->fetchOrderBook('BTCUSDT', 10);
        self::assertSame('binance', $binanceBook['exchange_id']);
        self::assertSame('BTCUSDT', $binanceBook['symbol']);
        self::assertCount(1, $binanceBook['bids']);

        $binanceTicker = $binance->fetchTicker('BTCUSDT');
        self::assertSame('binance', $binanceTicker['exchange_id']);
        self::assertSame('65005.00', $binanceTicker['last_price']);

        $binanceHealth = $binance->healthCheck();
        self::assertSame('HEALTHY', $binanceHealth['status']);
    }
}
