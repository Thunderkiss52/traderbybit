<?php

declare(strict_types=1);

namespace App\Exchanges\KuCoin;

use App\Exchanges\Domain\CapabilityStatus;
use App\Exchanges\Domain\MarketCapability;
use App\Exchanges\Domain\MarketType;
use App\Exchanges\Infrastructure\AbstractExchangeAdapter;
use RuntimeException;

/**
 * KuCoin public market data adapter.
 * Strictly read-only public market data. Zero credentials, zero order placement.
 */
final class KuCoinAdapter extends AbstractExchangeAdapter
{
    protected string $baseUrl = 'https://api-futures.kucoin.com';

    protected string $spotBaseUrl = 'https://api.kucoin.com';

    protected string $futuresBaseUrl = 'https://api-futures.kucoin.com';

    public function __construct(
        string $futuresBaseUrl = 'https://api-futures.kucoin.com',
        string $spotBaseUrl = 'https://api.kucoin.com'
    ) {
        $this->futuresBaseUrl = $futuresBaseUrl;
        $this->spotBaseUrl = $spotBaseUrl;
        $this->baseUrl = $futuresBaseUrl;
    }

    public function id(): string
    {
        return 'kucoin';
    }

    public function name(): string
    {
        return 'KuCoin';
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
        if ($symbol === 'BTCUSDT' || $symbol === 'BTC-USDT') {
            return 'XBTUSDTM';
        }
        if (str_ends_with($symbol, 'USDTM')) {
            return $symbol;
        }
        if (str_contains($symbol, '-')) {
            $parts = explode('-', $symbol);
            $base = $parts[0] === 'BTC' ? 'XBT' : $parts[0];

            return $base.'USDTM';
        }
        if (str_ends_with($symbol, 'USDT') && strlen($symbol) > 4) {
            $base = substr($symbol, 0, -4);
            $base = $base === 'BTC' ? 'XBT' : $base;

            return $base.'USDTM';
        }

        return $symbol;
    }

