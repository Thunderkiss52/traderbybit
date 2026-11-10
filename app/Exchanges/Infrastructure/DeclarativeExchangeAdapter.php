<?php

declare(strict_types=1);

namespace App\Exchanges\Infrastructure;

use App\Exchanges\Contracts\ExtendedExchangeAdapter;
use App\Exchanges\Domain\CapabilityStatus;
use App\Exchanges\Domain\MarketCapability;
use App\Exchanges\Domain\MarketType;
use App\HistoricalData\UniversalAcquisitionService;
use DateTimeImmutable;
use Throwable;

/**
 * Universal declarative adapter configured dynamically from an ExchangeRecord or ExchangeManifest.
 * Provides public market data ingestion, instruments discovery, and connectivity testing without dedicated code.
 */
class DeclarativeExchangeAdapter extends AbstractExchangeAdapter implements ExtendedExchangeAdapter
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly string $exchangeId,
        private readonly string $exchangeName,
        private readonly string $exchangeBaseUrl,
        private readonly array $config = [],
    ) {
        $this->baseUrl = $this->exchangeBaseUrl;
        $this->timeoutSeconds = (int) ($this->config['timeout_seconds'] ?? 8);
        $this->maxRetries = 3;
    }

    public function id(): string
    {
        return $this->exchangeId;
    }

    public function name(): string
    {
        return $this->exchangeName;
    }

    public function fetchCandles(string $symbol, string $interval, int $limit, ?int $startMs = null, ?int $endMs = null): array
    {
        $normalizedTf = UniversalAcquisitionService::normalizeInterval($interval);
        $endpoint = $this->config['endpoints']['candles'] ?? '/api/v1/klines';

        $query = [
            'symbol' => $symbol,
            'interval' => $normalizedTf,
            'limit' => min(500, max(1, $limit)),
        ];

        if ($startMs !== null) {
            $query['startTime'] = $startMs;
        }
        if ($endMs !== null) {
            $query['endTime'] = $endMs;
        }

        try {
            $response = $this->get($endpoint, $query);
            $rawCandles = $response['data'] ?? ($response['result'] ?? ($response['candles'] ?? $response));

            if (! is_array($rawCandles)) {
                return [];
            }

            $results = [];
            foreach ($rawCandles as $c) {
                if (! is_array($c)) {
                    continue;
                }

                // Handle standard OHLCV array format [time, open, high, low, close, volume]
                if (isset($c[0], $c[1], $c[2], $c[3], $c[4])) {
                    $ts = is_numeric($c[0]) ? (int) $c[0] : (int) (strtotime((string) $c[0]) * 1000);
                    if ($ts < 1_000_000_000_000) {
                        $ts *= 1000;
                    }
                    $openTime = (new DateTimeImmutable('@'.((int) ($ts / 1000))))->format('Y-m-d H:i:s.uP');

                    $results[] = [
                        'open_time' => $openTime,
                        'open' => (string) $c[1],
                        'high' => (string) $c[2],
                        'low' => (string) $c[3],
                        'close' => (string) $c[4],
                        'volume' => (string) ($c[5] ?? '0'),
                        'symbol' => $symbol,
                        'interval' => $interval,
                        'turnover' => isset($c[6]) ? (string) $c[6] : null,
                    ];
                } elseif (isset($c['open'], $c['high'], $c['low'], $c['close'])) {
                    // Object format
                    $rawTime = $c['time'] ?? ($c['open_time'] ?? ($c['timestamp'] ?? time()));
                    $ts = is_numeric($rawTime) ? (int) $rawTime : (int) (strtotime((string) $rawTime) * 1000);
                    if ($ts < 1_000_000_000_000) {
                        $ts *= 1000;
                    }
                    $openTime = (new DateTimeImmutable('@'.((int) ($ts / 1000))))->format('Y-m-d H:i:s.uP');

                    $results[] = [
                        'open_time' => $openTime,
                        'open' => (string) $c['open'],
                        'high' => (string) $c['high'],
                        'low' => (string) $c['low'],
                        'close' => (string) $c['close'],
                        'volume' => (string) ($c['volume'] ?? ($c['vol'] ?? '0')),
                        'symbol' => $symbol,
                        'interval' => $interval,
                        'turnover' => isset($c['turnover']) ? (string) $c['turnover'] : null,
                    ];
                }
            }

            return $results;
        } catch (Throwable $e) {
            return [];
        }
    }

    public function fetchFundingHistory(string $symbol, ?int $startMs = null, ?int $endMs = null): array
    {
        $endpoint = $this->config['endpoints']['funding'] ?? '/api/v1/funding-rate';
        try {
            $query = ['symbol' => $symbol];
            if ($startMs !== null) {
                $query['startTime'] = $startMs;
            }
            if ($endMs !== null) {
                $query['endTime'] = $endMs;
            }
            $response = $this->get($endpoint, $query);

            return (array) ($response['data'] ?? ($response['result'] ?? []));
        } catch (Throwable) {
            return [];
        }
    }

    public function fetchOpenInterest(string $symbol, string $interval, ?int $startMs = null, ?int $endMs = null): array
    {
        $endpoint = $this->config['endpoints']['open_interest'] ?? '/api/v1/open-interest';
        try {
            $query = ['symbol' => $symbol, 'interval' => $interval];
            if ($startMs !== null) {
                $query['startTime'] = $startMs;
            }
            if ($endMs !== null) {
                $query['endTime'] = $endMs;
            }
            $response = $this->get($endpoint, $query);

            return (array) ($response['data'] ?? ($response['result'] ?? []));
        } catch (Throwable) {
            return [];
        }
    }

    public function fetchOrderBook(string $symbol, int $depth = 20): array
    {
        $endpoint = $this->config['endpoints']['order_book'] ?? '/api/v1/depth';
        try {
            $raw = $this->get($endpoint, ['symbol' => $symbol, 'limit' => $depth]);
            $data = $raw['data'] ?? ($raw['result'] ?? $raw);

            return [
                'exchange_id' => $this->exchangeId,
                'symbol' => $symbol,
                'timestamp_utc' => now('UTC')->toISOString(),
                'bids' => (array) ($data['bids'] ?? []),
                'asks' => (array) ($data['asks'] ?? []),
                'raw_source_hash' => hash('sha256', (string) json_encode($data)),
            ];
        } catch (Throwable) {
            return [
                'exchange_id' => $this->exchangeId,
                'symbol' => $symbol,
                'timestamp_utc' => now('UTC')->toISOString(),
                'bids' => [],
                'asks' => [],
                'raw_source_hash' => hash('sha256', ''),
            ];
        }
    }

    public function fetchTicker(string $symbol): array
    {
        $endpoint = $this->config['endpoints']['ticker'] ?? '/api/v1/ticker';
        try {
            $raw = $this->get($endpoint, ['symbol' => $symbol]);
            $item = $raw['data'] ?? ($raw['result'] ?? $raw);

            return [
                'symbol' => $symbol,
                'bid' => (string) ($item['bid'] ?? ($item['bidPrice'] ?? ($item['best_bid'] ?? '0'))),
                'ask' => (string) ($item['ask'] ?? ($item['askPrice'] ?? ($item['best_ask'] ?? '0'))),
                'last_price' => (string) ($item['last'] ?? ($item['lastPrice'] ?? ($item['price'] ?? '0'))),
                'mark_price' => (string) ($item['mark_price'] ?? ($item['markPrice'] ?? ($item['last'] ?? '0'))),
                'index_price' => (string) ($item['index_price'] ?? ($item['indexPrice'] ?? '0')),
                'funding_rate' => (string) ($item['funding_rate'] ?? ($item['fundingRate'] ?? '0.0001')),
                'open_interest' => (string) ($item['open_interest'] ?? ($item['openInterest'] ?? '0')),
                'timestamp_ms' => (int) (microtime(true) * 1000),
            ];
        } catch (Throwable) {
            return [
                'symbol' => $symbol,
                'bid' => '0',
                'ask' => '0',
                'last_price' => '0',
                'mark_price' => '0',
                'index_price' => '0',
                'funding_rate' => '0.0001',
                'open_interest' => '0',
                'timestamp_ms' => (int) (microtime(true) * 1000),
            ];
        }
    }

    public function fetchSymbols(): array
    {
        $inst = $this->fetchInstruments(MarketType::Perpetual);

        return array_map(static fn ($row): string => (string) $row['symbol'], $inst);
    }

    public function fetchInstruments(MarketType $marketType): array
    {
        $endpoint = $this->config['endpoints']['instruments'] ?? '/api/v1/instruments';
        try {
            $raw = $this->get($endpoint, ['type' => $marketType->value]);
            $list = $raw['data'] ?? ($raw['result'] ?? ($raw['instruments'] ?? ($raw['symbols'] ?? [])));

            $res = [];
            foreach ($list as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $sym = (string) ($row['symbol'] ?? ($row['name'] ?? ''));
                if ($sym === '') {
                    continue;
                }
                $res[] = [
                    'symbol' => $sym,
                    'base_asset' => (string) ($row['base_asset'] ?? ($row['base'] ?? ($row['baseCurrency'] ?? ''))),
                    'quote_asset' => (string) ($row['quote_asset'] ?? ($row['quote'] ?? ($row['quoteCurrency'] ?? 'USDT'))),
                    'market_type' => $marketType->value,
                    'tick_size' => (string) ($row['tick_size'] ?? ($row['price_tick'] ?? ($this->config['capabilities']['price_precision'] ? '0.'.str_repeat('0', max(0, $this->config['capabilities']['price_precision'] - 1)).'1' : '0.01'))),
                    'lot_size' => (string) ($row['lot_size'] ?? ($row['qty_step'] ?? ($this->config['capabilities']['qty_precision'] ? '0.'.str_repeat('0', max(0, $this->config['capabilities']['qty_precision'] - 1)).'1' : '0.001'))),
                    'min_order_qty' => (string) ($row['min_order_qty'] ?? ($this->config['capabilities']['min_qty'] ?? '0.001')),
                    'max_order_qty' => (string) ($row['max_order_qty'] ?? '1000'),
                    'min_notional' => (string) ($row['min_notional'] ?? ($this->config['capabilities']['min_notional'] ?? '5.0')),
                    'is_active' => (bool) ($row['is_active'] ?? true),
                    'contract_type' => strtolower($marketType->value),
                ];
            }

            return $res;
        } catch (Throwable) {
            // Default configured symbols fallback
            return [
                [
                    'symbol' => 'BTCUSDT',
                    'base_asset' => 'BTC',
                    'quote_asset' => 'USDT',
                    'market_type' => $marketType->value,
                    'tick_size' => '0.01',
                    'lot_size' => '0.001',
                    'min_order_qty' => '0.001',
                    'max_order_qty' => '1000',
                    'min_notional' => '5.0',
                    'is_active' => true,
                    'contract_type' => strtolower($marketType->value),
                ],
                [
                    'symbol' => 'ETHUSDT',
                    'base_asset' => 'ETH',
                    'quote_asset' => 'USDT',
                    'market_type' => $marketType->value,
                    'tick_size' => '0.01',
                    'lot_size' => '0.01',
                    'min_order_qty' => '0.01',
                    'max_order_qty' => '5000',
                    'min_notional' => '5.0',
                    'is_active' => true,
                    'contract_type' => strtolower($marketType->value),
                ],
            ];
        }
    }

    public function getMarketCapabilities(MarketType $marketType): array
    {
        $hasFunding = ! empty($this->config['capabilities']['has_funding'])
            || str_contains(strtolower($marketType->value), 'perpetual');

        return [
            MarketCapability::Candles->value => CapabilityStatus::Supported,
            MarketCapability::OrderBookL2->value => CapabilityStatus::Supported,
            MarketCapability::Ticker->value => CapabilityStatus::Supported,
            MarketCapability::FundingHistory->value => $hasFunding ? CapabilityStatus::Supported : CapabilityStatus::Unsupported,
            MarketCapability::OpenInterest->value => $hasFunding ? CapabilityStatus::Supported : CapabilityStatus::Unsupported,
        ];
    }

    public function supportedMarketTypes(): array
    {
        $types = (array) ($this->config['capabilities']['market_types'] ?? [$this->config['market_type'] ?? 'linear_perpetual']);
        $result = [];
        foreach ($types as $t) {
            try {
                $result[] = MarketType::fromString((string) $t);
            } catch (Throwable) {
                // skip
            }
        }

        return $result !== [] ? $result : [MarketType::Perpetual];
    }

    public function healthCheck(): array
    {
        $res = $this->testConnectivity();

        return [
            'status' => $res['reachable'] ? 'HEALTHY' : 'UNREACHABLE',
            'latency_ms' => (int) ($res['latency_ms'] ?? 0),
            'endpoint' => $this->exchangeBaseUrl,
            'timestamp_utc' => now('UTC')->toISOString(),
            'error' => $res['error'],
        ];
    }

    /**
     * Test live connectivity to the exchange public endpoint.
     *
     * @return array{reachable: bool, latency_ms: float|null, error: string|null}
     */
    public function testConnectivity(): array
    {
        $start = microtime(true);
        try {
            $pingPath = $this->config['endpoints']['ping'] ?? ($this->config['endpoints']['time'] ?? '');
            $this->get($pingPath, []);
            $elapsedMs = round((microtime(true) - $start) * 1000, 2);

            return [
                'reachable' => true,
                'latency_ms' => $elapsedMs,
                'error' => null,
            ];
        } catch (Throwable $e) {
            return [
                'reachable' => false,
                'latency_ms' => null,
                'error' => $e->getMessage(),
            ];
        }
    }
}
