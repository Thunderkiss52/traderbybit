<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData;

use App\MarketData\Application\CandleSynchronizer;
use App\MarketData\Application\ClosedCandleIngestor;
use App\MarketData\Application\GapDetector;
use App\MarketData\Application\MarketStreamSupervisor;
use App\MarketData\Contracts\Backoff;
use App\MarketData\Contracts\BybitMarketDataClient;
use App\MarketData\Contracts\Sleeper;
use App\MarketData\Contracts\WebSocketConnection;
use App\MarketData\Contracts\WebSocketConnector;
use App\MarketData\Domain\CandleQuery;
use App\MarketData\Infrastructure\Bybit\BybitWebSocketMessageParser;
use App\MarketData\Infrastructure\Persistence\EloquentCandleStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class MarketStreamSupervisorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reconnects_and_runs_rest_recovery_each_time(): void
    {
        $connector = new FailingConnector;
        $client = new CountingMarketClient;
        $store = new EloquentCandleStore;
        $sleeper = new NoopSleeper;
        $supervisor = new MarketStreamSupervisor(
            $connector,
            new ClosedCandleIngestor(new BybitWebSocketMessageParser, $store),
            new CandleSynchronizer($client, $store, new GapDetector, 0, 10),
            $store,
            new ImmediateBackoff,
            $sleeper,
            app('log'),
            'wss://example.test/v5/public/linear',
            ['BTCUSDT'],
            ['5'],
            20,
            5,
            30,
        );

        $supervisor->run(2);

        self::assertSame(2, $connector->connections);
        self::assertSame(2, $client->candleRequests);
        self::assertSame([1], $sleeper->delays);
    }
}

final class FailingConnector implements WebSocketConnector
{
    public int $connections = 0;

    public function connect(string $url): WebSocketConnection
    {
        $this->connections++;

        return new FailingConnection;
    }
}

final class FailingConnection implements WebSocketConnection
{
    public function send(string $payload): void {}

    public function receive(): ?string
    {
        throw new RuntimeException('disconnected');
    }

    public function close(): void {}
}

final class CountingMarketClient implements BybitMarketDataClient
{
    public int $candleRequests = 0;

    public function candles(CandleQuery $query): array
    {
        $this->candleRequests++;

        return [];
    }

    public function ticker(string $category, string $symbol): array
    {
        return [];
    }

    public function orderBook(string $category, string $symbol, int $limit = 50): array
    {
        return [];
    }

    public function fundingRates(string $category, string $symbol, ?int $fromMs = null, ?int $toMs = null): array
    {
        return [];
    }

    public function openInterest(string $category, string $symbol, string $intervalTime, ?string $cursor = null): array
    {
        return [];
    }
}

final class ImmediateBackoff implements Backoff
{
    public function milliseconds(int $retryNumber): int
    {
        return 1;
    }
}

final class NoopSleeper implements Sleeper
{
    public array $delays = [];

    public function sleepMilliseconds(int $milliseconds): void
    {
        $this->delays[] = $milliseconds;
    }
}
