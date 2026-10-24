<?php

declare(strict_types=1);

namespace App\Exchanges\Binance;

use App\Exchanges\Contracts\ExchangeAdapter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use RuntimeException;

/** Binance USD-M public REST market data. Strictly no signed requests or trading endpoints. */
final class BinancePublicAdapter implements ExchangeAdapter
{
    public function id(): string
    {
        return 'binance';
    }

    public function fetchCandles(string $symbol, string $interval, int $limit, ?int $startMs = null, ?int $endMs = null): array
    {
        $symbol = self::symbol($symbol);
        $interval = self::interval($interval);
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Candle limit must be between 1 and 500.');
        }
        $args = ['symbol' => $symbol, 'interval' => $interval, 'limit' => $limit];
        if ($startMs !== null) {
            $args['startTime'] = self::timestamp($startMs);
        }
        if ($endMs !== null) {
            $args['endTime'] = self::timestamp($endMs);
        }

        return self::normalizeCandles($this->get('/fapi/v1/klines', $args), $symbol, $interval);
    }

    public function fetchFundingHistory(string $symbol, ?int $startMs = null, ?int $endMs = null): array
    {
        $symbol = self::symbol($symbol);
        $args = ['symbol' => $symbol, 'limit' => 100];
        if ($startMs !== null) {
            $args['startTime'] = self::timestamp($startMs);
        }
        if ($endMs !== null) {
            $args['endTime'] = self::timestamp($endMs);
        }
        $rows = $this->get('/fapi/v1/fundingRate', $args);
        if (! array_is_list($rows)) {
            throw new RuntimeException('Malformed Binance funding response.');
        }

        return array_map(static function ($row) use ($symbol): array {
            if (! is_array($row) || ($row['symbol'] ?? '') !== $symbol) {
                throw new RuntimeException('Binance funding symbol mismatch.');
            }
            $time = self::timestamp($row['fundingTime'] ?? null);

            return [
                'exchange_id' => 'binance', 'symbol' => $symbol,
                'funding_time_utc' => self::utc($time),
                'funding_rate' => self::decimal($row['fundingRate'] ?? null),
                'mark_price' => isset($row['markPrice']) ? self::positiveDecimal($row['markPrice'], 'settlement mark price') : null,
                'raw_source_hash' => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)),
            ];
        }, $rows);
    }

    public function fetchOpenInterest(string $symbol, string $interval, ?int $startMs = null, ?int $endMs = null): array
    {
        $symbol = self::symbol($symbol);
        $period = self::interval($interval);
        if (! in_array($period, ['5m', '15m', '30m', '1h', '4h', '1d'], true)) {
            throw new InvalidArgumentException('Binance OI interval unsupported.');
        }
        $args = ['symbol' => $symbol, 'period' => $period, 'limit' => 30];
        if ($startMs !== null) {
            $args['startTime'] = self::timestamp($startMs);
        }
        if ($endMs !== null) {
            $args['endTime'] = self::timestamp($endMs);
        }
        $rows = $this->get('/futures/data/openInterestHist', $args);
        if (! array_is_list($rows)) {
            throw new RuntimeException('Malformed Binance open-interest response.');
        }

        return array_map(static function ($row) use ($symbol): array {
            if (! is_array($row) || ($row['symbol'] ?? '') !== $symbol) {
                throw new RuntimeException('Binance OI symbol mismatch.');
            }

            return [
                'exchange_id' => 'binance', 'symbol' => $symbol,
                'timestamp_utc' => self::utc(self::timestamp($row['timestamp'] ?? null)),
                'open_interest' => self::nonnegativeDecimal($row['sumOpenInterest'] ?? null, 'open interest'),
                'open_interest_value_usdt' => isset($row['sumOpenInterestValue']) ? self::nonnegativeDecimal($row['sumOpenInterestValue'], 'open interest notional') : null,
                'raw_source_hash' => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)),
            ];
        }, $rows);
    }

    public function fetchSymbols(): array
    {
        $raw = $this->get('/fapi/v1/exchangeInfo', []);
        if (! isset($raw['symbols']) || ! is_array($raw['symbols'])) {
            throw new RuntimeException('Malformed Binance exchange info.');
        }
        $results = [];
        foreach ($raw['symbols'] as $row) {
            if (! is_array($row) || ($row['contractType'] ?? '') !== 'PERPETUAL' || ($row['quoteAsset'] ?? '') !== 'USDT') {
                continue;
            }
            // The global inventory can contain contracts outside our canonical
            // symbol alphabet. Do not let an unrelated listing disable BTC/ETH;
            // direct requests still reject unsupported symbols before HTTP.
            if (! is_string($row['symbol'] ?? null) || ! preg_match('/^[A-Z0-9]{4,32}$/D', $row['symbol'])) {
                continue;
            }
            $symbol = self::symbol($row['symbol']);
            $filters = [];
            foreach ($row['filters'] ?? [] as $filter) {
                if (is_array($filter) && is_string($filter['filterType'] ?? null)) {
                    $filters[$filter['filterType']] = $filter;
                }
            }
            // Missing exchange precision is unavailable evidence, not an invented 0.
            if (! isset($filters['PRICE_FILTER']['tickSize'], $filters['LOT_SIZE']['stepSize'])) {
                continue;
            }
            $notional = $filters['MIN_NOTIONAL']['notional'] ?? $filters['NOTIONAL']['minNotional'] ?? null;
            $results[] = [
                'exchange_id' => 'binance', 'symbol' => $symbol,
                'base_asset' => (string) ($row['baseAsset'] ?? ''), 'quote_asset' => 'USDT',
                'tick_size' => self::decimal($filters['PRICE_FILTER']['tickSize']),
                'lot_size' => self::decimal($filters['LOT_SIZE']['stepSize']),
                'min_order_qty' => isset($filters['LOT_SIZE']['minQty']) ? self::decimal($filters['LOT_SIZE']['minQty']) : null,
                'max_order_qty' => isset($filters['LOT_SIZE']['maxQty']) ? self::decimal($filters['LOT_SIZE']['maxQty']) : null,
                'min_notional' => $notional === null ? null : self::decimal($notional),
                'funding_interval_seconds' => isset($row['fundingIntervalHours']) ? ((int) $row['fundingIntervalHours'] * 3600) : null,
                'contract_type' => 'perpetual', 'is_active' => ($row['status'] ?? '') === 'TRADING',
            ];
        }

        return $results;
    }

    /** Public market context with source-derived exchange precision; no credentials. */
    public function fetchContext(string $symbol): array
    {
        $symbol = self::symbol($symbol);
        $book = $this->get('/fapi/v1/ticker/bookTicker', ['symbol' => $symbol]);
        $ticker = $this->get('/fapi/v1/ticker/24hr', ['symbol' => $symbol]);
        $premium = $this->get('/fapi/v1/premiumIndex', ['symbol' => $symbol]);
        if (($book['symbol'] ?? null) !== $symbol || ($ticker['symbol'] ?? null) !== $symbol
            || ($premium['symbol'] ?? null) !== $symbol) {
            throw new RuntimeException('Binance market-context symbol mismatch.');
        }
        $metadata = null;
        foreach ($this->fetchSymbols() as $item) {
            if ($item['symbol'] === $symbol && $item['is_active']) {
                $metadata = $item;
                break;
            }
        }
        if ($metadata === null || $metadata['min_notional'] === null
            || $metadata['min_order_qty'] === null || $metadata['max_order_qty'] === null) {
            throw new RuntimeException('Binance trading constraints unavailable.');
        }

        return self::normalizeContext($symbol, $book, $ticker, $premium, $metadata);
    }

    /** Validate each independent public response before publishing a tradable quote. */
    public static function normalizeContext(string $symbol, array $book, array $ticker, array $premium, array $metadata): array
    {
        $symbol = self::symbol($symbol);
        if (($book['symbol'] ?? null) !== $symbol || ($ticker['symbol'] ?? null) !== $symbol
            || ($premium['symbol'] ?? null) !== $symbol || ($metadata['symbol'] ?? null) !== $symbol
            || ($metadata['exchange_id'] ?? null) !== 'binance' || ($metadata['is_active'] ?? null) !== true) {
            throw new RuntimeException('Binance quote or contract metadata provenance mismatch.');
        }
        $bid = self::positiveDecimal($book['bidPrice'] ?? null, 'bid price');
        $ask = self::positiveDecimal($book['askPrice'] ?? null, 'ask price');
        self::positiveDecimal($book['bidQty'] ?? null, 'bid quantity');
        self::positiveDecimal($book['askQty'] ?? null, 'ask quantity');
        if (self::compareUnsignedDecimal($bid, $ask) > 0) {
            throw new RuntimeException('Binance best bid exceeds best ask.');
        }
        $turnover = self::decimal($ticker['quoteVolume'] ?? null);
        if (str_starts_with($turnover, '-')) {
            throw new RuntimeException('Negative Binance 24h quote turnover.');
        }
        $tickSize = self::positiveDecimal($metadata['tick_size'] ?? null, 'tick size');
        $qtyStep = self::positiveDecimal($metadata['lot_size'] ?? null, 'quantity step');
        $minQuantity = self::positiveDecimal($metadata['min_order_qty'] ?? null, 'minimum quantity');
        $maxQuantity = self::positiveDecimal($metadata['max_order_qty'] ?? null, 'maximum quantity');
        $minNotional = self::positiveDecimal($metadata['min_notional'] ?? null, 'minimum notional');
        if (self::compareUnsignedDecimal($minQuantity, $maxQuantity) > 0) {
            throw new RuntimeException('Binance minimum order quantity exceeds maximum.');
        }

        return [
            'exchange_id' => 'binance', 'symbol' => $symbol, 'bid' => $bid, 'ask' => $ask,
            'turnover_24h' => $turnover, 'tick_size' => $tickSize, 'qty_step' => $qtyStep,
            'min_order_qty' => $minQuantity, 'max_order_qty' => $maxQuantity,
            'min_notional_value' => $minNotional,
            'funding_rate' => self::decimal($premium['lastFundingRate'] ?? null),
        ];
    }

    public static function normalizeCandles(mixed $rows, string $symbol, string $interval): array
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException('Malformed Binance candle response.');
        }
        // Binance fixed-duration klines are UTC-aligned and close at the final
        // millisecond of the interval. An irregular bar must never enter
        // indicator or PAPER execution inputs, even if OHLC values are valid.
        $durations = [
            '1m' => 60000, '3m' => 180000, '5m' => 300000,
            '15m' => 900000, '30m' => 1800000, '1h' => 3600000,
            '2h' => 7200000, '4h' => 14400000, '6h' => 21600000,
            '8h' => 28800000, '12h' => 43200000, '1d' => 86400000,
        ];
        $durationMs = $durations[self::interval($interval)];
        $result = [];
        $previousOpenMs = null;
        foreach ($rows as $row) {
            if (! is_array($row) || ! array_is_list($row) || count($row) < 8) {
                throw new RuntimeException('Malformed Binance candle row.');
            }
            $openMs = self::timestamp($row[0]);
            $closeMs = self::timestamp($row[6]);
            if ($openMs % $durationMs !== 0 || $closeMs !== $openMs + $durationMs - 1) {
                throw new RuntimeException('Invalid Binance candle interval alignment or close time.');
            }
            if ($previousOpenMs !== null && $openMs <= $previousOpenMs) {
                throw new RuntimeException('Binance candles are duplicated or out of order.');
            }
            $previousOpenMs = $openMs;
            $open = self::decimal($row[1]);
            $high = self::decimal($row[2]);
            $low = self::decimal($row[3]);
            $close = self::decimal($row[4]);
            $volume = self::decimal($row[5]);
            $turnover = self::decimal($row[7]);
            // Prices and OHLC relationships must be validated without converting
            // exact exchange decimal strings to floating point.
            if (! self::isPositive($open) || ! self::isPositive($high)
                || ! self::isPositive($low) || ! self::isPositive($close)
                || self::compareUnsignedDecimal($high, $low) < 0
                || self::compareUnsignedDecimal($high, $open) < 0
                || self::compareUnsignedDecimal($high, $close) < 0
                || self::compareUnsignedDecimal($low, $open) > 0
                || self::compareUnsignedDecimal($low, $close) > 0) {
                throw new RuntimeException('Invalid Binance candle OHLC values.');
            }
            if (str_starts_with($volume, '-') || str_starts_with($turnover, '-')) {
                throw new RuntimeException('Invalid Binance candle volume or turnover.');
            }
            $result[] = [
                'exchange_id' => 'binance', 'symbol' => $symbol, 'interval' => $interval,
                'open_time_utc' => self::utc($openMs), 'close_time_utc' => self::utc($closeMs),
                'open' => $open, 'high' => $high, 'low' => $low, 'close' => $close,
                'volume' => $volume, 'turnover' => $turnover,
                'is_closed' => $closeMs < (int) floor(microtime(true) * 1000),
                'raw_source_hash' => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)),
            ];
        }

        return $result;
    }

    private function get(string $path, array $query): array
    {
        // Only idempotent public GETs are retried. Never follow a redirect to
        // an unreviewed host/path, even if the original URL was allowlisted.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $delay = 200 * (2 ** $attempt);
            try {
                $response = Http::acceptJson()->withoutRedirecting()
                    ->connectTimeout(3)->timeout(max(1, min(15, (int) config('exchanges.binance.timeout_seconds', 8))))
                    ->get('https://fapi.binance.com'.$path, $query);
            } catch (ConnectionException) {
                if ($attempt === 2) {
                    break;
                }
                Sleep::for($delay)->milliseconds();

                continue;
            }
            if ($response->successful()) {
                $body = $response->json();
                if (! is_array($body)) {
                    throw new RuntimeException('Malformed Binance public API response.');
                }
                if (array_key_exists('code', $body)) {
                    break;
                }

                return $body;
            }
            if ($attempt === 2) {
                break;
            }
            if ($response->status() === 429) {
                $retryAfter = $response->header('Retry-After');
                // Unknown/long cooldowns are deferred to the caller; never
                // shorten a server cooldown to fit this bounded request.
                if (! ctype_digit($retryAfter) || (int) $retryAfter > 5) {
                    break;
                }
                $delay = max($delay, (int) $retryAfter * 1000);
            } elseif (! $response->serverError() && $response->status() !== 408) {
                // Includes IP bans (418), access restrictions and redirects.
                break;
            }
            Sleep::for($delay)->milliseconds();
        }

        // Do not leak raw remote payloads, URLs or transport exception chains.
        throw new RuntimeException('Binance public market data unavailable.');
    }

    private static function symbol(mixed $symbol): string
    {
        if (! is_string($symbol) || ! preg_match('/^[A-Z0-9]{4,32}$/D', $symbol)) {
            throw new InvalidArgumentException('Unsupported market symbol.');
        }

        return $symbol;
    }

    private static function interval(string $interval): string
    {
        $allowed = ['1m', '3m', '5m', '15m', '30m', '1h', '2h', '4h', '6h', '8h', '12h', '1d'];
        if (! in_array($interval, $allowed, true)) {
            throw new InvalidArgumentException('Unsupported Binance interval.');
        }

        return $interval;
    }

    private static function timestamp(mixed $value): int
    {
        if ((! is_int($value) && ! (is_string($value) && ctype_digit($value))) || (int) $value < 0 || (int) $value > 9999999999999) {
            throw new InvalidArgumentException('Invalid Binance UTC timestamp.');
        }

        return (int) $value;
    }

    private static function decimal(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value)) {
            throw new RuntimeException('Malformed decimal value in Binance public data.');
        }

        return $value;
    }

    /** Decimal-string comparison; does not lose precision for large or tiny prices. */
    private static function compareUnsignedDecimal(string $left, string $right): int
    {
        [$leftInteger, $leftFraction] = array_pad(explode('.', $left, 2), 2, '');
        [$rightInteger, $rightFraction] = array_pad(explode('.', $right, 2), 2, '');
        $integerLength = strlen($leftInteger) <=> strlen($rightInteger);
        if ($integerLength !== 0) {
            return $integerLength;
        }
        $integerComparison = strcmp($leftInteger, $rightInteger);
        if ($integerComparison !== 0) {
            return $integerComparison <=> 0;
        }
        $precision = max(strlen($leftFraction), strlen($rightFraction));

        return strcmp(str_pad($leftFraction, $precision, '0'), str_pad($rightFraction, $precision, '0')) <=> 0;
    }

    private static function isPositive(string $value): bool
    {
        return ! str_starts_with($value, '-') && preg_match('/[1-9]/', $value) === 1;
    }

    private static function positiveDecimal(mixed $value, string $field): string
    {
        $number = self::decimal($value);
        if (! self::isPositive($number)) {
            throw new RuntimeException('Invalid Binance '.$field.'.');
        }

        return $number;
    }

    private static function nonnegativeDecimal(mixed $value, string $field): string
    {
        $number = self::decimal($value);
        if (str_starts_with($number, '-')) {
            throw new RuntimeException('Invalid Binance '.$field.'.');
        }

        return $number;
    }

    private static function utc(int $timestampMs): string
    {
        return gmdate('Y-m-d\TH:i:s', intdiv($timestampMs, 1000)).sprintf('.%03dZ', $timestampMs % 1000);
    }
}
