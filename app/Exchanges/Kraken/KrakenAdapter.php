<?php

declare(strict_types=1);

namespace App\Exchanges\Kraken;

use App\Exchanges\Domain\CapabilityStatus;
use App\Exchanges\Domain\MarketCapability;
use App\Exchanges\Domain\MarketType;
use App\Exchanges\Infrastructure\AbstractExchangeAdapter;
use RuntimeException;

/**
 * Kraken public market data adapter.
 * Strictly read-only public market data. Zero credentials, zero order placement.
 */
final class KrakenAdapter extends AbstractExchangeAdapter
{
    protected string $baseUrl = 'https://futures.kraken.com/derivatives/api/v3';

    protected string $spotBaseUrl = 'https://api.kraken.com/0/public';

    protected string $futuresBaseUrl = 'https://futures.kraken.com/derivatives/api/v3';

    public function __construct(
        string $futuresBaseUrl = 'https://futures.kraken.com/derivatives/api/v3',
        string $spotBaseUrl = 'https://api.kraken.com/0/public'
    ) {
        $this->futuresBaseUrl = $futuresBaseUrl;
        $this->spotBaseUrl = $spotBaseUrl;
        $this->baseUrl = $futuresBaseUrl;
    }

    public function id(): string
    {
        return 'kraken';
    }

    public function name(): string
    {
        return 'Kraken';
    }

    /**
     * @return list<MarketType>
     */
    public function supportedMarketTypes(): array
    {
        return [
            MarketType::Spot,
            MarketType::Perpetual,
            MarketType::Futures,
        ];
    }

    /**
     * @return array<string, CapabilityStatus>
     */
    public function getMarketCapabilities(MarketType $marketType): array
    {
        return match ($marketType) {
            MarketType::Spot => [
                MarketCapability::Candles->value => CapabilityStatus::Supported,
                MarketCapability::OrderBookL2->value => CapabilityStatus::Supported,
                MarketCapability::Trades->value => CapabilityStatus::Supported,
                MarketCapability::Ticker->value => CapabilityStatus::Supported,
                MarketCapability::MarkPrice->value => CapabilityStatus::Unsupported,
                MarketCapability::FundingHistory->value => CapabilityStatus::Unsupported,
                MarketCapability::OpenInterest->value => CapabilityStatus::Unsupported,
                MarketCapability::Liquidations->value => CapabilityStatus::Unsupported,
                MarketCapability::OptionsChain->value => CapabilityStatus::Unsupported,
            ],
            MarketType::Perpetual => [
                MarketCapability::Candles->value => CapabilityStatus::Supported,
                MarketCapability::OrderBookL2->value => CapabilityStatus::Supported,
                MarketCapability::Trades->value => CapabilityStatus::Supported,
                MarketCapability::Ticker->value => CapabilityStatus::Supported,
                MarketCapability::MarkPrice->value => CapabilityStatus::Supported,
                MarketCapability::FundingHistory->value => CapabilityStatus::Supported,
                MarketCapability::OpenInterest->value => CapabilityStatus::Supported,
                MarketCapability::Liquidations->value => CapabilityStatus::PartiallySupported,
                MarketCapability::OptionsChain->value => CapabilityStatus::Unsupported,
            ],
            MarketType::Futures => [
                MarketCapability::Candles->value => CapabilityStatus::Supported,
                MarketCapability::OrderBookL2->value => CapabilityStatus::Supported,
                MarketCapability::Trades->value => CapabilityStatus::Supported,
                MarketCapability::Ticker->value => CapabilityStatus::Supported,
                MarketCapability::MarkPrice->value => CapabilityStatus::Supported,
                MarketCapability::FundingHistory->value => CapabilityStatus::Unsupported,
                MarketCapability::OpenInterest->value => CapabilityStatus::Supported,
                MarketCapability::Liquidations->value => CapabilityStatus::PartiallySupported,
                MarketCapability::OptionsChain->value => CapabilityStatus::Unsupported,
            ],
            MarketType::Options => [
                MarketCapability::Candles->value => CapabilityStatus::Unsupported,
                MarketCapability::OrderBookL2->value => CapabilityStatus::Unsupported,
                MarketCapability::Trades->value => CapabilityStatus::Unsupported,
                MarketCapability::Ticker->value => CapabilityStatus::Unsupported,
                MarketCapability::MarkPrice->value => CapabilityStatus::Unsupported,
                MarketCapability::FundingHistory->value => CapabilityStatus::Unsupported,
                MarketCapability::OpenInterest->value => CapabilityStatus::Unsupported,
                MarketCapability::Liquidations->value => CapabilityStatus::Unsupported,
                MarketCapability::OptionsChain->value => CapabilityStatus::Unsupported,
            ],
        };
    }

