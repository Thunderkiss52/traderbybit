<?php

declare(strict_types=1);

namespace App\Exchanges\Coinbase;

use App\Exchanges\Domain\CapabilityStatus;
use App\Exchanges\Domain\MarketCapability;
use App\Exchanges\Domain\MarketType;
use App\Exchanges\Infrastructure\AbstractExchangeAdapter;
use InvalidArgumentException;

/**
 * Coinbase Exchange public REST market data adapter.
 * Strictly public read-only endpoints. Spot market only. Zero order placement or credentials.
 */
final class CoinbaseAdapter extends AbstractExchangeAdapter
{
    protected string $baseUrl = 'https://api.exchange.coinbase.com';

    public function __construct(?string $baseUrl = null)
    {
        if ($baseUrl !== null) {
            $this->baseUrl = rtrim($baseUrl, '/');
        }
    }

    public function id(): string
    {
        return 'coinbase';
    }

    public function name(): string
    {
        return 'Coinbase Exchange';
    }

    /**
     * @return list<MarketType>
     */
    public function supportedMarketTypes(): array
    {
        return [MarketType::Spot];
    }

    /**
     * @return array<string, CapabilityStatus>
     */
    public function getMarketCapabilities(MarketType $marketType): array
    {
        if ($marketType !== MarketType::Spot) {
            return array_fill_keys(
                array_map(static fn (MarketCapability $c): string => $c->value, MarketCapability::cases()),
                CapabilityStatus::Unsupported
            );
        }

        return [
            MarketCapability::Candles->value => CapabilityStatus::Supported,
            MarketCapability::OrderBookL2->value => CapabilityStatus::Supported,
            MarketCapability::Trades->value => CapabilityStatus::Supported,
            MarketCapability::Ticker->value => CapabilityStatus::Supported,
            MarketCapability::MarkPrice->value => CapabilityStatus::Unsupported,
            MarketCapability::FundingHistory->value => CapabilityStatus::Unsupported,
            MarketCapability::OpenInterest->value => CapabilityStatus::Unsupported,
            MarketCapability::Liquidations->value => CapabilityStatus::Unsupported,
            MarketCapability::OptionsChain->value => CapabilityStatus::Unsupported,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchInstruments(MarketType $marketType): array
    {
        if ($marketType !== MarketType::Spot) {
            return [];
        }

        $raw = $this->get('/products');
        if (! is_array($raw)) {
            return [];
        }

        $instruments = [];
        foreach ($raw as $product) {
            if (! is_array($product) || ! isset($product['id'])) {
                continue;
            }

            $instruments[] = [
                'exchange_id' => $this->id(),
                'symbol' => (string) $product['id'],
                'base_asset' => (string) ($product['base_currency'] ?? ''),
                'quote_asset' => (string) ($product['quote_currency'] ?? ''),
                'market_type' => MarketType::Spot->value,
                'tick_size' => self::decimal($product['quote_increment'] ?? '0.01', 'quote_increment'),
                'lot_size' => self::decimal($product['base_increment'] ?? '0.00000001', 'base_increment'),
                'min_order_qty' => isset($product['base_min_size']) ? self::decimal($product['base_min_size'], 'base_min_size') : null,
                'max_order_qty' => isset($product['base_max_size']) ? self::decimal($product['base_max_size'], 'base_max_size') : null,
                'min_notional' => isset($product['min_market_funds']) ? self::decimal($product['min_market_funds'], 'min_market_funds') : null,
                'is_active' => ($product['status'] ?? '') === 'online' && ! ($product['trading_disabled'] ?? false),
                'contract_type' => 'spot',
                'raw_source_hash' => hash('sha256', json_encode($product, JSON_THROW_ON_ERROR)),
            ];
        }

        return $instruments;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchSymbols(): array
    {
        return $this->fetchInstruments(MarketType::Spot);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchCandles(string $symbol, string $interval, int $limit, ?int $startMs = null, ?int $endMs = null): array
    {
        if ($limit < 1 || $limit > 300) {
            throw new InvalidArgumentException("Limit must be between 1 and 300 for {$this->id()}.");
        }

        $granularity = match ($interval) {
            '1m', '1' => 60,
            '5m', '5' => 300,
            '15m', '15' => 900,
            '1h', '60m', '60' => 3600,
            '6h', '360m', '360' => 21600,
            '1d', 'D', '1440m' => 86400,
            default => throw new InvalidArgumentException("Unsupported candle interval '{$interval}' for {$this->id()}."),
        };

        $product = $this->formatSymbol($symbol);
        $query = ['granularity' => $granularity];

        if ($startMs !== null) {
            $query['start'] = gmdate('Y-m-d\TH:i:s\Z', (int) ($startMs / 1000));
        }
        if ($endMs !== null) {
            $query['end'] = gmdate('Y-m-d\TH:i:s\Z', (int) ($endMs / 1000));
        }

        $raw = $this->get("/products/{$product}/candles", $query);
        if (! is_array($raw)) {
            return [];
        }

        // Coinbase returns newest first: [time, low, high, open, close, volume]
        $rows = array_reverse($raw);
        if (count($rows) > $limit) {
            $rows = array_slice($rows, -$limit);
        }

        $candles = [];
        foreach ($rows as $row) {
            if (! is_array($row) || count($row) < 6) {
                continue;
            }

            $openSec = (int) $row[0];
            $closeSec = $openSec + $granularity - 1;

            $candles[] = [
                'exchange_id' => $this->id(),
                'symbol' => $symbol,
                'interval' => $interval,
                'open_time_utc' => self::utcTimestamp($openSec),
                'close_time_utc' => self::utcTimestamp($closeSec),
                'open' => self::decimal($row[3], 'open'),
                'high' => self::decimal($row[2], 'high'),
                'low' => self::decimal($row[1], 'low'),
                'close' => self::decimal($row[4], 'close'),
                'volume' => self::decimal($row[5], 'volume'),
                'turnover' => null,
                'is_closed' => true,
                'raw_source_hash' => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)),
            ];
        }

        return $candles;
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
        $product = $this->formatSymbol($symbol);
        $raw = $this->get("/products/{$product}/book", ['level' => 2]);

        $bids = [];
        foreach (array_slice($raw['bids'] ?? [], 0, $depth) as $bid) {
            if (is_array($bid) && isset($bid[0], $bid[1])) {
                $bids[] = [self::decimal($bid[0], 'bid_price'), self::decimal($bid[1], 'bid_size')];
            }
        }

        $asks = [];
        foreach (array_slice($raw['asks'] ?? [], 0, $depth) as $ask) {
            if (is_array($ask) && isset($ask[0], $ask[1])) {
                $asks[] = [self::decimal($ask[0], 'ask_price'), self::decimal($ask[1], 'ask_size')];
            }
        }

        $timestampUtc = isset($raw['time'])
            ? self::utcTimestamp(strtotime((string) $raw['time']))
            : gmdate('Y-m-d\TH:i:s\Z');

        return [
            'exchange_id' => $this->id(),
            'symbol' => $symbol,
            'timestamp_utc' => $timestampUtc,
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
        $product = $this->formatSymbol($symbol);
        $raw = $this->get("/products/{$product}/ticker");

        $timestampUtc = isset($raw['time'])
            ? self::utcTimestamp(strtotime((string) $raw['time']))
            : gmdate('Y-m-d\TH:i:s\Z');

        return [
            'exchange_id' => $this->id(),
            'symbol' => $symbol,
            'last_price' => self::decimal($raw['price'] ?? null, 'price'),
            'bid_price' => isset($raw['bid']) && $raw['bid'] !== '' ? self::decimal($raw['bid'], 'bid') : null,
            'ask_price' => isset($raw['ask']) && $raw['ask'] !== '' ? self::decimal($raw['ask'], 'ask') : null,
            'volume_24h' => self::decimal($raw['volume'] ?? '0', 'volume'),
            'turnover_24h' => null,
            'timestamp_utc' => $timestampUtc,
            'raw_source_hash' => hash('sha256', json_encode($raw, JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * Funding history is unsupported on Spot exchange.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchFundingHistory(string $symbol, ?int $startMs = null, ?int $endMs = null): array
    {
        return [];
    }

    /**
     * Open interest is unsupported on Spot exchange.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchOpenInterest(string $symbol, string $interval, ?int $startMs = null, ?int $endMs = null): array
    {
        return [];
    }

    private function formatSymbol(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));
        if (str_contains($symbol, '-')) {
            return $symbol;
        }

        foreach (['USDT', 'USDC', 'USD', 'EUR', 'GBP', 'BTC', 'ETH'] as $quote) {
            if (str_ends_with($symbol, $quote) && strlen($symbol) > strlen($quote)) {
                $base = substr($symbol, 0, -strlen($quote));

                return "{$base}-{$quote}";
            }
        }

        return $symbol;
    }
}
