<?php

declare(strict_types=1);

namespace App\Exchanges\Gate;

use App\Exchanges\Domain\CapabilityStatus;
use App\Exchanges\Domain\MarketCapability;
use App\Exchanges\Domain\MarketType;
use App\Exchanges\Infrastructure\AbstractExchangeAdapter;
use RuntimeException;

/**
 * Gate.io public market data adapter.
 * Strictly read-only public market data. Zero trade credentials, zero order placement.
 */
final class GateAdapter extends AbstractExchangeAdapter
{
    protected string $baseUrl = 'https://api.gateio.ws/api/v4';

    public function __construct(string $baseUrl = 'https://api.gateio.ws/api/v4')
    {
        $this->baseUrl = $baseUrl;
    }

    public function id(): string
    {
        return 'gate';
    }

    public function name(): string
    {
        return 'Gate.io';
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
            MarketType::Options,
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
                MarketCapability::Candles->value => CapabilityStatus::PartiallySupported,
                MarketCapability::OrderBookL2->value => CapabilityStatus::Supported,
                MarketCapability::Trades->value => CapabilityStatus::Supported,
                MarketCapability::Ticker->value => CapabilityStatus::Supported,
                MarketCapability::MarkPrice->value => CapabilityStatus::Supported,
                MarketCapability::FundingHistory->value => CapabilityStatus::Unsupported,
                MarketCapability::OpenInterest->value => CapabilityStatus::Supported,
                MarketCapability::Liquidations->value => CapabilityStatus::Unsupported,
                MarketCapability::OptionsChain->value => CapabilityStatus::Supported,
            ],
        };
    }

    public static function normalizeSymbol(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));
        if (str_contains($symbol, '_')) {
            return $symbol;
        }
        if (str_contains($symbol, '-')) {
            return str_replace('-', '_', $symbol);
        }
        if (str_ends_with($symbol, 'USDT') && strlen($symbol) > 4) {
            return substr($symbol, 0, -4).'_USDT';
        }
        if (str_ends_with($symbol, 'USDC') && strlen($symbol) > 4) {
            return substr($symbol, 0, -4).'_USDC';
        }

        return $symbol;
    }

    public static function normalizeInterval(string $interval): string
    {
        $normalized = strtolower(trim($interval));

        return match ($normalized) {
            '1', '1m' => '1m',
            '5', '5m' => '5m',
            '15', '15m' => '15m',
            '30', '30m' => '30m',
            '60', '1h' => '1h',
            '240', '4h' => '4h',
            '480', '8h' => '8h',
            '1440', '1d', 'd' => '1d',
            '7d', 'w', '1w' => '7d',
            default => $normalized,
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchCandles(string $symbol, string $interval, int $limit, ?int $startMs = null, ?int $endMs = null): array
    {
        $contract = self::normalizeSymbol($symbol);
        $tf = self::normalizeInterval($interval);
        $limit = min(max(1, $limit), 1000);

        $query = [
            'contract' => $contract,
            'interval' => $tf,
            'limit' => $limit,
        ];
        if ($startMs !== null) {
            $query['from'] = (int) ($startMs / 1000);
        }
        if ($endMs !== null) {
            $query['to'] = (int) ($endMs / 1000);
        }

        $rows = $this->get('/futures/usdt/candlesticks', $query);
        if (! is_array($rows)) {
            throw new RuntimeException("Malformed Gate.io candle response for {$symbol}.");
        }

        $results = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            if (isset($row['t'])) {
                // Futures format: {"t": 1600000000, "v": 1234, "c": "60000", "h": "60100", "l": "59900", "o": "59950", "sum": "74040000"}
                $timestamp = (int) $row['t'];
                $open = self::decimal($row['o'] ?? null, 'open');
                $high = self::decimal($row['h'] ?? null, 'high');
                $low = self::decimal($row['l'] ?? null, 'low');
                $close = self::decimal($row['c'] ?? null, 'close');
                $volume = self::decimal($row['v'] ?? '0', 'volume');
                $turnover = isset($row['sum']) ? self::decimal($row['sum'], 'turnover') : null;
            } elseif (isset($row[0])) {
                // Spot array format: [time, quote_vol, close, high, low, open, vol]
                $timestamp = (int) $row[0];
                $open = self::decimal($row[5] ?? null, 'open');
                $high = self::decimal($row[3] ?? null, 'high');
                $low = self::decimal($row[4] ?? null, 'low');
                $close = self::decimal($row[2] ?? null, 'close');
                $volume = self::decimal($row[6] ?? '0', 'volume');
                $turnover = isset($row[1]) ? self::decimal($row[1], 'turnover') : null;
            } else {
                continue;
            }

            $results[] = [
                'exchange_id' => $this->id(),
                'symbol' => $symbol,
                'interval' => $interval,
                'open_time_utc' => self::utcTimestamp($timestamp),
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

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchFundingHistory(string $symbol, ?int $startMs = null, ?int $endMs = null): array
    {
        $contract = self::normalizeSymbol($symbol);
        $query = [
            'contract' => $contract,
            'limit' => 100,
        ];
        if ($startMs !== null) {
            $query['from'] = (int) ($startMs / 1000);
        }
        if ($endMs !== null) {
            $query['to'] = (int) ($endMs / 1000);
        }

        $rows = $this->get('/futures/usdt/funding_rate', $query);
        if (! is_array($rows)) {
            throw new RuntimeException("Malformed Gate.io funding history response for {$symbol}.");
        }

        $results = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['t'], $row['r'])) {
                continue;
            }
            $results[] = [
                'exchange_id' => $this->id(),
                'symbol' => $symbol,
                'funding_time_utc' => self::utcTimestamp((int) $row['t']),
                'funding_rate' => self::decimal($row['r'], 'funding_rate'),
                'mark_price' => isset($row['mark_price']) ? self::decimal($row['mark_price'], 'mark_price') : null,
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
        $contract = self::normalizeSymbol($symbol);
        $tf = self::normalizeInterval($interval);
        $query = [
            'contract' => $contract,
            'interval' => $tf,
            'limit' => 30,
        ];
        if ($startMs !== null) {
            $query['from'] = (int) ($startMs / 1000);
        }
        if ($endMs !== null) {
            $query['to'] = (int) ($endMs / 1000);
        }

        $rows = $this->get('/futures/usdt/contract_stats', $query);
        if (! is_array($rows)) {
            throw new RuntimeException("Malformed Gate.io open interest response for {$symbol}.");
        }

        $results = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $time = $row['time'] ?? $row['t'] ?? null;
            if ($time === null) {
                continue;
            }
            $oi = $row['open_interest'] ?? $row['oi'] ?? '0';
            $oiUsd = $row['open_interest_usd'] ?? $row['oi_usd'] ?? null;

            $results[] = [
                'exchange_id' => $this->id(),
                'symbol' => $symbol,
                'timestamp_utc' => self::utcTimestamp((int) $time),
                'open_interest' => self::decimal($oi, 'open_interest'),
                'open_interest_value_usdt' => $oiUsd !== null ? self::decimal($oiUsd, 'open_interest_usd') : null,
                'raw_source_hash' => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)),
            ];
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchSymbols(): array
    {
        $rows = $this->get('/futures/usdt/contracts');
        if (! is_array($rows)) {
            throw new RuntimeException('Malformed Gate.io contract list response.');
        }

        $results = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['name'])) {
                continue;
            }
            $parts = explode('_', (string) $row['name']);
            $base = $parts[0] ?? '';
            $quote = $parts[1] ?? 'USDT';

            $results[] = [
                'exchange_id' => $this->id(),
                'symbol' => (string) $row['name'],
                'base_asset' => $base,
                'quote_asset' => $quote,
                'tick_size' => self::decimal($row['order_price_round'] ?? '0.1', 'tick_size'),
                'lot_size' => self::decimal($row['order_size_min'] ?? '1', 'lot_size'),
                'min_order_qty' => isset($row['order_size_min']) ? self::decimal($row['order_size_min']) : null,
                'max_order_qty' => isset($row['order_size_max']) ? self::decimal($row['order_size_max']) : null,
                'min_notional' => '5.0',
                'contract_type' => 'perpetual',
                'is_active' => ! ($row['in_delisting'] ?? false),
            ];
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchInstruments(MarketType $marketType): array
    {
        $endpoint = match ($marketType) {
            MarketType::Spot => '/spot/currency_pairs',
            MarketType::Perpetual => '/futures/usdt/contracts',
            MarketType::Futures => '/delivery/usdt/contracts',
            MarketType::Options => '/options/contracts',
        };

        $rows = $this->get($endpoint);
        if (! is_array($rows)) {
            throw new RuntimeException("Malformed Gate.io instruments response for {$marketType->value}.");
        }

        $results = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            if ($marketType === MarketType::Spot) {
                $symbol = (string) ($row['id'] ?? '');
                $base = (string) ($row['base'] ?? '');
                $quote = (string) ($row['quote'] ?? '');
                $tickSize = isset($row['precision']) ? (string) (10 ** -((int) $row['precision'])) : '0.01';
                $lotSize = isset($row['amount_precision']) ? (string) (10 ** -((int) $row['amount_precision'])) : '0.0001';
                $minQty = isset($row['min_base_amount']) ? self::decimal($row['min_base_amount']) : null;
                $maxQty = isset($row['max_base_amount']) ? self::decimal($row['max_base_amount']) : null;
                $minNotional = isset($row['min_quote_amount']) ? self::decimal($row['min_quote_amount']) : '1.0';
                $isActive = ($row['trade_status'] ?? '') === 'tradable';
            } else {
                $symbol = (string) ($row['name'] ?? '');
                $parts = explode('_', $symbol);
                $base = $parts[0] ?? '';
                $quote = $parts[1] ?? 'USDT';
                $tickSize = isset($row['order_price_round']) ? self::decimal($row['order_price_round']) : '0.1';
                $lotSize = isset($row['order_size_min']) ? self::decimal($row['order_size_min']) : '1';
                $minQty = isset($row['order_size_min']) ? self::decimal($row['order_size_min']) : '1';
                $maxQty = isset($row['order_size_max']) ? self::decimal($row['order_size_max']) : null;
                $minNotional = '5.0';
                $isActive = ! ($row['in_delisting'] ?? false);
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
        $contract = self::normalizeSymbol($symbol);
        $depth = min(max(1, $depth), 100);

        $raw = $this->get('/futures/usdt/order_book', [
            'contract' => $contract,
            'limit' => $depth,
        ]);
        if (! is_array($raw)) {
            throw new RuntimeException("Malformed Gate.io order book response for {$symbol}.");
        }

        $bids = [];
        foreach ($raw['bids'] ?? [] as $bid) {
            if (is_array($bid)) {
                $price = $bid['p'] ?? $bid[0] ?? null;
                $size = $bid['s'] ?? $bid[1] ?? null;
                if ($price !== null && $size !== null) {
                    $bids[] = [self::decimal($price, 'bid_price'), self::decimal($size, 'bid_size')];
                }
            }
        }

        $asks = [];
        foreach ($raw['asks'] ?? [] as $ask) {
            if (is_array($ask)) {
                $price = $ask['p'] ?? $ask[0] ?? null;
                $size = $ask['s'] ?? $ask[1] ?? null;
                if ($price !== null && $size !== null) {
                    $asks[] = [self::decimal($price, 'ask_price'), self::decimal($size, 'ask_size')];
                }
            }
        }

        $timestamp = $raw['current'] ?? $raw['update'] ?? microtime(true);

        return [
            'exchange_id' => $this->id(),
            'symbol' => $symbol,
            'timestamp_utc' => self::utcTimestamp($timestamp),
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
        $contract = self::normalizeSymbol($symbol);
        $rows = $this->get('/futures/usdt/tickers', [
            'contract' => $contract,
        ]);

        if (! is_array($rows) || empty($rows)) {
            throw new RuntimeException("Empty or malformed Gate.io ticker response for {$symbol}.");
        }

        $t = $rows[0] ?? $rows;
        if (! is_array($t)) {
            throw new RuntimeException("Invalid Gate.io ticker item for {$symbol}.");
        }

        $last = self::decimal($t['last'] ?? null, 'last_price');
        $bid = isset($t['highest_bid']) && $t['highest_bid'] !== '' ? self::decimal($t['highest_bid'], 'bid_price') : null;
        $ask = isset($t['lowest_ask']) && $t['lowest_ask'] !== '' ? self::decimal($t['lowest_ask'], 'ask_price') : null;
        $volume = self::decimal($t['volume_24h'] ?? $t['base_volume'] ?? '0', 'volume_24h');
        $turnover = isset($t['volume_24h_quote']) ? self::decimal($t['volume_24h_quote'], 'turnover_24h') : (isset($t['quote_volume']) ? self::decimal($t['quote_volume'], 'turnover_24h') : null);

        return [
            'exchange_id' => $this->id(),
            'symbol' => $symbol,
            'last_price' => $last,
            'bid_price' => $bid,
            'ask_price' => $ask,
            'volume_24h' => $volume,
            'turnover_24h' => $turnover,
            'timestamp_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'raw_source_hash' => hash('sha256', json_encode($t, JSON_THROW_ON_ERROR)),
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
