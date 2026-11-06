<?php

declare(strict_types=1);

namespace App\Exchanges\Htx;

use App\Exchanges\Domain\CapabilityStatus;
use App\Exchanges\Domain\MarketCapability;
use App\Exchanges\Domain\MarketType;
use App\Exchanges\Infrastructure\AbstractExchangeAdapter;
use RuntimeException;

/**
 * HTX (Huobi) public market data adapter.
 * Strictly read-only public market data. Zero credentials, zero order placement.
 */
final class HtxAdapter extends AbstractExchangeAdapter
{
    protected string $baseUrl = 'https://api.hbdm.com';

    protected string $spotBaseUrl = 'https://api.huobi.pro';

    protected string $futuresBaseUrl = 'https://api.hbdm.com';

    public function __construct(
        string $futuresBaseUrl = 'https://api.hbdm.com',
        string $spotBaseUrl = 'https://api.huobi.pro'
    ) {
        $this->futuresBaseUrl = $futuresBaseUrl;
        $this->spotBaseUrl = $spotBaseUrl;
        $this->baseUrl = $futuresBaseUrl;
    }

    public function id(): string
    {
        return 'htx';
    }

    public function name(): string
    {
        return 'HTX (Huobi)';
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
        if (str_contains($symbol, '-')) {
            return $symbol;
        }
        if (str_contains($symbol, '_')) {
            return str_replace('_', '-', $symbol);
        }
        if (str_ends_with($symbol, 'USDT') && strlen($symbol) > 4) {
            return substr($symbol, 0, -4).'-USDT';
        }

        return $symbol;
    }

    public static function normalizeSpotSymbol(string $symbol): string
    {
        $symbol = strtolower(trim($symbol));
        $symbol = str_replace(['-', '_'], '', $symbol);

        return $symbol;
    }

    public static function normalizePeriod(string $interval): string
    {
        $normalized = strtolower(trim($interval));

        return match ($normalized) {
            '1', '1m', '1min' => '1min',
            '5', '5m', '5min' => '5min',
            '15', '15m', '15min' => '15min',
            '30', '30m', '30min' => '30min',
            '60', '1h', '60min' => '60min',
            '240', '4h', '4hour' => '4hour',
            '1440', '1d', 'd', '1day' => '1day',
            '1w', 'w', '1week' => '1week',
            default => '15min',
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchCandles(string $symbol, string $interval, int $limit, ?int $startMs = null, ?int $endMs = null): array
    {
        $contract = self::normalizeFuturesSymbol($symbol);
        $period = self::normalizePeriod($interval);
        $limit = min(max(1, $limit), 1000);

        $query = [
            'contract_code' => $contract,
            'period' => $period,
            'size' => $limit,
        ];
        if ($startMs !== null) {
            $query['from'] = (int) ($startMs / 1000);
        }
        if ($endMs !== null) {
            $query['to'] = (int) ($endMs / 1000);
        }

        $raw = $this->get("{$this->futuresBaseUrl}/linear-swap-ex/market/history/kline", $query);
        $rows = $raw['data'] ?? [];
        if (! is_array($rows)) {
            throw new RuntimeException("Malformed HTX candle response for {$symbol}.");
        }

        $results = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['id'])) {
                continue;
            }

            $ts = (int) $row['id'];
            $open = self::decimal($row['open'] ?? null, 'open');
            $high = self::decimal($row['high'] ?? null, 'high');
            $low = self::decimal($row['low'] ?? null, 'low');
            $close = self::decimal($row['close'] ?? null, 'close');
            $volume = self::decimal($row['amount'] ?? '0', 'volume');
            $turnover = isset($row['vol']) ? self::decimal($row['vol'], 'turnover') : null;

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

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchFundingHistory(string $symbol, ?int $startMs = null, ?int $endMs = null): array
    {
        $contract = self::normalizeFuturesSymbol($symbol);
        $raw = $this->get("{$this->futuresBaseUrl}/linear-swap-api/v1/swap_historical_funding_rate", [
            'contract_code' => $contract,
            'page_size' => 50,
        ]);

        $data = $raw['data'] ?? [];
        $rows = is_array($data) && isset($data['data']) && is_array($data['data']) ? $data['data'] : (array_is_list($data) ? $data : []);

        $results = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['funding_rate'], $row['funding_time'])) {
                continue;
            }