    public static function normalizeFuturesSymbol(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));
        if (str_starts_with($symbol, 'PI_') || str_starts_with($symbol, 'PF_')) {
            return $symbol;
        }
        if ($symbol === 'BTCUSDT' || $symbol === 'XBTUSDT') {
            return 'PI_XBTUSD';
        }
        if ($symbol === 'ETHUSDT') {
            return 'PI_ETHUSD';
        }
        if (str_ends_with($symbol, 'USDT')) {
            $base = substr($symbol, 0, -4);
            $base = $base === 'BTC' ? 'XBT' : $base;

            return 'PI_'.$base.'USD';
        }

        return 'PI_'.$symbol;
    }

    public static function normalizeSpotSymbol(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));
        if (str_starts_with($symbol, 'PI_')) {
            $sub = substr($symbol, 3);
            if (str_ends_with($sub, 'USD')) {
                return substr($sub, 0, -3).'USDT';
            }

            return $sub;
        }
        if (str_starts_with($symbol, 'PF_')) {
            $sub = substr($symbol, 3);
            if (str_ends_with($sub, 'USD')) {
                return substr($sub, 0, -3).'USDT';
            }

            return $sub;
        }
        if ($symbol === 'BTCUSDT') {
            return 'XBTUSDT';
        }

        return $symbol;
    }

    public static function normalizeSpotInterval(string $interval): int
    {
        $normalized = strtolower(trim($interval));

        return match ($normalized) {
            '1', '1m' => 1,
            '5', '5m' => 5,
            '15', '15m' => 15,
            '30', '30m' => 30,
            '60', '1h' => 60,
            '240', '4h' => 240,
            '1440', '1d', 'd' => 1440,
            default => 15,
        };
    }

    public static function normalizeFuturesInterval(string $interval): string
    {
        $normalized = strtolower(trim($interval));

        return match ($normalized) {
            '1', '1m' => '1m',
            '5', '5m' => '5m',
            '15', '15m' => '15m',
            '30', '30m' => '30m',
            '60', '1h' => '1h',
            '240', '4h' => '4h',
            '1440', '1d', 'd' => '1d',
            default => '15m',
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchCandles(string $symbol, string $interval, int $limit, ?int $startMs = null, ?int $endMs = null): array
    {
        $futuresSymbol = self::normalizeFuturesSymbol($symbol);
        $tf = self::normalizeFuturesInterval($interval);

        // Try Kraken Futures trade chart
        $raw = $this->get("{$this->futuresBaseUrl}/charts/v1/trade/{$futuresSymbol}/{$tf}");

        $results = [];

        // Check if Futures response: {"candles": [{"time": 1600000000000, "open": "...", "high": "...", "low": "...", "close": "...", "volume": "..."}, ...]}
        if (isset($raw['candles']) && is_array($raw['candles'])) {
            foreach ($raw['candles'] as $c) {
                if (! is_array($c) || ! isset($c['time'], $c['open'], $c['high'], $c['low'], $c['close'])) {
                    continue;
                }
                $ts = (int) $c['time'];
                $results[] = [
                    'exchange_id' => $this->id(),
                    'symbol' => $symbol,
                    'interval' => $interval,
                    'open_time_utc' => self::utcTimestamp($ts),
                    'open' => self::decimal($c['open'], 'open'),
                    'high' => self::decimal($c['high'], 'high'),
                    'low' => self::decimal($c['low'], 'low'),
                    'close' => self::decimal($c['close'], 'close'),
                    'volume' => self::decimal($c['volume'] ?? '0', 'volume'),
                    'turnover' => null,
                    'is_closed' => true,
                    'raw_source_hash' => hash('sha256', json_encode($c, JSON_THROW_ON_ERROR)),
                ];
            }
        } elseif (isset($raw['result']) && is_array($raw['result'])) {
            // Spot OHLC response: {"error": [], "result": {"XBTUSDT": [[time_sec, open, high, low, close, vwap, vol, count]], "last": 1600000000}}
            foreach ($raw['result'] as $key => $rows) {
                if ($key === 'last' || ! is_array($rows)) {
                    continue;
                }
                foreach ($rows as $row) {
                    if (! is_array($row) || count($row) < 7) {
                        continue;
                    }
                    $ts = (int) $row[0];
                    $results[] = [
                        'exchange_id' => $this->id(),
                        'symbol' => $symbol,
                        'interval' => $interval,
                        'open_time_utc' => self::utcTimestamp($ts),
                        'open' => self::decimal($row[1], 'open'),
                        'high' => self::decimal($row[2], 'high'),
                        'low' => self::decimal($row[3], 'low'),
                        'close' => self::decimal($row[4], 'close'),
                        'volume' => self::decimal($row[6], 'volume'),
                        'turnover' => null,
                        'is_closed' => true,
                        'raw_source_hash' => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)),
                    ];
                }
            }
        }

        if ($limit > 0 && count($results) > $limit) {
            return array_slice($results, -$limit);
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchFundingHistory(string $symbol, ?int $startMs = null, ?int $endMs = null): array
    {
        $futuresSymbol = self::normalizeFuturesSymbol($symbol);
        $raw = $this->get("{$this->futuresBaseUrl}/historicalfundingrates", [
            'symbol' => $futuresSymbol,
        ]);

        $rates = [];
        if (isset($raw['rates']) && is_array($raw['rates'])) {
            $rates = $raw['rates'];
        } elseif (isset($raw['data']) && is_array($raw['data'])) {
            $rates = $raw['data'];
        } elseif (array_is_list($raw)) {
            $rates = $raw;
        }

        $results = [];
        foreach ($rates as $row) {
            if (! is_array($row) || ! isset($row['fundingRate'])) {
                continue;
            }
            $timeStr = (string) ($row['timestamp'] ?? gmdate('Y-m-d\TH:i:s\Z'));
            $ts = strtotime($timeStr) ?: (int) ($row['time'] ?? time());

            $results[] = [
                'exchange_id' => $this->id(),
                'symbol' => $symbol,
                'funding_time_utc' => self::utcTimestamp($ts),
                'funding_rate' => self::decimal($row['fundingRate'], 'funding_rate'),
                'mark_price' => null,
                'raw_source_hash' => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)),
            ];
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchOpenInterest(string $symbol, string $interval, ?int $startMs = null, ?int $endMs = null): array
    {
        $futuresSymbol = self::normalizeFuturesSymbol($symbol);
        $raw = $this->get("{$this->futuresBaseUrl}/tickers");

        $tickers = [];
        if (isset($raw['tickers']) && is_array($raw['tickers'])) {
            $tickers = $raw['tickers'];
        } elseif (array_is_list($raw)) {
            $tickers = $raw;
        }

        $results = [];
        foreach ($tickers as $t) {
            if (! is_array($t)) {
                continue;
            }
            $tSymbol = (string) ($t['symbol'] ?? '');
            if ($tSymbol === $futuresSymbol || $tSymbol === $symbol) {
                $oi = self::decimal($t['openInterest'] ?? '0', 'open_interest');
                $lastTime = (string) ($t['lastTime'] ?? gmdate('Y-m-d\TH:i:s\Z'));
                $ts = strtotime($lastTime) ?: time();

                $results[] = [
                    'exchange_id' => $this->id(),
                    'symbol' => $symbol,
                    'timestamp_utc' => self::utcTimestamp($ts),
                    'open_interest' => $oi,
                    'open_interest_value_usdt' => null,
                    'raw_source_hash' => hash('sha256', json_encode($t, JSON_THROW_ON_ERROR)),
                ];
                break;
            }
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchSymbols(): array
    {
        $raw = $this->get("{$this->futuresBaseUrl}/instruments");
        $instruments = [];
        if (isset($raw['instruments']) && is_array($raw['instruments'])) {
            $instruments = $raw['instruments'];
        } elseif (array_is_list($raw)) {
            $instruments = $raw;
        }

        $results = [];
        foreach ($instruments as $row) {
            if (! is_array($row) || ! isset($row['symbol'])) {
                continue;
            }

            $symbol = (string) $row['symbol'];
            $results[] = [
                'exchange_id' => $this->id(),
                'symbol' => $symbol,
                'base_asset' => (string) ($row['underlying'] ?? 'XBT'),
                'quote_asset' => 'USD',
                'tick_size' => isset($row['tickSize']) ? self::decimal($row['tickSize']) : '0.5',
                'lot_size' => isset($row['contractSize']) ? self::decimal($row['contractSize']) : '1',
                'min_order_qty' => '1',
                'max_order_qty' => null,
                'min_notional' => '10.0',
                'contract_type' => 'perpetual',
                'is_active' => (bool) ($row['tradeable'] ?? true),
            ];
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchInstruments(MarketType $marketType): array
    {
        if ($marketType === MarketType::Spot) {
            $raw = $this->get("{$this->spotBaseUrl}/AssetPairs");
            $pairs = $raw['result'] ?? [];
            if (! is_array($pairs)) {
                throw new RuntimeException('Malformed Kraken spot AssetPairs response.');
            }

            $results = [];
            foreach ($pairs as $key => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $altname = (string) ($row['altname'] ?? $row['wsname'] ?? $key);
                $results[] = [
                    'exchange_id' => $this->id(),
                    'market_type' => $marketType->value,
                    'symbol' => $altname,
                    'base_asset' => (string) ($row['base'] ?? ''),
                    'quote_asset' => (string) ($row['quote'] ?? ''),
                    'tick_size' => isset($row['pair_decimals']) ? (string) (10 ** -((int) $row['pair_decimals'])) : '0.1',
                    'lot_size' => isset($row['lot_decimals']) ? (string) (10 ** -((int) $row['lot_decimals'])) : '0.0001',
                    'min_order_qty' => isset($row['ordermin']) ? self::decimal($row['ordermin']) : null,
                    'max_order_qty' => null,
                    'min_notional' => '10.0',
                    'is_active' => ($row['status'] ?? 'online') === 'online',
                ];
            }

            return $results;
        }

        // Perpetual or Futures
        $raw = $this->get("{$this->futuresBaseUrl}/instruments");
        $instruments = $raw['instruments'] ?? (array_is_list($raw) ? $raw : []);

        $results = [];
        foreach ($instruments as $row) {
            if (! is_array($row) || ! isset($row['symbol'])) {
                continue;
            }
            $symbol = (string) $row['symbol'];
            $results[] = [
                'exchange_id' => $this->id(),
                'market_type' => $marketType->value,
                'symbol' => $symbol,
                'base_asset' => (string) ($row['underlying'] ?? 'XBT'),
                'quote_asset' => 'USD',
                'tick_size' => isset($row['tickSize']) ? self::decimal($row['tickSize']) : '0.5',
                'lot_size' => isset($row['contractSize']) ? self::decimal($row['contractSize']) : '1',
                'min_order_qty' => '1',
                'max_order_qty' => null,
                'min_notional' => '10.0',
                'is_active' => (bool) ($row['tradeable'] ?? true),
            ];
        }

        return $results;
    }

    /**
     * @return array{
     *     exchange_id: string,
     *     symbol: string,
     *     timestamp_utc: string,
     *     bids: list<array{0: string, 1: string}>,
     *     asks: list<array{0: string, 1: string}>,
     *     raw_source_hash: string
     * }
     */
    public function fetchOrderBook(string $symbol, int $depth = 20): array
    {
        $futuresSymbol = self::normalizeFuturesSymbol($symbol);
        $raw = $this->get("{$this->futuresBaseUrl}/orderbook", [
            'symbol' => $futuresSymbol,
        ]);

        $bids = [];
        $asks = [];

        // Kraken futures response: {"result": "success", "orderBook": {"bids": [[price, size]], "asks": [[price, size]]}}
        $book = $raw['orderBook'] ?? $raw;
        if (isset($book['bids']) && is_array($book['bids'])) {
            foreach ($book['bids'] as $bid) {
                if (is_array($bid) && isset($bid[0], $bid[1])) {
                    $bids[] = [self::decimal($bid[0], 'bid_price'), self::decimal($bid[1], 'bid_size')];
                }
            }
        }
        if (isset($book['asks']) && is_array($book['asks'])) {
            foreach ($book['asks'] as $ask) {
                if (is_array($ask) && isset($ask[0], $ask[1])) {
                    $asks[] = [self::decimal($ask[0], 'ask_price'), self::decimal($ask[1], 'ask_size')];
                }
            }
        }

        // Spot format fallback: {"error": [], "result": {"XBTUSDT": {"bids": [[price, vol, ts]], "asks": ...}}}
        if (empty($bids) && empty($asks) && isset($raw['result']) && is_array($raw['result'])) {
            foreach ($raw['result'] as $pairKey => $pairData) {
                if (is_array($pairData) && isset($pairData['bids'], $pairData['asks'])) {
                    foreach ($pairData['bids'] as $bid) {
                        if (is_array($bid) && isset($bid[0], $bid[1])) {
                            $bids[] = [self::decimal($bid[0], 'bid_price'), self::decimal($bid[1], 'bid_size')];
                        }
                    }
                    foreach ($pairData['asks'] as $ask) {
                        if (is_array($ask) && isset($ask[0], $ask[1])) {
                            $asks[] = [self::decimal($ask[0], 'ask_price'), self::decimal($ask[1], 'ask_size')];
                        }
                    }
                    break;
                }
            }
        }

        $timeStr = (string) ($raw['serverTime'] ?? gmdate('Y-m-d\TH:i:s\Z'));
        $ts = strtotime($timeStr) ?: time();

        return [
            'exchange_id' => $this->id(),
            'symbol' => $symbol,
            'timestamp_utc' => self::utcTimestamp($ts),
            'bids' => $bids,
            'asks' => $asks,
            'raw_source_hash' => hash('sha256', json_encode($raw, JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * @return array{
     *     exchange_id: string,
     *     symbol: string,
     *     last_price: string,
     *     bid_price: ?string,
     *     ask_price: ?string,
     *     volume_24h: string,
     *     turnover_24h: ?string,
     *     timestamp_utc: string,
     *     raw_source_hash: string
     * }
     */
    public function fetchTicker(string $symbol): array
    {
        $futuresSymbol = self::normalizeFuturesSymbol($symbol);
        $raw = $this->get("{$this->futuresBaseUrl}/tickers");

        $tickers = $raw['tickers'] ?? (array_is_list($raw) ? $raw : []);

        foreach ($tickers as $t) {
            if (! is_array($t)) {
                continue;
            }
            $tSymbol = (string) ($t['symbol'] ?? '');
            if ($tSymbol === $futuresSymbol || $tSymbol === $symbol) {
                $last = self::decimal($t['last'] ?? null, 'last_price');
                $bid = isset($t['bid']) && $t['bid'] !== '' ? self::decimal($t['bid'], 'bid_price') : null;
                $ask = isset($t['ask']) && $t['ask'] !== '' ? self::decimal($t['ask'], 'ask_price') : null;
                $vol = self::decimal($t['vol24h'] ?? '0', 'volume_24h');

                return [
                    'exchange_id' => $this->id(),
                    'symbol' => $symbol,
                    'last_price' => $last,
                    'bid_price' => $bid,
                    'ask_price' => $ask,
                    'volume_24h' => $vol,
                    'turnover_24h' => null,
                    'timestamp_utc' => gmdate('Y-m-d\TH:i:s\Z'),
                    'raw_source_hash' => hash('sha256', json_encode($t, JSON_THROW_ON_ERROR)),
                ];
            }
        }

        // Spot format fallback: {"error": [], "result": {"XBTUSDT": {"a": [ask, ...], "b": [bid, ...], "c": [last, ...], "v": [today, 24h]}}}
        if (isset($raw['result']) && is_array($raw['result'])) {
            foreach ($raw['result'] as $pairKey => $data) {
                if (is_array($data) && isset($data['c'][0])) {
                    $last = self::decimal($data['c'][0], 'last_price');
                    $bid = isset($data['b'][0]) ? self::decimal($data['b'][0], 'bid_price') : null;
                    $ask = isset($data['a'][0]) ? self::decimal($data['a'][0], 'ask_price') : null;
                    $vol = isset($data['v'][1]) ? self::decimal($data['v'][1], 'volume_24h') : '0';

                    return [
                        'exchange_id' => $this->id(),
                        'symbol' => $symbol,
                        'last_price' => $last,
                        'bid_price' => $bid,
                        'ask_price' => $ask,
                        'volume_24h' => $vol,
                        'turnover_24h' => null,
                        'timestamp_utc' => gmdate('Y-m-d\TH:i:s\Z'),
                        'raw_source_hash' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)),
                    ];
                }
            }
        }

        throw new RuntimeException("Kraken ticker not found for {$symbol}.");
    }

    /**
     * Bounded HTTP GET supporting both relative paths and absolute URLs.
     */
    protected function get(string $path, array $query = [], array $headers = []): array
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $parsed = parse_url($path);
            $base = ($parsed['scheme'] ?? 'https').'://'.($parsed['host'] ?? '').(isset($parsed['port']) ? ':'.$parsed['port'] : '');
            $subPath = $parsed['path'] ?? '/';
            $savedBase = $this->baseUrl;
            $this->baseUrl = $base;
            try {
                return parent::get($subPath, $query, $headers);
            } finally {
                $this->baseUrl = $savedBase;
            }
        }

        return parent::get($path, $query, $headers);
    }
}