    public static function normalizeSpotSymbol(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));
        if (str_contains($symbol, '-')) {
            return $symbol;
        }
        if ($symbol === 'XBTUSDTM') {
            return 'BTC-USDT';
        }
        if (str_ends_with($symbol, 'USDTM')) {
            $base = substr($symbol, 0, -5);
            $base = $base === 'XBT' ? 'BTC' : $base;

            return $base.'-USDT';
        }
        if (str_ends_with($symbol, 'USDT') && strlen($symbol) > 4) {
            return substr($symbol, 0, -4).'-USDT';
        }

        return $symbol;
    }

    public static function granularityFromInterval(string $interval): int
    {
        $normalized = strtolower(trim($interval));

        return match ($normalized) {
            '1', '1m', '1min' => 1,
            '5', '5m', '5min' => 5,
            '15', '15m', '15min' => 15,
            '30', '30m', '30min' => 30,
            '60', '1h', '1hour' => 60,
            '120', '2h', '2hour' => 120,
            '240', '4h', '4hour' => 240,
            '480', '8h', '8hour' => 480,
            '1440', '1d', 'd', '1day' => 1440,
            default => 15,
        };
    }

    /**
     * Unwraps KuCoin standard envelope: {"code": "200000", "data": ...}
     */
    private function getKucoin(string $urlOrPath, array $query = []): mixed
    {
        $raw = $this->get($urlOrPath, $query);
        $code = (string) ($raw['code'] ?? '200000');
        if ($code !== '200000' && $code !== '200') {
            $msg = (string) ($raw['msg'] ?? 'Unknown KuCoin error');
            throw new RuntimeException("KuCoin error ({$code}): {$msg}");
        }

        return $raw['data'] ?? $raw;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchCandles(string $symbol, string $interval, int $limit, ?int $startMs = null, ?int $endMs = null): array
    {
        $contract = self::normalizeFuturesSymbol($symbol);
        $granularity = self::granularityFromInterval($interval);

        $query = [
            'symbol' => $contract,
            'granularity' => $granularity,
        ];
        if ($startMs !== null) {
            $query['from'] = $startMs;
        }
        if ($endMs !== null) {
            $query['to'] = $endMs;
        }

        $data = $this->getKucoin("{$this->futuresBaseUrl}/api/v1/kline/query", $query);
        if (! is_array($data)) {
            throw new RuntimeException("Malformed KuCoin candle response for {$symbol}.");
        }

        $results = [];
        foreach ($data as $row) {
            if (! is_array($row) || count($row) < 5) {
                continue;
            }

            // KuCoin futures format: [timeMs, open, high, low, close, volume]
            // KuCoin spot format: [timeSec, open, close, high, low, volume, turnover]
            $ts = (int) $row[0];
            $open = self::decimal($row[1], 'open');
            if (isset($row[5]) && count($row) >= 7) {
                // Spot layout: [time, open, close, high, low, vol, turnover]
                $close = self::decimal($row[2], 'close');
                $high = self::decimal($row[3], 'high');
                $low = self::decimal($row[4], 'low');
                $volume = self::decimal($row[5], 'volume');
                $turnover = self::decimal($row[6], 'turnover');
            } else {
                // Futures layout: [time, open, high, low, close, volume]
                $high = self::decimal($row[2], 'high');
                $low = self::decimal($row[3], 'low');
                $close = self::decimal($row[4], 'close');
                $volume = isset($row[5]) ? self::decimal($row[5], 'volume') : '0';
                $turnover = null;
            }

            $results[] = [
                'exchange_id' => $this->id(),
                'symbol' => $symbol,
                'interval' => $interval,
                'open_time_utc' => self::utcTimestamp($ts),
                'open' => $open,
                'high' => $high,
                'low' => $low,
                'close' => $close,
                'volume' => $volume,
                'turnover' => $turnover,
                'is_closed' => true,
                'raw_source_hash' => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)),
            ];
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
        $contract = self::normalizeFuturesSymbol($symbol);
        $query = [
            'symbol' => $contract,
        ];
        if ($startMs !== null) {
            $query['from'] = $startMs;
        }
        if ($endMs !== null) {
            $query['to'] = $endMs;
        }

        $data = $this->getKucoin("{$this->futuresBaseUrl}/api/v1/funding-history", $query);
        $rows = [];
        if (is_array($data)) {
            $rows = isset($data['dataList']) && is_array($data['dataList']) ? $data['dataList'] : $data;
        }

        $results = [];
        foreach ($rows as $item) {
            if (! is_array($item) || ! isset($item['timePoint'], $item['fundingRate'])) {
                continue;
            }

            $results[] = [
                'exchange_id' => $this->id(),
                'symbol' => $symbol,
                'funding_time_utc' => self::utcTimestamp((int) $item['timePoint']),
                'funding_rate' => self::decimal($item['fundingRate'], 'funding_rate'),
                'mark_price' => isset($item['markPrice']) ? self::decimal($item['markPrice'], 'mark_price') : null,
                'raw_source_hash' => hash('sha256', json_encode($item, JSON_THROW_ON_ERROR)),
            ];
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchOpenInterest(string $symbol, string $interval, ?int $startMs = null, ?int $endMs = null): array
    {
        $contract = self::normalizeFuturesSymbol($symbol);
        $data = $this->getKucoin("{$this->futuresBaseUrl}/api/v1/openInterest/query", [
            'symbol' => $contract,
        ]);

        $results = [];
        if (is_array($data)) {
            if (isset($data['value']) || isset($data['openInterest'])) {
                $time = $data['timePoint'] ?? $data['timestamp'] ?? microtime(true) * 1000;
                $val = $data['value'] ?? $data['openInterest'] ?? '0';
                $results[] = [
                    'exchange_id' => $this->id(),
                    'symbol' => $symbol,
                    'timestamp_utc' => self::utcTimestamp((int) $time),
                    'open_interest' => self::decimal($val, 'open_interest'),
                    'open_interest_value_usdt' => null,
                    'raw_source_hash' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)),
                ];
            } elseif (array_is_list($data)) {
                foreach ($data as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $time = $row['timePoint'] ?? $row['timestamp'] ?? microtime(true) * 1000;
                    $val = $row['value'] ?? $row['openInterest'] ?? '0';
                    $results[] = [
                        'exchange_id' => $this->id(),
                        'symbol' => $symbol,
                        'timestamp_utc' => self::utcTimestamp((int) $time),
                        'open_interest' => self::decimal($val, 'open_interest'),
                        'open_interest_value_usdt' => null,
                        'raw_source_hash' => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)),
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchSymbols(): array
    {
        $data = $this->getKucoin("{$this->futuresBaseUrl}/api/v1/contracts/active");
        if (! is_array($data)) {
            throw new RuntimeException('Malformed KuCoin active contracts response.');
        }

        $results = [];
        foreach ($data as $row) {
            if (! is_array($row) || ! isset($row['symbol'])) {
                continue;
            }

            $symbol = (string) $row['symbol'];
            $base = (string) ($row['baseCurrency'] ?? '');
            $quote = (string) ($row['quoteCurrency'] ?? 'USDT');
            $tickSize = isset($row['tickSize']) ? self::decimal($row['tickSize']) : '0.1';
            $lotSize = isset($row['lotSize']) ? self::decimal($row['lotSize']) : '1';
            $minQty = isset($row['lotSize']) ? self::decimal($row['lotSize']) : '1';
            $maxQty = isset($row['maxOrderQty']) ? self::decimal($row['maxOrderQty']) : null;
            $isActive = ($row['status'] ?? '') === 'Open';

            $results[] = [
                'exchange_id' => $this->id(),
                'symbol' => $symbol,
                'base_asset' => $base,
                'quote_asset' => $quote,
                'tick_size' => $tickSize,
                'lot_size' => $lotSize,
                'min_order_qty' => $minQty,
                'max_order_qty' => $maxQty,
                'min_notional' => '5.0',
                'contract_type' => 'perpetual',
                'is_active' => $isActive,
            ];
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchInstruments(MarketType $marketType): array
    {
        $url = $marketType === MarketType::Spot
            ? "{$this->spotBaseUrl}/api/v1/symbols"
            : "{$this->futuresBaseUrl}/api/v1/contracts/active";

        $data = $this->getKucoin($url);
        if (! is_array($data)) {
            throw new RuntimeException("Malformed KuCoin instruments response for {$marketType->value}.");
        }

        $results = [];
        foreach ($data as $row) {
            if (! is_array($row)) {
                continue;
            }

            if ($marketType === MarketType::Spot) {
                $symbol = (string) ($row['symbol'] ?? '');
                $base = (string) ($row['baseCurrency'] ?? '');
                $quote = (string) ($row['quoteCurrency'] ?? '');
                $tickSize = isset($row['priceIncrement']) ? self::decimal($row['priceIncrement']) : '0.01';
                $lotSize = isset($row['baseIncrement']) ? self::decimal($row['baseIncrement']) : '0.0001';
                $minQty = isset($row['baseMinSize']) ? self::decimal($row['baseMinSize']) : null;
                $maxQty = isset($row['baseMaxSize']) ? self::decimal($row['baseMaxSize']) : null;
                $minNotional = isset($row['minFunds']) ? self::decimal($row['minFunds']) : '1.0';
                $isActive = (bool) ($row['enableTrading'] ?? false);
            } else {
                $symbol = (string) ($row['symbol'] ?? '');
                $base = (string) ($row['baseCurrency'] ?? '');
                $quote = (string) ($row['quoteCurrency'] ?? 'USDT');
                $tickSize = isset($row['tickSize']) ? self::decimal($row['tickSize']) : '0.1';
                $lotSize = isset($row['lotSize']) ? self::decimal($row['lotSize']) : '1';
                $minQty = isset($row['lotSize']) ? self::decimal($row['lotSize']) : '1';
                $maxQty = isset($row['maxOrderQty']) ? self::decimal($row['maxOrderQty']) : null;
                $minNotional = '5.0';
                $isActive = ($row['status'] ?? '') === 'Open';
            }

            if ($symbol === '') {
                continue;
            }

            $results[] = [
                'exchange_id' => $this->id(),
                'market_type' => $marketType->value,
                'symbol' => $symbol,
                'base_asset' => $base,
                'quote_asset' => $quote,
                'tick_size' => $tickSize,
                'lot_size' => $lotSize,
                'min_order_qty' => $minQty,
                'max_order_qty' => $maxQty,
                'min_notional' => $minNotional,
                'is_active' => $isActive,
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
        $contract = self::normalizeFuturesSymbol($symbol);
        $data = $this->getKucoin("{$this->futuresBaseUrl}/api/v1/level2/depth20", [
            'symbol' => $contract,
        ]);

        if (! is_array($data)) {
            throw new RuntimeException("Malformed KuCoin order book for {$symbol}.");
        }

        $bids = [];
        foreach ($data['bids'] ?? [] as $bid) {
            if (is_array($bid) && isset($bid[0], $bid[1])) {
                $bids[] = [self::decimal($bid[0], 'bid_price'), self::decimal($bid[1], 'bid_size')];
            }
        }

        $asks = [];
        foreach ($data['asks'] ?? [] as $ask) {
            if (is_array($ask) && isset($ask[0], $ask[1])) {
                $asks[] = [self::decimal($ask[0], 'ask_price'), self::decimal($ask[1], 'ask_size')];
            }
        }

        $ts = $data['time'] ?? microtime(true) * 1000;

        return [
            'exchange_id' => $this->id(),
            'symbol' => $symbol,
            'timestamp_utc' => self::utcTimestamp($ts),
            'bids' => $bids,
            'asks' => $asks,
            'raw_source_hash' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)),
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
        $contract = self::normalizeFuturesSymbol($symbol);
        $data = $this->getKucoin("{$this->futuresBaseUrl}/api/v1/ticker", [
            'symbol' => $contract,
        ]);

        if (! is_array($data)) {
            throw new RuntimeException("Malformed KuCoin ticker response for {$symbol}.");
        }

        $price = self::decimal($data['price'] ?? null, 'last_price');
        $bid = isset($data['bestBidPrice']) && $data['bestBidPrice'] !== '' ? self::decimal($data['bestBidPrice'], 'bid_price') : null;
        $ask = isset($data['bestAskPrice']) && $data['bestAskPrice'] !== '' ? self::decimal($data['bestAskPrice'], 'ask_price') : null;
        $vol = self::decimal($data['volume'] ?? '0', 'volume');
        $turnover = isset($data['turnover']) ? self::decimal($data['turnover'], 'turnover') : null;

        return [
            'exchange_id' => $this->id(),
            'symbol' => $symbol,
            'last_price' => $price,
            'bid_price' => $bid,
            'ask_price' => $ask,
            'volume_24h' => $vol,
            'turnover_24h' => $turnover,
            'timestamp_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'raw_source_hash' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)),
        ];
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
