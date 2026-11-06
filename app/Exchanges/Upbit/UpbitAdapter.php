<?php

declare(strict_types=1);

namespace App\Exchanges\Upbit;

use App\Exchanges\Domain\CapabilityStatus;
use App\Exchanges\Domain\MarketCapability;
use App\Exchanges\Domain\MarketType;
use App\Exchanges\Infrastructure\AbstractExchangeAdapter;
use InvalidArgumentException;

/**
 * Upbit public REST market data adapter.
 * Strictly public read-only endpoints. Spot market only. Zero order placement or credentials.
 */
final class UpbitAdapter extends AbstractExchangeAdapter
{
    protected string $baseUrl = 'https://api.upbit.com';

    public function __construct(?string $baseUrl = null)
    {
        if ($baseUrl !== null) {
            $this->baseUrl = rtrim($baseUrl, '/');
        }
    }

    public function id(): string
    {
        return 'upbit';
    }

    public function name(): string
    {
        return 'Upbit';
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

        $raw = $this->get('/v1/market/all', ['isDetails' => 'true']);
        if (! is_array($raw)) {
            return [];
        }

        $instruments = [];
        foreach ($raw as $market) {
            if (! is_array($market) || ! isset($market['market'])) {
                continue;
            }

            $marketCode = (string) $market['market'];
            $parts = explode('-', $marketCode);
            $quote = $parts[0] ?? '';
            $base = $parts[1] ?? '';

            $instruments[] = [
                'exchange_id' => $this->id(),
                'symbol' => $marketCode,
                'base_asset' => $base,
                'quote_asset' => $quote,
                'market_type' => MarketType::Spot->value,
                'tick_size' => '0.00000001',
                'lot_size' => '0.00000001',
                'min_order_qty' => null,
                'max_order_qty' => null,
                'min_notional' => null,
                'is_active' => ($market['market_warning'] ?? 'NONE') !== 'DELISTING',
                'contract_type' => 'spot',
                'raw_source_hash' => hash('sha256', json_encode($market, JSON_THROW_ON_ERROR)),
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
        if ($limit < 1 || $limit > 200) {
            throw new InvalidArgumentException("Limit must be between 1 and 200 for {$this->id()}.");
        }

        $market = $this->formatMarket($symbol);
        $query = ['market' => $market, 'count' => $limit];

        if ($endMs !== null) {
            $query['to'] = gmdate('Y-m-d\TH:i:s\Z', (int) ($endMs / 1000));
        }

        $endpoint = match ($interval) {
            '1m', '1' => '/v1/candles/minutes/1',
            '3m', '3' => '/v1/candles/minutes/3',
            '5m', '5' => '/v1/candles/minutes/5',
            '10m', '10' => '/v1/candles/minutes/10',
            '15m', '15' => '/v1/candles/minutes/15',
            '30m', '30' => '/v1/candles/minutes/30',
            '1h', '60m', '60' => '/v1/candles/minutes/60',
            '4h', '240m', '240' => '/v1/candles/minutes/240',
            '1d', 'D', '1440m' => '/v1/candles/days',
            default => throw new InvalidArgumentException("Unsupported candle interval '{$interval}' for {$this->id()}."),
        };

        $raw = $this->get($endpoint, $query);
        if (! is_array($raw)) {
            return [];
        }

        // Upbit returns newest first
        $rows = array_reverse($raw);
        if (count($rows) > $limit) {
            $rows = array_slice($rows, -$limit);
        }

        $candles = [];
        foreach ($rows as $c) {
            if (! is_array($c) || ! isset($c['opening_price'])) {
                continue;
            }

            $timeStr = (string) ($c['candle_date_time_utc'] ?? '');
            $openMs = isset($c['timestamp']) ? (int) $c['timestamp'] : strtotime($timeStr.'Z') * 1000;

            $candles[] = [
                'exchange_id' => $this->id(),
                'symbol' => $symbol,
                'interval' => $interval,
                'open_time_utc' => self::utcTimestamp($openMs),
                'open' => self::decimal($c['opening_price'], 'opening_price'),
                'high' => self::decimal($c['high_price'], 'high_price'),
                'low' => self::decimal($c['low_price'], 'low_price'),
                'close' => self::decimal($c['trade_price'], 'trade_price'),
                'volume' => self::decimal($c['candle_acc_trade_volume'], 'candle_acc_trade_volume'),
                'turnover' => isset($c['candle_acc_trade_price']) ? self::decimal($c['candle_acc_trade_price'], 'candle_acc_trade_price') : null,
                'is_closed' => true,
                'raw_source_hash' => hash('sha256', json_encode($c, JSON_THROW_ON_ERROR)),
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
        $market = $this->formatMarket($symbol);
        $raw = $this->get('/v1/orderbook', ['markets' => $market]);

        $book = is_array($raw) && isset($raw[0]) && is_array($raw[0]) ? $raw[0] : [];
        $units = $book['orderbook_units'] ?? [];

        $bids = [];
        $asks = [];
        foreach (array_slice($units, 0, $depth) as $unit) {
            if (is_array($unit)) {
                if (isset($unit['bid_price'], $unit['bid_size'])) {
                    $bids[] = [self::decimal($unit['bid_price'], 'bid_price'), self::decimal($unit['bid_size'], 'bid_size')];
                }
                if (isset($unit['ask_price'], $unit['ask_size'])) {
                    $asks[] = [self::decimal($unit['ask_price'], 'ask_price'), self::decimal($unit['ask_size'], 'ask_size')];
                }
            }
        }

        $timestampUtc = isset($book['timestamp'])
            ? self::utcTimestamp($book['timestamp'])
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
        $market = $this->formatMarket($symbol);
        $raw = $this->get('/v1/ticker', ['markets' => $market]);

        $t = is_array($raw) && isset($raw[0]) && is_array($raw[0]) ? $raw[0] : [];

        $timestampUtc = isset($t['timestamp'])
            ? self::utcTimestamp($t['timestamp'])
            : gmdate('Y-m-d\TH:i:s\Z');

        return [
            'exchange_id' => $this->id(),
            'symbol' => $symbol,
            'last_price' => self::decimal($t['trade_price'] ?? null, 'trade_price'),
            'bid_price' => null,
            'ask_price' => null,
            'volume_24h' => self::decimal($t['acc_trade_volume_24h'] ?? '0', 'acc_trade_volume_24h'),
            'turnover_24h' => isset($t['acc_trade_price_24h']) ? self::decimal($t['acc_trade_price_24h'], 'acc_trade_price_24h') : null,
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

    private function formatMarket(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));
        if (str_contains($symbol, '-')) {
            return $symbol;
        }

        // Upbit market notation is QUOTE-BASE, e.g. KRW-BTC or USDT-BTC
        foreach (['KRW', 'USDT', 'BTC'] as $quote) {
            if (str_ends_with($symbol, $quote) && strlen($symbol) > strlen($quote)) {
                $base = substr($symbol, 0, -strlen($quote));

                return "{$quote}-{$base}";
            }
            if (str_starts_with($symbol, $quote) && strlen($symbol) > strlen($quote)) {
                $base = substr($symbol, strlen($quote));

                return "{$quote}-{$base}";
            }
        }

        return $symbol;
    }
}
