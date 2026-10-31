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

    public function test_it_sends_subscription_and_proactive_heartbeat_on_connect(): void
    {
        $connection = new RecordingConnection;
        $connector = new FixedConnector($connection);
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
            10,
            5,
            30,
        );

        $supervisor->run(1);

        self::assertGreaterThanOrEqual(2, count($connection->sentMessages));
        $subscribeMessage = json_decode($connection->sentMessages[0], true);
        self::assertSame('subscribe', $subscribeMessage['op']);
        self::assertSame(['kline.5.BTCUSDT'], $subscribeMessage['args']);

        $heartbeatMessage = json_decode($connection->sentMessages[1], true);
        self::assertSame('ping', $heartbeatMessage['op']);
        self::assertStringStartsWith('heartbeat-init-', (string) $heartbeatMessage['req_id']);
    }
}

final class FixedConnector implements WebSocketConnector
{
    public function __construct(private WebSocketConnection $connection) {}

    public function connect(string $url): WebSocketConnection
    {
        return $this->connection;
    }
}

final class RecordingConnection implements WebSocketConnection
{
    /** @var list<string> */
    public array $sentMessages = [];

    public function send(string $payload): void
    {
        $this->sentMessages[] = $payload;
    }

    public function receive(): ?string
    {
        throw new RuntimeException('stop-after-first-cycle');
    }

    public function close(): void {}
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