            $results[] = [
                'exchange_id' => $this->id(),
                'symbol' => $symbol,
                'funding_time_utc' => self::utcTimestamp((int) $row['funding_time']),
                'funding_rate' => self::decimal($row['funding_rate'], 'funding_rate'),
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
        $contract = self::normalizeFuturesSymbol($symbol);
        $raw = $this->get("{$this->futuresBaseUrl}/linear-swap-api/v1/swap_open_interest", [
            'contract_code' => $contract,
        ]);

        $rows = $raw['data'] ?? [];
        $results = [];

        if (is_array($rows)) {
            $items = array_is_list($rows) ? $rows : [$rows];
            foreach ($items as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $vol = self::decimal($row['volume'] ?? '0', 'volume');
                $amount = isset($row['amount']) ? self::decimal($row['amount'], 'amount') : null;

                $results[] = [
                    'exchange_id' => $this->id(),
                    'symbol' => $symbol,
                    'timestamp_utc' => gmdate('Y-m-d\TH:i:s\Z'),
                    'open_interest' => $vol,
                    'open_interest_value_usdt' => $amount,
                    'raw_source_hash' => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)),
                ];
            }
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchSymbols(): array
    {
        $raw = $this->get("{$this->futuresBaseUrl}/linear-swap-api/v1/swap_contract_info");
        $rows = $raw['data'] ?? [];
        if (! is_array($rows)) {
            throw new RuntimeException('Malformed HTX contract info response.');
        }

        $results = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['contract_code'])) {
                continue;
            }

            $code = (string) $row['contract_code'];
            $parts = explode('-', $code);
            $base = $parts[0] ?? '';
            $quote = $parts[1] ?? 'USDT';

            $results[] = [
                'exchange_id' => $this->id(),
                'symbol' => $code,
                'base_asset' => $base,
                'quote_asset' => $quote,
                'tick_size' => isset($row['price_tick']) ? self::decimal($row['price_tick']) : '0.1',
                'lot_size' => isset($row['contract_size']) ? self::decimal($row['contract_size']) : '1',
                'min_order_qty' => '1',
                'max_order_qty' => null,
                'min_notional' => '10.0',
                'contract_type' => 'perpetual',
                'is_active' => ($row['contract_status'] ?? 0) === 1,
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
            $raw = $this->get("{$this->spotBaseUrl}/v1/common/symbols");
            $rows = $raw['data'] ?? [];
            if (! is_array($rows)) {
                throw new RuntimeException('Malformed HTX spot symbols response.');
            }

            $results = [];
            foreach ($rows as $row) {
                if (! is_array($row) || ! isset($row['symbol'])) {
                    continue;
                }
                $symbol = (string) $row['symbol'];
                $base = strtoupper((string) ($row['base-currency'] ?? ''));
                $quote = strtoupper((string) ($row['quote-currency'] ?? ''));
                $tickSize = isset($row['price-precision']) ? (string) (10 ** -((int) $row['price-precision'])) : '0.01';
                $lotSize = isset($row['amount-precision']) ? (string) (10 ** -((int) $row['amount-precision'])) : '0.0001';
                $minQty = isset($row['min-order-amt']) ? self::decimal($row['min-order-amt']) : null;
                $maxQty = isset($row['max-order-amt']) ? self::decimal($row['max-order-amt']) : null;
                $minNotional = isset($row['min-order-value']) ? self::decimal($row['min-order-value']) : '5.0';
                $isActive = ($row['state'] ?? '') === 'online';

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

        // Perpetual or Futures
        $raw = $this->get("{$this->futuresBaseUrl}/linear-swap-api/v1/swap_contract_info");
        $rows = $raw['data'] ?? [];
        if (! is_array($rows)) {
            throw new RuntimeException('Malformed HTX linear swap contract info.');
        }

        $results = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['contract_code'])) {
                continue;
            }
            $code = (string) $row['contract_code'];
            $parts = explode('-', $code);
            $base = $parts[0] ?? '';
            $quote = $parts[1] ?? 'USDT';

            $results[] = [
                'exchange_id' => $this->id(),
                'market_type' => $marketType->value,
                'symbol' => $code,
                'base_asset' => $base,
                'quote_asset' => $quote,
                'tick_size' => isset($row['price_tick']) ? self::decimal($row['price_tick']) : '0.1',
                'lot_size' => isset($row['contract_size']) ? self::decimal($row['contract_size']) : '1',
                'min_order_qty' => '1',
                'max_order_qty' => null,
                'min_notional' => '10.0',
                'is_active' => ($row['contract_status'] ?? 0) === 1,
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
        $raw = $this->get("{$this->futuresBaseUrl}/linear-swap-ex/market/depth", [
            'contract_code' => $contract,
            'type' => 'step0',
        ]);

        $tick = $raw['tick'] ?? $raw;
        $bids = [];
        $asks = [];

        if (isset($tick['bids']) && is_array($tick['bids'])) {
            foreach ($tick['bids'] as $bid) {
                if (is_array($bid) && isset($bid[0], $bid[1])) {
                    $bids[] = [self::decimal($bid[0], 'bid_price'), self::decimal($bid[1], 'bid_size')];
                }
            }
        }
        if (isset($tick['asks']) && is_array($tick['asks'])) {
            foreach ($tick['asks'] as $ask) {
                if (is_array($ask) && isset($ask[0], $ask[1])) {
                    $asks[] = [self::decimal($ask[0], 'ask_price'), self::decimal($ask[1], 'ask_size')];
                }
            }
        }

        $ts = $tick['ts'] ?? $raw['ts'] ?? microtime(true) * 1000;

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
        $contract = self::normalizeFuturesSymbol($symbol);
        $raw = $this->get("{$this->futuresBaseUrl}/linear-swap-ex/market/detail/merged", [
            'contract_code' => $contract,
        ]);

        $tick = $raw['tick'] ?? $raw;
        if (! is_array($tick)) {
            throw new RuntimeException("Malformed HTX ticker response for {$symbol}.");
        }

        $last = self::decimal($tick['close'] ?? null, 'last_price');
        $bid = isset($tick['bid'][0]) ? self::decimal($tick['bid'][0], 'bid_price') : null;
        $ask = isset($tick['ask'][0]) ? self::decimal($tick['ask'][0], 'ask_price') : null;
        $vol = self::decimal($tick['amount'] ?? '0', 'volume_24h');
        $turnover = isset($tick['vol']) ? self::decimal($tick['vol'], 'turnover_24h') : null;
        $ts = $tick['ts'] ?? $raw['ts'] ?? microtime(true) * 1000;

        return [
            'exchange_id' => $this->id(),
            'symbol' => $symbol,
            'last_price' => $last,
            'bid_price' => $bid,
            'ask_price' => $ask,
            'volume_24h' => $vol,
            'turnover_24h' => $turnover,
            'timestamp_utc' => self::utcTimestamp($ts),
            'raw_source_hash' => hash('sha256', json_encode($raw, JSON_THROW_ON_ERROR)),
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
