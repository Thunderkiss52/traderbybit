<?php

declare(strict_types=1);

namespace App\Exchanges\Okx;

use App\Exchanges\Domain\CapabilityStatus;
use App\Exchanges\Domain\MarketCapability;
use App\Exchanges\Domain\MarketType;
use App\Exchanges\Infrastructure\AbstractExchangeAdapter;
use InvalidArgumentException;
use RuntimeException;

/**
 * OKX public v5 REST market data adapter.
 * Public read-only endpoints: Spot, Perpetual (SWAP), Futures, Options.
 * Zero private keys, zero order placement.
 */
final class OkxAdapter extends AbstractExchangeAdapter
{
    protected string $baseUrl = 'https://www.okx.com';

    public function __construct(?string $baseUrl = null)
    {
        if ($baseUrl !== null) {
            $this->baseUrl = rtrim($baseUrl, '/');
        }
    }

    public function id(): string
    {
        return 'okx';
    }

    public function name(): string
    {
        return 'OKX';
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
                MarketCapability::Candles->value => CapabilityStatus::Supported,
                MarketCapability::OrderBookL2->value => CapabilityStatus::Supported,
                MarketCapability::Trades->value => CapabilityStatus::Supported,
                MarketCapability::Ticker->value => CapabilityStatus::Supported,
                MarketCapability::MarkPrice->value => CapabilityStatus::Supported,
                MarketCapability::FundingHistory->value => CapabilityStatus::Unsupported,
                MarketCapability::OpenInterest->value => CapabilityStatus::Supported,
                MarketCapability::Liquidations->value => CapabilityStatus::Supported,
                MarketCapability::OptionsChain->value => CapabilityStatus::Supported,
            ],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchInstruments(MarketType $marketType): array
    {
        $instType = match ($marketType) {
            MarketType::Spot => 'SPOT',
            MarketType::Perpetual => 'SWAP',
            MarketType::Futures => 'FUTURES',
            MarketType::Options => 'OPTION',
        };

        $data = $this->getOkx('/api/v5/public/instruments', ['instType' => $instType]);
        $instruments = [];

        foreach ($data as $row) {
            if (! is_array($row) || ! isset($row['instId'])) {
                continue;
            }

            $instruments[] = [
                'exchange_id' => $this->id(),
                'symbol' => (string) $row['instId'],
                'base_asset' => (string) ($row['baseCcy'] ?? ($row['ctValCcy'] ?? '')),
                'quote_asset' => (string) ($row['quoteCcy'] ?? ($row['settleCcy'] ?? 'USDT')),
                'market_type' => $marketType->value,
                'tick_size' => self::decimal($row['tickSz'] ?? '0.01', 'tickSz'),
                'lot_size' => self::decimal($row['lotSz'] ?? '1', 'lotSz'),
                'min_order_qty' => isset($row['minSz']) ? self::decimal($row['minSz'], 'minSz') : null,
                'max_order_qty' => isset($row['maxLmtSz']) ? self::decimal($row['maxLmtSz'], 'maxLmtSz') : null,
                'min_notional' => null,
                'is_active' => ($row['state'] ?? '') === 'live',
                'contract_type' => strtolower($marketType->value),
                'raw_source_hash' => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)),
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

        $bar = match ($interval) {
            '1m', '1' => '1m',
            '3m', '3' => '3m',
            '5m', '5' => '5m',
            '15m', '15' => '15m',
            '30m', '30' => '30m',
            '1h', '60m', '60' => '1H',
            '2h', '120m', '120' => '2H',
            '4h', '240m', '240' => '4H',
            '1d', 'D', '1440m' => '1D',
            default => throw new InvalidArgumentException("Unsupported candle interval '{$interval}' for {$this->id()}."),
        };

        $instId = $this->formatInstId($symbol);
        $query = [
            'instId' => $instId,
            'bar' => $bar,
            'limit' => min($limit, 100),
        ];

        if ($endMs !== null) {
            $query['after'] = (string) $endMs;
        }
        if ($startMs !== null) {
            $query['before'] = (string) $startMs;
        }

        $data = $this->getOkx('/api/v5/market/candles', $query);

        // OKX returns newest first: [ts, o, h, l, c, vol, volCcy, volCcyQuote, confirm]
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
            $isClosed = ($row[8] ?? '1') === '1';

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
                'is_closed' => $isClosed,
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
        $instId = $this->formatInstId($symbol);
        $data = $this->getOkx('/api/v5/market/books', [
            'instId' => $instId,
            'sz' => min($depth, 400),
        ]);

        $book = isset($data[0]) && is_array($data[0]) ? $data[0] : [];

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
        $instId = $this->formatInstId($symbol);
        $data = $this->getOkx('/api/v5/market/ticker', ['instId' => $instId]);

        $t = isset($data[0]) && is_array($data[0]) ? $data[0] : [];

        $timestampUtc = isset($t['ts'])
            ? self::utcTimestamp($t['ts'])
            : gmdate('Y-m-d\TH:i:s\Z');

        return [
            'exchange_id' => $this->id(),
            'symbol' => $symbol,
            'last_price' => self::decimal($t['last'] ?? null, 'last'),
            'bid_price' => isset($t['bidPx']) && $t['bidPx'] !== '' ? self::decimal($t['bidPx'], 'bidPx') : null,
            'ask_price' => isset($t['askPx']) && $t['askPx'] !== '' ? self::decimal($t['askPx'], 'askPx') : null,
            'volume_24h' => self::decimal($t['vol24h'] ?? '0', 'vol24h'),
            'turnover_24h' => isset($t['volCcy24h']) && $t['volCcy24h'] !== '' ? self::decimal($t['volCcy24h'], 'volCcy24h') : null,
            'timestamp_utc' => $timestampUtc,
            'raw_source_hash' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchFundingHistory(string $symbol, ?int $startMs = null, ?int $endMs = null): array
    {
        $instId = $this->formatPerpetualInstId($symbol);
        $query = ['instId' => $instId, 'limit' => 100];

        if ($endMs !== null) {
            $query['after'] = (string) $endMs;
        }
        if ($startMs !== null) {
            $query['before'] = (string) $startMs;
        }

        $data = $this->getOkx('/api/v5/public/funding-rate-history', $query);

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
                'mark_price' => isset($row['realizedRate']) && $row['realizedRate'] !== '' ? self::decimal($row['realizedRate'], 'realizedRate') : null,
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
        $instId = $this->formatPerpetualInstId($symbol);
        $data = $this->getOkx('/api/v5/public/open-interest', [
            'instType' => 'SWAP',
            'instId' => $instId,
        ]);

        $results = [];
        foreach ($data as $row) {
            if (! is_array($row) || ! isset($row['oi'], $row['ts'])) {
                continue;
            }

            $results[] = [
                'exchange_id' => $this->id(),
                'symbol' => $symbol,
                'timestamp_utc' => self::utcTimestamp($row['ts']),
                'open_interest' => self::decimal($row['oi'], 'oi'),
                'open_interest_value_usdt' => isset($row['oiCcy']) && $row['oiCcy'] !== '' ? self::decimal($row['oiCcy'], 'oiCcy') : null,
                'raw_source_hash' => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)),
            ];
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<mixed>
     */
    private function getOkx(string $path, array $query = []): array
    {
        $payload = $this->get($path, $query);

        $code = (string) ($payload['code'] ?? '');
        if ($code !== '0') {
            $msg = (string) ($payload['msg'] ?? 'Unknown OKX error');
            throw new RuntimeException("OKX API error [{$code}]: {$msg}");
        }

        $data = $payload['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    private function formatInstId(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));
        if (str_contains($symbol, '-')) {
            return $symbol;
        }

        foreach (['USDT', 'USDC', 'USD', 'EUR', 'BTC', 'ETH'] as $quote) {
            if (str_ends_with($symbol, $quote) && strlen($symbol) > strlen($quote)) {
                $base = substr($symbol, 0, -strlen($quote));

                return "{$base}-{$quote}";
            }
        }

        return $symbol;
    }

    private function formatPerpetualInstId(string $symbol): string
    {
        $instId = $this->formatInstId($symbol);
        if (! str_ends_with($instId, '-SWAP') && ! str_contains($instId, '-SWAP')) {
            $instId .= '-SWAP';
        }

        return $instId;
    }
}
