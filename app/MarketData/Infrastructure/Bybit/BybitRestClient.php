<?php

declare(strict_types=1);

namespace App\MarketData\Infrastructure\Bybit;

use App\MarketData\Contracts\Backoff;
use App\MarketData\Contracts\BybitMarketDataClient;
use App\MarketData\Contracts\HttpTransport;
use App\MarketData\Contracts\InstrumentMetadataProvider;
use App\MarketData\Contracts\Sleeper;
use App\MarketData\Domain\Candle;
use App\MarketData\Domain\CandleQuery;
use App\MarketData\Exceptions\BybitApiException;
use App\MarketData\Exceptions\TransportException;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class BybitRestClient implements BybitMarketDataClient, InstrumentMetadataProvider
{
    public function __construct(
        private HttpTransport $transport,
        private Backoff $backoff,
        private Sleeper $sleeper,
        private LoggerInterface $log,
        private int $maxRetries,
        private int $pageSize,
    ) {}

    public function candles(CandleQuery $query): array
    {
        $byOpenTime = [];
        $pageEnd = $query->toMs - 1;

        while ($pageEnd >= $query->fromMs) {
            $payload = $this->request('/v5/market/kline', [
                'category' => $query->category,
                'symbol' => $query->symbol,
                'interval' => $query->interval->value,
                'start' => $query->fromMs,
                'end' => $pageEnd,
                'limit' => $this->pageSize,
            ]);

            $result = $this->requireArray($payload, 'result');
            $rows = $this->requireList($result, 'list');
            if ($rows === []) {
                break;
            }

            $serverTimeMs = $this->requireIntegerLike($payload, 'time');
            $oldest = PHP_INT_MAX;

            foreach ($rows as $index => $row) {
                if (! is_array($row) || count($row) < 7) {
                    throw new BybitApiException("Malformed kline row at index {$index}.");
                }

                $openTimeMs = $this->integerLike($row[0] ?? null, "result.list.{$index}.0");
                $oldest = min($oldest, $openTimeMs);

                if ($openTimeMs < $query->fromMs || $openTimeMs >= $query->toMs) {
                    continue;
                }

                $candle = new Candle(
                    exchange: 'bybit',
                    category: $this->requireString($result, 'category'),
                    symbol: $this->requireString($result, 'symbol'),
                    interval: $query->interval,
                    openTimeMs: $openTimeMs,
                    open: $this->decimal($row[1] ?? null, "result.list.{$index}.1"),
                    high: $this->decimal($row[2] ?? null, "result.list.{$index}.2"),
                    low: $this->decimal($row[3] ?? null, "result.list.{$index}.3"),
                    close: $this->decimal($row[4] ?? null, "result.list.{$index}.4"),
                    volume: $this->decimal($row[5] ?? null, "result.list.{$index}.5"),
                    turnover: $this->decimal($row[6] ?? null, "result.list.{$index}.6"),
                    isClosed: $openTimeMs + $query->interval->milliseconds() <= $serverTimeMs,
                );
                $byOpenTime[$openTimeMs] = $candle;
            }

            if (count($rows) < $this->pageSize || $oldest <= $query->fromMs) {
                break;
            }

            $nextEnd = $oldest - 1;
            if ($nextEnd >= $pageEnd) {
                throw new BybitApiException('Kline pagination did not advance.');
            }
            $pageEnd = $nextEnd;
        }

        ksort($byOpenTime, SORT_NUMERIC);

        return array_values($byOpenTime);
    }

    public function ticker(string $category, string $symbol): array
    {
        $result = $this->requireArray($this->request('/v5/market/tickers', compact('category', 'symbol')), 'result');
        $list = $this->requireList($result, 'list');
        if (! isset($list[0]) || ! is_array($list[0])) {
            throw new BybitApiException('Ticker response contains no instrument.');
        }

        $ticker = $list[0];
        foreach (['symbol', 'lastPrice', 'bid1Price', 'ask1Price', 'volume24h', 'turnover24h', 'fundingRate', 'openInterest'] as $field) {
            $this->requireString($ticker, $field);
        }
        if ($ticker['symbol'] !== $symbol) {
            throw new BybitApiException('Ticker response symbol does not match the request.');
        }

        return $ticker;
    }

    public function instrumentInfo(string $category, string $symbol): array
    {
        $result = $this->requireArray($this->request('/v5/market/instruments-info', compact('category', 'symbol')), 'result');
        $list = $this->requireList($result, 'list');
        if (! isset($list[0]) || ! is_array($list[0])) {
            throw new BybitApiException('Instrument-info response contains no instrument.');
        }
        $instrument = $list[0];
        if ($this->requireString($instrument, 'symbol') !== $symbol) {
            throw new BybitApiException('Instrument-info response symbol does not match the request.');
        }
        $priceFilter = $this->requireArray($instrument, 'priceFilter');
        $this->requireString($priceFilter, 'tickSize');
        $lotSizeFilter = $this->requireArray($instrument, 'lotSizeFilter');
        foreach (['qtyStep', 'minOrderQty', 'maxOrderQty', 'minNotionalValue'] as $field) {
            $this->requireString($lotSizeFilter, $field);
        }

        return $instrument;
    }

    public function orderBook(string $category, string $symbol, int $limit = 50): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new BybitApiException('Order book limit must be between 1 and 1000.');
        }

        $result = $this->requireArray(
            $this->request('/v5/market/orderbook', compact('category', 'symbol', 'limit')),
            'result',
        );
        if ($this->requireString($result, 's') !== $symbol) {
            throw new BybitApiException('Order book response symbol does not match the request.');
        }
        $this->validateOrderBookSide($this->requireList($result, 'b'), 'b');
        $this->validateOrderBookSide($this->requireList($result, 'a'), 'a');
        $this->requireIntegerLike($result, 'ts');

        return $result;
    }

    public function fundingRates(string $category, string $symbol, ?int $fromMs = null, ?int $toMs = null): array
    {
        if ($fromMs !== null && $toMs === null) {
            throw new BybitApiException('Bybit funding history requires endTime when startTime is supplied.');
        }
        $query = ['category' => $category, 'symbol' => $symbol, 'limit' => 200];
        if ($fromMs !== null) {
            $query['startTime'] = $fromMs;
        }
        if ($toMs !== null) {
            $query['endTime'] = $toMs;
        }
        $result = $this->requireArray($this->request('/v5/market/funding/history', $query), 'result');

        $list = $this->requireList($result, 'list');
        foreach ($list as $index => $row) {
            if (! is_array($row)) {
                throw new BybitApiException("Funding history row [{$index}] must be an object.");
            }
            foreach (['symbol', 'fundingRate', 'fundingRateTimestamp'] as $field) {
                $this->requireString($row, $field);
            }
        }

        return $list;
    }

    public function openInterest(string $category, string $symbol, string $intervalTime, ?string $cursor = null): array
    {
        if (! in_array($intervalTime, ['5min', '15min', '30min', '1h', '4h', '1d'], true)) {
            throw new BybitApiException('Unsupported open-interest interval.');
        }
        $query = compact('category', 'symbol', 'intervalTime');
        $query['limit'] = 200;
        if ($cursor !== null && $cursor !== '') {
            $query['cursor'] = $cursor;
        }

        $result = $this->requireArray($this->request('/v5/market/open-interest', $query), 'result');
        $list = $this->requireList($result, 'list');
        foreach ($list as $index => $row) {
            if (! is_array($row)) {
                throw new BybitApiException("Open-interest row [{$index}] must be an object.");
            }
            $this->requireString($row, 'openInterest');
            $this->requireString($row, 'timestamp');
        }

        return $result;
    }

    /** @param array<string, scalar> $query @return array<string, mixed> */
    private function request(string $path, array $query): array
    {
        $lastException = null;

        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            try {
                $response = $this->transport->get($path, $query);
                $retCode = is_array($response->json) && isset($response->json['retCode']) && is_numeric($response->json['retCode'])
                    ? (int) $response->json['retCode']
                    : null;
                $retryable = $response->status === 429 || $response->status >= 500 || $retCode === 10006;

                if ($retryable) {
                    throw new BybitApiException(
                        message: 'Bybit rate limit or transient service error.',
                        retCode: $retCode,
                        httpStatus: $response->status,
                    );
                }
                if ($response->status < 200 || $response->status >= 300) {
                    throw new BybitApiException('Bybit returned an HTTP error.', $retCode, $response->status);
                }
                if ($response->json === null) {
                    throw new BybitApiException('Bybit returned invalid JSON.', httpStatus: $response->status);
                }
                if ($retCode === null || $retCode !== 0) {
                    $message = is_string($response->json['retMsg'] ?? null)
                        ? $response->json['retMsg']
                        : 'Unknown Bybit API error.';
                    throw new BybitApiException($message, $retCode, $response->status);
                }

                return $response->json;
            } catch (TransportException|BybitApiException $exception) {
                $lastException = $exception;
                $retryable = $exception instanceof TransportException
                    || $exception->retCode === 10006
                    || $exception->httpStatus === 429
                    || ($exception->httpStatus !== null && $exception->httpStatus >= 500);

                if (! $retryable || $attempt >= $this->maxRetries) {
                    throw $exception;
                }

                $delay = $this->backoff->milliseconds($attempt + 1);
                $this->log->warning('Retrying public Bybit market-data request.', [
                    'endpoint' => $path,
                    'attempt' => $attempt + 1,
                    'delay_ms' => $delay,
                    'http_status' => $exception instanceof BybitApiException ? $exception->httpStatus : null,
                    'ret_code' => $exception instanceof BybitApiException ? $exception->retCode : null,
                ]);
                $this->sleeper->sleepMilliseconds($delay);
            } catch (Throwable $exception) {
                throw new BybitApiException('Unexpected Bybit response processing error.', previous: $exception);
            }
        }

        throw $lastException ?? new BybitApiException('Bybit request failed.');
    }

    /** @param array<string, mixed> $source @return array<string, mixed> */
    private function requireArray(array $source, string $key): array
    {
        $value = $source[$key] ?? null;
        if (! is_array($value)) {
            throw new BybitApiException("Bybit response field [{$key}] must be an object.");
        }

        return $value;
    }

    /** @param array<string, mixed> $source @return list<mixed> */
    private function requireList(array $source, string $key): array
    {
        $value = $source[$key] ?? null;
        if (! is_array($value) || ! array_is_list($value)) {
            throw new BybitApiException("Bybit response field [{$key}] must be a list.");
        }

        return $value;
    }

    /** @param array<string, mixed> $source */
    private function requireString(array $source, string $key): string
    {
        $value = $source[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new BybitApiException("Bybit response field [{$key}] must be a non-empty string.");
        }

        return $value;
    }

    /** @param array<string, mixed> $source */
    private function requireIntegerLike(array $source, string $key): int
    {
        return $this->integerLike($source[$key] ?? null, $key);
    }

    private function integerLike(mixed $value, string $path): int
    {
        if ((! is_int($value) && ! is_string($value)) || ! ctype_digit((string) $value)) {
            throw new BybitApiException("Bybit response field [{$path}] must be an integer.");
        }

        return (int) $value;
    }

    private function decimal(mixed $value, string $path): string
    {
        if (! is_string($value) || ! preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $value)) {
            throw new BybitApiException("Bybit response field [{$path}] must be a decimal string.");
        }

        return $value;
    }

    /** @param list<mixed> $levels */
    private function validateOrderBookSide(array $levels, string $side): void
    {
        foreach ($levels as $index => $level) {
            if (! is_array($level) || count($level) !== 2) {
                throw new BybitApiException("Order book [{$side}.{$index}] must contain price and size.");
            }
            $this->decimal($level[0] ?? null, "result.{$side}.{$index}.0");
            $this->decimal($level[1] ?? null, "result.{$side}.{$index}.1");
        }
    }
}
