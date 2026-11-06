<?php

declare(strict_types=1);

namespace App\Exchanges\Bitget;

use App\Exchanges\Domain\CapabilityStatus;
use App\Exchanges\Domain\MarketCapability;
use App\Exchanges\Domain\MarketType;
use App\Exchanges\Infrastructure\AbstractExchangeAdapter;
use InvalidArgumentException;
use RuntimeException;

/**
 * Bitget public v2 REST market data adapter.
 * Public read-only endpoints: Spot, Perpetual (USDT-FUTURES), Futures.
 * Zero private keys, zero order placement.
 */
final class BitgetAdapter extends AbstractExchangeAdapter
{
    protected string $baseUrl = 'https://api.bitget.com';

    public function __construct(?string $baseUrl = null)
    {
        if ($baseUrl !== null) {
            $this->baseUrl = rtrim($baseUrl, '/');
        }
    }

    public function id(): string
    {
        return 'bitget';
    }

    public function name(): string
    {
        return 'Bitget';
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
                MarketCapability::Liquidations->value => CapabilityStatus::Supported,
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
                MarketCapability::Liquidations->value => CapabilityStatus::Supported,
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

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchInstruments(MarketType $marketType): array
    {
        $instruments = [];

        if ($marketType === MarketType::Spot) {
            $data = $this->getBitget('/api/v2/spot/public/symbols');
            foreach ($data as $row) {
                if (! is_array($row) || ! isset($row['symbol'])) {
                    continue;
                }

                $pricePrec = isset($row['pricePrecision']) ? (int) $row['pricePrecision'] : 2;
                $qtyPrec = isset($row['quantityPrecision']) ? (int) $row['quantityPrecision'] : 4;
                $tickSize = sprintf('%.'.max(0, $pricePrec).'f', 10 ** -$pricePrec);
                $lotSize = sprintf('%.'.max(0, $qtyPrec).'f', 10 ** -$qtyPrec);

                $instruments[] = [
                    'exchange_id' => $this->id(),
                    'symbol' => (string) $row['symbol'],
                    'base_asset' => (string) ($row['baseCoin'] ?? ''),
                    'quote_asset' => (string) ($row['quoteCoin'] ?? 'USDT'),
                    'market_type' => MarketType::Spot->value,
                    'tick_size' => self::decimal($tickSize, 'tick_size'),
                    'lot_size' => self::decimal($lotSize, 'lot_size'),
                    'min_order_qty' => isset($row['minOrderQty']) ? self::decimal($row['minOrderQty'], 'minOrderQty') : null,
                    'max_order_qty' => isset($row['maxOrderQty']) ? self::decimal($row['maxOrderQty'], 'maxOrderQty') : null,
                    'min_notional' => isset($row['minOrderAmount']) ? self::decimal($row['minOrderAmount'], 'minOrderAmount') : null,
                    'is_active' => ($row['status'] ?? '') === 'online',
                    'contract_type' => 'spot',
                    'raw_source_hash' => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)),
                ];
            }
        } elseif ($marketType === MarketType::Perpetual || $marketType === MarketType::Futures) {
            $productType = $marketType === MarketType::Perpetual ? 'USDT-FUTURES' : 'COIN-FUTURES';
            $data = $this->getBitget('/api/v2/mix/market/contracts', ['productType' => $productType]);
            foreach ($data as $row) {
                if (! is_array($row) || ! isset($row['symbol'])) {
                    continue;
                }

                $pricePrec = isset($row['pricePrecision']) ? (int) $row['pricePrecision'] : 2;
                $qtyPrec = isset($row['volumePlace']) ? (int) $row['volumePlace'] : 3;
                $tickSize = sprintf('%.'.max(0, $pricePrec).'f', 10 ** -$pricePrec);
                $lotSize = sprintf('%.'.max(0, $qtyPrec).'f', 10 ** -$qtyPrec);

                $instruments[] = [
                    'exchange_id' => $this->id(),
                    'symbol' => (string) $row['symbol'],
                    'base_asset' => (string) ($row['baseCoin'] ?? ''),
                    'quote_asset' => (string) ($row['quoteCoin'] ?? 'USDT'),
                    'market_type' => $marketType->value,
                    'tick_size' => self::decimal($tickSize, 'tick_size'),
                    'lot_size' => self::decimal($lotSize, 'lot_size'),
                    'min_order_qty' => isset($row['minOrderNum']) ? self::decimal($row['minOrderNum'], 'minOrderNum') : null,
                    'max_order_qty' => isset($row['maxOrderNum']) ? self::decimal($row['maxOrderNum'], 'maxOrderNum') : null,
                    'min_notional' => null,
                    'is_active' => ($row['symbolStatus'] ?? '') === 'normal',
                    'contract_type' => strtolower($marketType->value),
                    'raw_source_hash' => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)),
                ];
            }
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

        $granularity = match ($interval) {
            '1m', '1' => '1m',
            '3m', '3' => '3m',
            '5m', '5' => '5m',
            '15m', '15' => '15m',
            '30m', '30' => '30m',
            '1h', '60m', '60' => '1h',
            '4h', '240m', '240' => '4h',
            '6h', '360m', '360' => '6h',
            '12h', '720m', '720' => '12h',
            '1d', 'D', '1440m' => '1d',
            default => throw new InvalidArgumentException("Unsupported candle interval '{$interval}' for {$this->id()}."),
        };

        $normSymbol = $this->formatSymbol($symbol);
        $query = [
            'symbol' => $normSymbol,
            'granularity' => $granularity,
            'limit' => $limit,
        ];

        if ($startMs !== null) {
            $query['startTime'] = (string) $startMs;
        }
        if ($endMs !== null) {
            $query['endTime'] = (string) $endMs;
        }

        $data = $this->getBitget('/api/v2/spot/market/candles', $query);

        // Bitget returns newest first: [ts, open, high, low, close, volume, quoteVolume]
        $rows = array_reverse($data);
        if (count($rows) > $limit) {
            $rows = array_slice($rows, -$limit);
        }

        $candles = [];
        foreach ($rows as $row) {
            if (! is_array($row) || count($row) < 6) {
                continue;
            }

            $openMs = (int) $row[0];

            $candles[] = [
                'exchange_id' => $this->id(),
                'symbol' => $symbol,
                'interval' => $interval,
                'open_time_utc' => self::utcTimestamp($openMs),
                'open' => self::decimal($row[1], 'open'),
                'high' => self::decimal($row[2], 'high'),
                'low' => self::decimal($row[3], 'low'),
                'close' => self::decimal($row[4], 'close'),
                'volume' => self::decimal($row[5], 'volume'),
                'turnover' => isset($row[6]) && $row[6] !== '' ? self::decimal($row[6], 'turnover') : null,
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
        $normSymbol = $this->formatSymbol($symbol);
        $data = $this->getBitget('/api/v2/spot/market/orderbook', [
            'symbol' => $normSymbol,
            'limit' => min($depth, 100),
        ]);

        $book = is_array($data) && ! array_is_list($data) ? $data : ($data[0] ?? []);

        $bids = [];
        foreach (array_slice($book['bids'] ?? [], 0, $depth) as $bid) {
            if (is_array($bid) && isset($bid[0], $bid[1])) {
                $bids[] = [self::decimal($bid[0], 'bid_price'), self::decimal($bid[1], 'bid_size')];
            }
        }

        $asks = [];
        foreach (array_slice($book['asks'] ?? [], 0, $depth) as $ask) {
            if (is_array($ask) && isset($ask[0], $ask[1])) {
                $asks[] = [self::decimal($ask[0], 'ask_price'), self::decimal($ask[1], 'ask_size')];
            }
        }

        $timestampUtc = isset($book['ts'])
            ? self::utcTimestamp($book['ts'])
            : gmdate('Y-m-d\TH:i:s\Z');

        return [
            'exchange_id' => $this->id(),
            'symbol' => $symbol,
            'timestamp_utc' => $timestampUtc,
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
        $normSymbol = $this->formatSymbol($symbol);
        $data = $this->getBitget('/api/v2/spot/market/tickers', ['symbol' => $normSymbol]);

        $t = is_array($data) && isset($data[0]) && is_array($data[0]) ? $data[0] : (is_array($data) ? $data : []);

        $timestampUtc = isset($t['ts'])
            ? self::utcTimestamp($t['ts'])
            : gmdate('Y-m-d\TH:i:s\Z');

        return [
            'exchange_id' => $this->id(),
            'symbol' => $symbol,
            'last_price' => self::decimal($t['lastPr'] ?? $t['lastPrice'] ?? $t['last'] ?? null, 'lastPrice'),
            'bid_price' => isset($t['bidPr']) && $t['bidPr'] !== '' ? self::decimal($t['bidPr'], 'bidPr') : null,
            'ask_price' => isset($t['askPr']) && $t['askPr'] !== '' ? self::decimal($t['askPr'], 'askPr') : null,
            'volume_24h' => self::decimal($t['baseVolume'] ?? $t['volume24h'] ?? '0', 'volume_24h'),
            'turnover_24h' => isset($t['quoteVolume']) && $t['quoteVolume'] !== '' ? self::decimal($t['quoteVolume'], 'turnover_24h') : null,
            'timestamp_utc' => $timestampUtc,
            'raw_source_hash' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchFundingHistory(string $symbol, ?int $startMs = null, ?int $endMs = null): array
    {
        $normSymbol = $this->formatSymbol($symbol);
        $data = $this->getBitget('/api/v2/mix/market/history-fund-rate', [
            'symbol' => $normSymbol,
            'productType' => 'USDT-FUTURES',
            'pageSize' => 100,
            'pageNo' => 1,
        ]);

        $results = [];
        foreach ($data as $row) {
            if (! is_array($row) || ! isset($row['fundingRate'], $row['fundingTime'])) {
                continue;
            }

            $results[] = [
                'exchange_id' => $this->id(),
                'symbol' => $symbol,
                'funding_time_utc' => self::utcTimestamp($row['fundingTime']),
                'funding_rate' => self::decimal($row['fundingRate'], 'fundingRate'),
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
        $normSymbol = $this->formatSymbol($symbol);
        $data = $this->getBitget('/api/v2/mix/market/open-interest', [
            'symbol' => $normSymbol,
            'productType' => 'USDT-FUTURES',
        ]);

        $items = [];
        if (is_array($data) && isset($data['openInterestList'])) {
            $items = $data['openInterestList'];
        } elseif (is_array($data) && isset($data[0]['openInterestList'])) {
            $items = $data[0]['openInterestList'];
        } elseif (is_array($data) && array_is_list($data)) {
            $items = $data;
        }

        $results = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['size'], $item['ts'])) {
                continue;
            }

            $results[] = [
                'exchange_id' => $this->id(),
                'symbol' => $symbol,
                'timestamp_utc' => self::utcTimestamp($item['ts']),
                'open_interest' => self::decimal($item['size'], 'size'),
                'open_interest_value_usdt' => isset($item['amount']) && $item['amount'] !== '' ? self::decimal($item['amount'], 'amount') : null,
                'raw_source_hash' => hash('sha256', json_encode($item, JSON_THROW_ON_ERROR)),
            ];
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function getBitget(string $path, array $query = []): mixed
    {
        $payload = $this->get($path, $query);

        $code = (string) ($payload['code'] ?? '');
        if ($code !== '00000') {
            $msg = (string) ($payload['msg'] ?? 'Unknown Bitget error');
            throw new RuntimeException("Bitget API error [{$code}]: {$msg}");
        }

        return $payload['data'] ?? [];
    }

    private function formatSymbol(string $symbol): string
    {
        return str_replace('-', '', strtoupper(trim($symbol)));
    }
}
