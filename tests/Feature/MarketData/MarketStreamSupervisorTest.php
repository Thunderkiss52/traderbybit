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
use App\MarketData\Infrastructure\Http\ExponentialJitterBackoff;
use App\MarketData\Infrastructure\Persistence\EloquentCandleStore;
use App\PaperTrading\Application\PaperAccountService;
use App\Trading\Application\TradingPipelineEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Psr\Log\NullLogger;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use RuntimeException;
use Tests\TestCase;

final class MarketStreamSupervisorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('market_candles')->where('symbol', 'TESTUSDT')->delete();
        DB::table('trading_pipeline_events')->where('symbol', 'TESTUSDT')->delete();
    }

    protected function tearDown(): void
    {
        DB::table('market_candles')->where('symbol', 'TESTUSDT')->delete();
        DB::table('trading_pipeline_events')->where('symbol', 'TESTUSDT')->delete();
        parent::tearDown();
    }

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

    public function test_repeated_empty_read_terminates_receive_loop_without_busy_loop_on_same_socket(): void
    {
        $deadSocket = new TrackingConnection(
            throwOnReceive: new RuntimeException('Empty read; connection dead?')
        );
        $recoveredSocket = new TrackingConnection(
            onReceiveCallback: function (TrackingConnection $conn, MarketStreamSupervisor $sup): ?string {
                $sup->requestStop();

                return null;
            }
        );

        $connector = new SequenceConnector([$deadSocket, $recoveredSocket]);
        $store = new EloquentCandleStore;
        $sleeper = new NoopSleeper;
        $client = new CountingMarketClient;

        $supervisor = new MarketStreamSupervisor(
            $connector,
            new ClosedCandleIngestor(new BybitWebSocketMessageParser, $store),
            new CandleSynchronizer($client, $store, new GapDetector, 0, 10),
            $store,
            new ImmediateBackoff,
            $sleeper,
            new NullLogger,
            'wss://example.test/v5/public/linear',
            ['BTCUSDT'],
            ['5'],
            10,
            5,
            30,
        );

        $recoveredSocket->setSupervisor($supervisor);
        $supervisor->run(5);

        // A dead socket must cause the receive loop to terminate immediately.
        // It must NOT be read repeatedly (559 times) in an infinite loop.
        self::assertSame(1, $deadSocket->receiveCount, 'Dead socket was read repeatedly instead of terminating receive loop.');
        self::assertTrue($deadSocket->closed, 'Dead socket was not closed.');
        self::assertSame(1, $deadSocket->closeCount);

        // Supervisor successfully reconnected with a brand new socket
        self::assertSame(2, $connector->connectionsCreated);
        self::assertGreaterThanOrEqual(1, $recoveredSocket->receiveCount);
    }

    public function test_dead_socket_detection_followed_by_cleanup(): void
    {
        $deadSocket = new TrackingConnection(
            throwOnReceive: new RuntimeException('WebSocket connection closed by peer (EOF).')
        );
        $connector = new SequenceConnector([$deadSocket]);
        $store = new EloquentCandleStore;
        $sleeper = new NoopSleeper;
        $client = new CountingMarketClient;

        $supervisor = new MarketStreamSupervisor(
            $connector,
            new ClosedCandleIngestor(new BybitWebSocketMessageParser, $store),
            new CandleSynchronizer($client, $store, new GapDetector, 0, 10),
            $store,
            new ImmediateBackoff,
            $sleeper,
            new NullLogger,
            'wss://example.test/v5/public/linear',
            ['BTCUSDT'],
            ['5'],
            10,
            5,
            30,
        );

        $supervisor->run(1);

        self::assertTrue($deadSocket->closed, 'Dead socket was not safely cleaned up.');
        self::assertSame(1, $deadSocket->closeCount);
    }

    public function test_successful_reconnect_with_new_connection_and_automatic_resubscription(): void
    {
        $candleJson = $this->klineJson('TESTUSDT', '5', 1704067200000, '42000.0', '42500.0', '41900.0', '42400.0', '10.5', '444150.0');

        $socket1 = new TrackingConnection(throwOnReceive: new RuntimeException('Network glitch'));
        $socket2 = new TrackingConnection(responses: [$candleJson]);

        $connector = new SequenceConnector([$socket1, $socket2]);
        $store = new EloquentCandleStore;
        $sleeper = new NoopSleeper;
        $client = new CountingMarketClient;

        $supervisor = new MarketStreamSupervisor(
            $connector,
            new ClosedCandleIngestor(new BybitWebSocketMessageParser, $store),
            new CandleSynchronizer($client, $store, new GapDetector, 0, 10),
            $store,
            new ImmediateBackoff,
            $sleeper,
            new NullLogger,
            'wss://example.test/v5/public/linear',
            ['TESTUSDT'],
            ['5', '15'],
            10,
            5,
            30,
        );

        $socket2->setOnReceiveCallback(function (TrackingConnection $conn, MarketStreamSupervisor $sup): ?string {
            $sup->requestStop();

            return null;
        });
        $socket2->setSupervisor($supervisor);

        $supervisor->run(2);

        self::assertSame(2, $connector->connectionsCreated);
        self::assertTrue($socket1->closed);

        // Verify resubscription on socket2:
        self::assertGreaterThanOrEqual(2, count($socket2->sentMessages));
        $sub = json_decode($socket2->sentMessages[0], true);
        self::assertSame('subscribe', $sub['op']);
        self::assertSame(['kline.5.TESTUSDT', 'kline.15.TESTUSDT'], $sub['args']);

        // Verify candle from socket2 was persisted:
        $latest = $store->latestClosedOpenTimeMs('bybit', 'linear', 'TESTUSDT', '5');
        self::assertSame(1704067200000, $latest);
    }

    public function test_recovery_after_slow_rest_backfill(): void
    {
        $candleJson = $this->klineJson('TESTUSDT', '5', 1704070800000, '43000.0', '43100.0', '42900.0', '43050.0', '5.0', '215200.0');

        $socket = new TrackingConnection(responses: [
            json_encode(['op' => 'subscribe', 'success' => true]),
            json_encode(['op' => 'pong', 'ret_msg' => 'pong']),
            $candleJson,
        ]);

        $connector = new SequenceConnector([$socket]);
        $store = new EloquentCandleStore;
        $sleeper = new NoopSleeper;
        $client = new CountingMarketClient;

        $supervisor = new MarketStreamSupervisor(
            $connector,
            new ClosedCandleIngestor(new BybitWebSocketMessageParser, $store),
            new CandleSynchronizer($client, $store, new GapDetector, 0, 10),
            $store,
            new ImmediateBackoff,
            $sleeper,
            new NullLogger,
            'wss://example.test/v5/public/linear',
            ['TESTUSDT'],
            ['5'],
            10,
            5,
            30,
        );

        $socket->setOnReceiveCallback(function (TrackingConnection $conn, MarketStreamSupervisor $sup): ?string {
            $sup->requestStop();

            return null;
        });
        $socket->setSupervisor($supervisor);

        $supervisor->run(1);

        // Verified heartbeats were maintained and candle was ingested
        self::assertGreaterThanOrEqual(2, count($socket->sentMessages));
        $latest = $store->latestClosedOpenTimeMs('bybit', 'linear', 'TESTUSDT', '5');
        self::assertSame(1704070800000, $latest);
    }

    public function test_ping_pong_timeout_detects_dead_socket_and_reconnects(): void
    {
        // Socket 1 sends no pong and no messages; heartbeat timeout occurs
        $frozenSocket = new TrackingConnection(
            heartbeatTimeoutSeconds: 0, // times out on ping check
            onReceiveCallback: fn () => null
        );

        $recoveredSocket = new TrackingConnection(
            onReceiveCallback: function (TrackingConnection $conn, MarketStreamSupervisor $sup): ?string {
                $sup->requestStop();

                return null;
            }
        );

        $connector = new SequenceConnector([$frozenSocket, $recoveredSocket]);
        $store = new EloquentCandleStore;
        $sleeper = new NoopSleeper;
        $client = new CountingMarketClient;

        $supervisor = new MarketStreamSupervisor(
            $connector,
            new ClosedCandleIngestor(new BybitWebSocketMessageParser, $store),
            new CandleSynchronizer($client, $store, new GapDetector, 0, 10),
            $store,
            new ImmediateBackoff,
            $sleeper,
            new NullLogger,
            'wss://example.test/v5/public/linear',
            ['BTCUSDT'],
            ['5'],
            0, // Trigger heartbeat immediately
            5,
            30,
            null,
            0, // Heartbeat pong timeout 0s for instant test
        );

        $recoveredSocket->setSupervisor($supervisor);
        $supervisor->run(2);

        self::assertSame(2, $connector->connectionsCreated);
        self::assertTrue($frozenSocket->closed);
    }

    public function test_network_outages_with_bounded_backoff_and_no_busy_loop(): void
    {
        $connector = new FailingConnector;
        $client = new CountingMarketClient;
        $store = new EloquentCandleStore;
        $sleeper = new NoopSleeper;

        $deterministicBackoff = new ExponentialJitterBackoff(
            baseMilliseconds: 250,
            maxMilliseconds: 5000,
            randomizer: new Randomizer(new Xoshiro256StarStar(12345))
        );

        $supervisor = new MarketStreamSupervisor(
            $connector,
            new ClosedCandleIngestor(new BybitWebSocketMessageParser, $store),
            new CandleSynchronizer($client, $store, new GapDetector, 0, 10),
            $store,
            $deterministicBackoff,
            $sleeper,
            new NullLogger,
            'wss://example.test/v5/public/linear',
            ['BTCUSDT'],
            ['5'],
            20,
            5,
            30, // max reconnect seconds = 30s (30000ms)
        );

        $supervisor->run(5);

        self::assertSame(5, $connector->connections);
        self::assertCount(4, $sleeper->delays);

        // Every delay must be positive (no busy-loop / CPU spin) and bounded
        foreach ($sleeper->delays as $delay) {
            self::assertGreaterThan(0, $delay, 'Backoff delay was 0; busy-loop detected.');
            self::assertLessThanOrEqual(30000, $delay, 'Backoff exceeded reconnectMaxSeconds cap.');
        }
    }

    public function test_repeated_disconnect_and_reconnect_cycles(): void
    {
        $sockets = [
            new TrackingConnection(throwOnReceive: new RuntimeException('Drop 1')),
            new TrackingConnection(throwOnReceive: new RuntimeException('Drop 2')),
            new TrackingConnection(throwOnReceive: new RuntimeException('Drop 3')),
            new TrackingConnection(onReceiveCallback: function (TrackingConnection $conn, MarketStreamSupervisor $sup): ?string {
                $sup->requestStop();

                return null;
            }),
        ];

        $connector = new SequenceConnector($sockets);
        $store = new EloquentCandleStore;
        $sleeper = new NoopSleeper;
        $client = new CountingMarketClient;

        $supervisor = new MarketStreamSupervisor(
            $connector,
            new ClosedCandleIngestor(new BybitWebSocketMessageParser, $store),
            new CandleSynchronizer($client, $store, new GapDetector, 0, 10),
            $store,
            new ImmediateBackoff,
            $sleeper,
            new NullLogger,
            'wss://example.test/v5/public/linear',
            ['BTCUSDT'],
            ['5'],
            10,
            5,
            30,
        );

        $sockets[3]->setSupervisor($supervisor);
        $supervisor->run(4);

        self::assertSame(4, $connector->connectionsCreated);
        self::assertTrue($sockets[0]->closed);
        self::assertTrue($sockets[1]->closed);
        self::assertTrue($sockets[2]->closed);
        self::assertTrue($sockets[3]->closed);
    }

    public function test_duplicate_candle_replay_is_idempotent(): void
    {
        $store = new EloquentCandleStore;
        $pipelineService = app(TradingPipelineEventService::class);
        $ingestor = new ClosedCandleIngestor(
            new BybitWebSocketMessageParser,
            $store,
            null,
            new NullLogger,
            $pipelineService
        );

        $candleJson = $this->klineJson('TESTUSDT', '5', 1704080000000, '42000.0', '42500.0', '41900.0', '42400.0', '10.5', '444150.0');

        // First ingestion:
        $first = $ingestor->ingest($candleJson);
        self::assertTrue($first);

        $candleCountFirst = DB::table('market_candles')
            ->where('exchange', 'bybit')
            ->where('symbol', 'TESTUSDT')
            ->where('interval', '5')
            ->count();
        self::assertSame(1, $candleCountFirst);

        $pipelineEventsFirst = DB::table('trading_pipeline_events')
            ->where('exchange', 'bybit')
            ->where('symbol', 'TESTUSDT')
            ->where('timeframe', '5')
            ->count();
        self::assertSame(1, $pipelineEventsFirst);

        // Replay identical candle:
        $second = $ingestor->ingest($candleJson);
        self::assertTrue($second);

        $candleCountSecond = DB::table('market_candles')
            ->where('exchange', 'bybit')
            ->where('symbol', 'TESTUSDT')
            ->where('interval', '5')
            ->count();
        self::assertSame(1, $candleCountSecond, 'Duplicate candle produced multiple database rows.');

        $pipelineEventsSecond = DB::table('trading_pipeline_events')
            ->where('exchange', 'bybit')
            ->where('symbol', 'TESTUSDT')
            ->where('timeframe', '5')
            ->count();
        self::assertSame(1, $pipelineEventsSecond, 'Duplicate candle produced multiple pipeline events.');
    }

    public function test_graceful_shutdown_during_backoff(): void
    {
        $socket = new TrackingConnection(throwOnReceive: new RuntimeException('Disconnect to trigger backoff'));
        $connector = new SequenceConnector([$socket]);
        $store = new EloquentCandleStore;
        $client = new CountingMarketClient;

        $stoppingSleeper = new class implements Sleeper
        {
            public ?MarketStreamSupervisor $supervisor = null;

            public int $sleepCount = 0;

            public function sleepMilliseconds(int $milliseconds): void
            {
                $this->sleepCount++;
                $this->supervisor?->requestStop();
            }
        };

        $supervisor = new MarketStreamSupervisor(
            $connector,
            new ClosedCandleIngestor(new BybitWebSocketMessageParser, $store),
            new CandleSynchronizer($client, $store, new GapDetector, 0, 10),
            $store,
            new ImmediateBackoff,
            $stoppingSleeper,
            new NullLogger,
            'wss://example.test/v5/public/linear',
            ['BTCUSDT'],
            ['5'],
            10,
            5,
            30,
        );

        $stoppingSleeper->supervisor = $supervisor;

        // Run with limit of 10 cycles, but requestStop is called during first backoff sleep:
        $supervisor->run(10);

        self::assertSame(1, $connector->connectionsCreated);
        self::assertSame(1, $stoppingSleeper->sleepCount);
    }

    public function test_preserves_bybit_paper_accounts_and_financial_invariants(): void
    {
        $accountService = app(PaperAccountService::class);
        $accounts = $accountService->allPrimaryAccounts('bybit');

        // Verify all 13 canonical Bybit PRIMARY accounts exist
        self::assertCount(13, $accounts);

        foreach ($accounts as $account) {
            self::assertTrue((bool) $account->is_primary);
            self::assertSame('ACTIVE', $account->status);
            self::assertStringStartsWith('10000', (string) $account->starting_balance);
            self::assertStringStartsWith('10000', (string) $account->current_equity);
        }
    }

    private function klineJson(
        string $symbol,
        string $interval,
        int $startMs,
        string $open,
        string $high,
        string $low,
        string $close,
        string $volume,
        string $turnover
    ): string {
        return json_encode([
            'topic' => "kline.{$interval}.{$symbol}",
            'type' => 'snapshot',
            'ts' => $startMs + 300000,
            'data' => [[
                'start' => $startMs,
                'end' => $startMs + 299999,
                'interval' => $interval,
                'open' => $open,
                'high' => $high,
                'low' => $low,
                'close' => $close,
                'volume' => $volume,
                'turnover' => $turnover,
                'confirm' => true,
                'timestamp' => $startMs + 299000,
            ]],
        ], JSON_THROW_ON_ERROR);
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

final class SequenceConnector implements WebSocketConnector
{
    public int $connectionsCreated = 0;

    /** @param list<WebSocketConnection> $connections */
    public function __construct(private array $connections) {}

    public function connect(string $url): WebSocketConnection
    {
        $this->connectionsCreated++;
        if ($this->connections === []) {
            throw new RuntimeException('No more connections in SequenceConnector.');
        }

        return array_shift($this->connections);
    }
}

final class TrackingConnection implements WebSocketConnection
{
    /** @var list<string> */
    public array $sentMessages = [];

    public int $receiveCount = 0;

    public int $closeCount = 0;

    public bool $closed = false;

    public bool $connected = true;

    private ?MarketStreamSupervisor $supervisor = null;

    /** @var null|callable(TrackingConnection, MarketStreamSupervisor): ?string */
    private $onReceiveCallback = null;

    /**
     * @param  list<string>  $responses
     * @param  null|callable(TrackingConnection, MarketStreamSupervisor): ?string  $onReceiveCallback
     */
    public function __construct(
        public array $responses = [],
        public ?RuntimeException $throwOnReceive = null,
        public int $heartbeatTimeoutSeconds = 15,
        ?callable $onReceiveCallback = null,
    ) {
        $this->onReceiveCallback = $onReceiveCallback;
    }

    public function setSupervisor(MarketStreamSupervisor $supervisor): void
    {
        $this->supervisor = $supervisor;
    }

    public function setOnReceiveCallback(callable $callback): void
    {
        $this->onReceiveCallback = $callback;
    }

    public function send(string $payload): void
    {
        $this->sentMessages[] = $payload;
    }

    public function receive(): ?string
    {
        $this->receiveCount++;

        if ($this->throwOnReceive !== null) {
            throw $this->throwOnReceive;
        }

        if ($this->responses !== []) {
            return array_shift($this->responses);
        }

        if ($this->onReceiveCallback !== null && $this->supervisor !== null) {
            $cb = $this->onReceiveCallback;

            return $cb($this, $this->supervisor);
        }

        return null;
    }

    public function close(): void
    {
        $this->closeCount++;
        $this->closed = true;
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected;
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

    public function isConnected(): bool
    {
        return true;
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

    public function isConnected(): bool
    {
        return true;
    }
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
