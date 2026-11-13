<?php

declare(strict_types=1);

namespace App\Exchanges\Infrastructure;

use App\Exchanges\Contracts\ExtendedExchangeAdapter;
use App\Exchanges\Domain\CapabilityStatus;
use App\Exchanges\Domain\MarketCapability;
use App\Exchanges\Domain\MarketType;
use App\HistoricalData\UniversalAcquisitionService;
use DateTimeImmutable;
use RuntimeException;
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
        $this->pinValidatedAddress = true;
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
        $endpoint = $this->endpoint('candles', '/api/v1/klines');

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
                    $parsedTime = is_numeric($c[0]) ? (int) $c[0] : strtotime((string) $c[0]);
                    if ($parsedTime === false || $parsedTime <= 0) {
                        continue;
                    }
                    $ts = is_numeric($c[0]) ? $parsedTime : $parsedTime * 1000;
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
                    $rawTime = $c['time'] ?? ($c['open_time'] ?? ($c['timestamp'] ?? null));
                    if ($rawTime === null || $rawTime === '') {
                        continue; // Never fabricate exchange timestamps.
                    }
                    $parsedTime = is_numeric($rawTime) ? (int) $rawTime : strtotime((string) $rawTime);
                    if ($parsedTime === false || $parsedTime <= 0) {
                        continue;
                    }
                    $ts = is_numeric($rawTime) ? $parsedTime : $parsedTime * 1000;
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
        $endpoint = $this->endpoint('funding', '/api/v1/funding-rate');
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
        $endpoint = $this->endpoint('open_interest', '/api/v1/open-interest');
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
        $raw = $this->get($this->endpoint('order_book', '/api/v1/depth'), ['symbol' => $symbol, 'limit' => $depth]);
        $data = $raw['data'] ?? ($raw['result'] ?? $raw);
        if (! is_array($data) || ! isset($data['bids'], $data['asks']) || ! is_array($data['bids']) || ! is_array($data['asks'])) {
            throw new RuntimeException("Invalid order book response for {$this->exchangeId}:{$symbol}.");
        }

        return [
            'exchange_id' => $this->exchangeId,
            'symbol' => $symbol,
            'timestamp_utc' => now('UTC')->toISOString(),
            'bids' => $data['bids'],
            'asks' => $data['asks'],
            'raw_source_hash' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)),
        ];
    }

    public function fetchTicker(string $symbol): array
    {
        $raw = $this->get($this->endpoint('ticker', '/api/v1/ticker'), ['symbol' => $symbol]);
        $item = $raw['data'] ?? ($raw['result'] ?? $raw);
        if (! is_array($item)) {
            throw new RuntimeException("Invalid ticker response for {$this->exchangeId}:{$symbol}.");
        }

        $bid = $item['bid'] ?? ($item['bidPrice'] ?? ($item['best_bid'] ?? null));
        $ask = $item['ask'] ?? ($item['askPrice'] ?? ($item['best_ask'] ?? null));
        if (! is_numeric($bid) || ! is_numeric($ask) || (float) $bid <= 0 || (float) $ask < (float) $bid) {
            throw new RuntimeException("Missing or invalid bid/ask for {$this->exchangeId}:{$symbol}; cannot infer an executable quote.");
        }

        return [
            'symbol' => $symbol,
            'bid' => (string) $bid,
            'ask' => (string) $ask,
            'last_price' => (string) ($item['last'] ?? $item['lastPrice'] ?? $item['price'] ?? ''),
            'mark_price' => isset($item['mark_price']) ? (string) $item['mark_price'] : (isset($item['markPrice']) ? (string) $item['markPrice'] : null),
            'index_price' => isset($item['index_price']) ? (string) $item['index_price'] : (isset($item['indexPrice']) ? (string) $item['indexPrice'] : null),
            'funding_rate' => isset($item['funding_rate']) ? (string) $item['funding_rate'] : (isset($item['fundingRate']) ? (string) $item['fundingRate'] : null),
            'open_interest' => isset($item['open_interest']) ? (string) $item['open_interest'] : (isset($item['openInterest']) ? (string) $item['openInterest'] : null),
            'timestamp_ms' => (int) (microtime(true) * 1000),
        ];
    }

    public function fetchSymbols(): array
    {
        $types = $this->supportedMarketTypes();
        if ($types === []) {
            return [];
        }
        $inst = $this->fetchInstruments($types[0]);

        return array_map(static fn ($row): string => (string) $row['symbol'], $inst);
    }

    public function fetchInstruments(MarketType $marketType): array
    {
        $endpoint = $this->endpoint('instruments', '/api/v1/instruments');
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
                    'base_asset' => (string) ($row['base_asset'] ?? ($row['base_currency'] ?? ($row['base'] ?? ($row['baseCurrency'] ?? '')))),
                    'quote_asset' => (string) ($row['quote_asset'] ?? ($row['quote_currency'] ?? ($row['quote'] ?? ($row['quoteCurrency'] ?? '')))),
                    'market_type' => $marketType->value,
                    'tick_size' => $row['tick_size'] ?? ($row['price_tick'] ?? (isset($this->config['capabilities']['price_precision']) ? '0.'.str_repeat('0', max(0, (int) $this->config['capabilities']['price_precision'] - 1)).'1' : null)),
                    'lot_size' => $row['lot_size'] ?? ($row['qty_step'] ?? (isset($this->config['capabilities']['qty_precision']) ? '0.'.str_repeat('0', max(0, (int) $this->config['capabilities']['qty_precision'] - 1)).'1' : null)),
                    'min_order_qty' => isset($row['min_order_qty']) ? (string) $row['min_order_qty'] : (isset($this->config['capabilities']['min_qty']) ? (string) $this->config['capabilities']['min_qty'] : null),
                    'max_order_qty' => isset($row['max_order_qty']) ? (string) $row['max_order_qty'] : null,
                    'min_notional' => isset($row['min_notional']) ? (string) $row['min_notional'] : (isset($this->config['capabilities']['min_notional']) ? (string) $this->config['capabilities']['min_notional'] : null),
                    'is_active' => (bool) ($row['is_active'] ?? true),
                    'contract_type' => strtolower($marketType->value),
                ];
            }

            return $res;
        } catch (Throwable $error) {
            // An unavailable instrument endpoint must not invent executable markets.
            throw new RuntimeException("Unable to discover actual instruments for {$this->exchangeId}.", 0, $error);
        }
    }

    public function getMarketCapabilities(MarketType $marketType): array
    {
        $caps = (array) ($this->config['capabilities'] ?? []);
        $endpoints = $this->configuredEndpoints();
        $types = $this->supportedMarketTypes();
        $supportsType = in_array($marketType, $types, true);
        $supported = static fn (bool $value): CapabilityStatus => $value
            ? CapabilityStatus::Supported : CapabilityStatus::Unsupported;
        $hasFunding = $supportsType && $marketType->hasFunding() && ! empty($caps['has_funding']);

        return [
            MarketCapability::Candles->value => $supported($supportsType && ! empty($caps['has_candles']) && isset($endpoints['candles'])),
            MarketCapability::OrderBookL2->value => $supported($supportsType && isset($endpoints['order_book'])),
            MarketCapability::Ticker->value => $supported($supportsType && isset($endpoints['ticker'])),
            MarketCapability::FundingHistory->value => $supported($hasFunding && isset($endpoints['funding'])),
            MarketCapability::OpenInterest->value => $supported($supportsType && ! empty($caps['has_open_interest']) && isset($endpoints['open_interest'])),
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

        return array_values(array_unique($result, SORT_REGULAR));
    }

    /** @return array<string, string> */
    private function configuredEndpoints(): array
    {
        $nested = (array) ($this->config['capabilities']['endpoints'] ?? []);
        $direct = (array) ($this->config['endpoints'] ?? []);

        return array_filter(array_merge($nested, $direct), static fn (mixed $value): bool => is_string($value) && trim($value) !== '');
    }

    private function endpoint(string $key, string $default): string
    {
        $path = $this->configuredEndpoints()[$key] ?? $default;
        // A declarative endpoint must remain on its exchange's configured host.
        // Absolute/protocol-relative URLs bypass per-exchange provenance.
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $path) || str_starts_with($path, '//')
            || str_contains($path, "\0") || preg_match('/[\r\n]/', $path)) {
            throw new \InvalidArgumentException("Invalid cross-host endpoint '{$key}' for {$this->exchangeId}.");
        }

        return $path;
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
            $pingPath = $this->endpoint('ping', $this->endpoint('time', ''));
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
