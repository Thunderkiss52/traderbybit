<?php

declare(strict_types=1);

namespace Tests\Unit\MarketData;

use App\MarketData\Contracts\Backoff;
use App\MarketData\Contracts\HttpTransport;
use App\MarketData\Contracts\Sleeper;
use App\MarketData\Domain\CandleQuery;
use App\MarketData\Domain\Interval;
use App\MarketData\Exceptions\BybitApiException;
use App\MarketData\Exceptions\TransportException;
use App\MarketData\Infrastructure\Bybit\BybitRestClient;
use App\MarketData\Infrastructure\Http\TransportResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class BybitRestClientTest extends TestCase
{
    public function test_it_parses_sorts_utc_and_marks_only_closed_rest_candles(): void
    {
        $transport = new SequenceTransport([
            $this->response([
                ['1704067500000', '11', '13', '10', '12', '2', '24'],
                ['1704067200000', '10', '12', '9', '11', '1', '11'],
            ], 1704067799999),
        ]);
        $client = $this->client($transport, pageSize: 1000);

        $candles = $client->candles(new CandleQuery('linear', 'BTCUSDT', Interval::FiveMinutes, 1704067200000, 1704067800000));

        self::assertCount(2, $candles);
        self::assertSame(1704067200000, $candles[0]->openTimeMs());
        self::assertSame('+00:00', $candles[0]->openTime->format('P'));
        self::assertTrue($candles[0]->isClosed);
        self::assertFalse($candles[1]->isClosed);
    }

    public function test_it_paginates_backwards_and_deduplicates_rows(): void
    {
        $transport = new SequenceTransport([
            $this->response([
                ['1704067800000', '12', '14', '11', '13', '1', '13'],
                ['1704067500000', '11', '13', '10', '12', '1', '12'],
            ], 1704070000000),
            $this->response([
                ['1704067500000', '11', '13', '10', '12', '1', '12'],
                ['1704067200000', '10', '12', '9', '11', '1', '11'],
            ], 1704070000000),
        ]);
        $client = $this->client($transport, pageSize: 2);

        $candles = $client->candles(new CandleQuery('linear', 'BTCUSDT', Interval::FiveMinutes, 1704067200000, 1704068100000));

        self::assertSame([1704067200000, 1704067500000, 1704067800000], array_map(fn ($c) => $c->openTimeMs(), $candles));
        self::assertCount(2, $transport->queries);
        self::assertSame(1704067499999, $transport->queries[1]['end']);
    }

    #[DataProvider('retryableResponses')]
    public function test_it_retries_rate_limits(TransportResponse $limited): void
    {
        $transport = new SequenceTransport([
            $limited,
            new TransportResponse(200, [
                'retCode' => 0,
                'retMsg' => 'OK',
                'result' => ['category' => 'linear', 'list' => [[
                    'symbol' => 'BTCUSDT',
                    'lastPrice' => '1',
                    'bid1Price' => '1',
                    'ask1Price' => '1',
                    'volume24h' => '1',
                    'turnover24h' => '1',
                    'fundingRate' => '0.0001',
                    'openInterest' => '1',
                ]]],
                'time' => 1704070000000,
            ]),
        ]);
        $sleeper = new RecordingSleeper;

        $ticker = $this->client($transport, $sleeper)->ticker('linear', 'BTCUSDT');

        self::assertSame('BTCUSDT', $ticker['symbol']);
        self::assertSame([25], $sleeper->delays);
    }

    public static function retryableResponses(): array
    {
        return [
            'HTTP 429' => [new TransportResponse(429, ['retCode' => 0, 'retMsg' => 'OK'])],
            'retCode 10006' => [new TransportResponse(200, ['retCode' => 10006, 'retMsg' => 'Too many visits!'])],
        ];
    }

    public function test_it_rejects_incomplete_api_payload(): void
    {
        $client = $this->client(new SequenceTransport([
            new TransportResponse(200, ['retCode' => 0, 'retMsg' => 'OK', 'result' => ['category' => 'linear'], 'time' => 1]),
        ]));

        $this->expectException(BybitApiException::class);
        $client->candles(new CandleQuery('linear', 'BTCUSDT', Interval::FiveMinutes, 1, 301000));
    }

    public function test_it_reads_tick_size_from_instrument_info(): void
    {
        $transport = new SequenceTransport([new TransportResponse(200, [
            'retCode' => 0, 'retMsg' => 'OK',
            'result' => ['category' => 'linear', 'list' => [[
                'symbol' => 'BTCUSDT', 'priceFilter' => ['tickSize' => '0.10'],
                'lotSizeFilter' => ['qtyStep' => '0.001', 'minOrderQty' => '0.001', 'maxOrderQty' => '100', 'minNotionalValue' => '5'],
            ]]],
            'time' => 1,
        ])]);

        $instrument = $this->client($transport)->instrumentInfo('linear', 'BTCUSDT');

        self::assertSame('0.10', $instrument['priceFilter']['tickSize']);
    }

    public function test_it_retries_a_network_transport_failure(): void
    {
        $transport = new SequenceTransport([
            new TransportException('connection reset'),
            $this->response([], 1704070000000),
        ]);
        $sleeper = new RecordingSleeper;

        self::assertSame([], $this->client($transport, $sleeper)->candles(
            new CandleQuery('linear', 'BTCUSDT', Interval::FiveMinutes, 1704067200000, 1704067500000),
        ));
        self::assertSame([25], $sleeper->delays);
    }

    private function client(HttpTransport $transport, ?Sleeper $sleeper = null, int $pageSize = 1000): BybitRestClient
    {
        return new BybitRestClient(
            $transport,
            new FixedBackoff,
            $sleeper ?? new RecordingSleeper,
            new NullLogger,
            maxRetries: 2,
            pageSize: $pageSize,
        );
    }

    /** @param list<list<string>> $rows */
    private function response(array $rows, int $time): TransportResponse
    {
        return new TransportResponse(200, [
            'retCode' => 0,
            'retMsg' => 'OK',
            'result' => ['category' => 'linear', 'symbol' => 'BTCUSDT', 'list' => $rows],
            'time' => $time,
        ]);
    }
}

final class SequenceTransport implements HttpTransport
{
    /** @var list<array<string, scalar>> */
    public array $queries = [];

    /** @param list<TransportResponse|TransportException> $responses */
    public function __construct(private array $responses) {}

    public function get(string $path, array $query): TransportResponse
    {
        $this->queries[] = $query;
        $response = array_shift($this->responses);
        if ($response instanceof TransportException) {
            throw $response;
        }
        if ($response === null) {
            throw new \RuntimeException('Unexpected HTTP request.');
        }

        return $response;
    }
}

final class FixedBackoff implements Backoff
{
    public function milliseconds(int $retryNumber): int
    {
        return 25;
    }
}

final class RecordingSleeper implements Sleeper
{
    /** @var list<int> */
    public array $delays = [];

    public function sleepMilliseconds(int $milliseconds): void
    {
        $this->delays[] = $milliseconds;
    }
}
