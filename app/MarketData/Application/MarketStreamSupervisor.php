<?php

declare(strict_types=1);

namespace App\MarketData\Application;

use App\MarketData\Contracts\Backoff;
use App\MarketData\Contracts\CandleStore;
use App\MarketData\Contracts\Sleeper;
use App\MarketData\Contracts\WebSocketConnection;
use App\MarketData\Contracts\WebSocketConnector;
use App\MarketData\Domain\CandleQuery;
use App\MarketData\Domain\Interval;
use App\QuoteIntegrity\Application\QimGapRecorder;
use Carbon\CarbonImmutable;
use JsonException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class MarketStreamSupervisor
{
    private bool $stopping = false;

    /** @param list<string> $symbols @param list<string> $intervals */
    public function __construct(
        private readonly WebSocketConnector $connector,
        private readonly ClosedCandleIngestor $ingestor,
        private readonly CandleSynchronizer $synchronizer,
        private readonly CandleStore $store,
        private readonly Backoff $backoff,
        private readonly Sleeper $sleeper,
        private readonly LoggerInterface $log,
        private readonly string $url,
        private readonly array $symbols,
        private readonly array $intervals,
        private readonly int $heartbeatSeconds,
        private readonly int $recoveryLookbackMinutes,
        private readonly int $reconnectMaxSeconds,
        private readonly ?QimGapRecorder $gapRecorder = null,
        private readonly ?int $heartbeatTimeoutSeconds = null,
    ) {}

    public function requestStop(): void
    {
        $this->stopping = true;
    }

    public function run(int $maxConnectionCycles = PHP_INT_MAX): void
    {
        $this->installSignalHandlers();
        $cycle = 0;

        while (! $this->stopping && $cycle < $maxConnectionCycles) {
            $connection = null;
            $connectStartedAt = microtime(true);

            $this->log->info('Bybit public WebSocket connecting.', [
                'cycle' => $cycle + 1,
                'url' => $this->url,
                'symbols' => $this->symbols,
                'intervals' => $this->intervals,
            ]);

            try {
                $connection = $this->connector->connect($this->url);
                $this->log->info('Bybit public WebSocket connected.', [
                    'cycle' => $cycle + 1,
                    'connect_duration_ms' => (int) ((microtime(true) - $connectStartedAt) * 1000),
                ]);

                $this->subscribe($connection);
                $lastHeartbeat = time();

                // Proactively recover missed candles while maintaining WebSocket heartbeats and frame ingestion
                $this->recoverMissedCandles($connection, $lastHeartbeat);

                $this->consume($connection, $lastHeartbeat);
            } catch (Throwable $exception) {
                if (! $this->stopping) {
                    $this->log->warning('Bybit public WebSocket disconnected; reconnecting.', [
                        'exception' => $exception::class,
                        'message' => $exception->getMessage(),
                        'cycle' => $cycle + 1,
                    ]);
                }
            } finally {
                try {
                    $connection?->close();
                } catch (Throwable $closeException) {
                    $this->log->debug('WebSocket close failed after disconnect.', [
                        'exception' => $closeException::class,
                        'message' => $closeException->getMessage(),
                    ]);
                }
            }

            $cycle++;
            if (! $this->stopping && $cycle < $maxConnectionCycles) {
                $backoffMs = min(
                    $this->reconnectMaxSeconds * 1000,
                    $this->backoff->milliseconds($cycle),
                );

                $this->log->info('Bybit public WebSocket awaiting reconnect backoff.', [
                    'cycle' => $cycle,
                    'backoff_ms' => $backoffMs,
                ]);

                $this->sleeper->sleepMilliseconds($backoffMs);
            }
        }

        $this->log->info('Bybit public WebSocket supervisor finished.', [
            'stopping' => $this->stopping,
            'cycles_completed' => $cycle,
        ]);
    }

    private function subscribe(WebSocketConnection $connection): void
    {
        $topics = [];
        foreach ($this->symbols as $symbol) {
            foreach ($this->intervals as $interval) {
                $topics[] = "kline.{$interval}.{$symbol}";
            }
        }

        $connection->send(json_encode([
            'req_id' => 'market-data-subscribe',
            'op' => 'subscribe',
            'args' => $topics,
        ], JSON_THROW_ON_ERROR));

        // Proactively send initial heartbeat to prevent Bybit server 20s idle drop during REST gap recovery
        $now = time();
        $connection->send(json_encode([
            'req_id' => 'heartbeat-init-'.((string) $now),
            'op' => 'ping',
        ], JSON_THROW_ON_ERROR));

        $this->log->info('Bybit public WebSocket subscriptions sent.', [
            'topics_count' => count($topics),
            'topics' => $topics,
        ]);
    }

    private function consume(WebSocketConnection $connection, int $lastHeartbeat): void
    {
        $lastPingSentAt = $lastHeartbeat;
        $awaitingPong = false;
        $heartbeatTimeoutSeconds = $this->heartbeatTimeoutSeconds ?? max($this->heartbeatSeconds * 2, 15);

        while (! $this->stopping) {
            if (! $connection->isConnected()) {
                throw new RuntimeException('Bybit WebSocket connection is not connected; connection dead.');
            }

            $now = time();

            // Heartbeat cadence check
            if ($now - $lastPingSentAt >= $this->heartbeatSeconds) {
                if ($awaitingPong && ($now - $lastPingSentAt >= $heartbeatTimeoutSeconds)) {
                    throw new RuntimeException(sprintf(
                        'Bybit WebSocket heartbeat pong timeout: no response for %d seconds; connection dead.',
                        $now - $lastPingSentAt
                    ));
                }

                $connection->send(json_encode([
                    'req_id' => 'heartbeat-'.((string) $now),
                    'op' => 'ping',
                ], JSON_THROW_ON_ERROR));

                $this->log->debug('Bybit public WebSocket heartbeat ping sent.', [
                    'req_id' => 'heartbeat-'.$now,
                ]);

                $lastPingSentAt = $now;
                $awaitingPong = true;
            }

            // Check ping timeout even between ping intervals
            if ($awaitingPong && ($now - $lastPingSentAt >= $heartbeatTimeoutSeconds)) {
                throw new RuntimeException(sprintf(
                    'Bybit WebSocket heartbeat pong timeout: no response for %d seconds; connection dead.',
                    $now - $lastPingSentAt
                ));
            }

            $message = $connection->receive();
            if ($message !== null) {
                if ($this->isPongMessage($message)) {
                    $awaitingPong = false;
                    $this->log->debug('Bybit public WebSocket heartbeat pong received.');

                    continue;
                }

                if ($this->isSubscriptionMessage($message)) {
                    $awaitingPong = false;
                    $this->log->info('Bybit public WebSocket subscription confirmed.');

                    continue;
                }

                $this->ingestor->ingest($message);
            }
        }
    }

    private function recoverMissedCandles(WebSocketConnection $connection, int &$lastHeartbeat): void
    {
        $nowMs = (int) floor(microtime(true) * 1000);
        foreach ($this->symbols as $symbol) {
            foreach ($this->intervals as $value) {
                if ($this->stopping) {
                    return;
                }

                $this->maintainHeartbeat($connection, $lastHeartbeat);

                $interval = Interval::parse($value);
                $latest = $this->store->latestClosedOpenTimeMs('bybit', 'linear', $symbol, $value);
                $from = $latest === null
                    ? $nowMs - ($this->recoveryLookbackMinutes * 60_000)
                    : $latest + $interval->milliseconds();
                $from = max(0, $interval->floor($from));
                $to = $interval->floor($nowMs);

                if ($from < $to) {
                    $gapFrom = CarbonImmutable::createFromTimestampMs($from);
                    $gapTo = CarbonImmutable::createFromTimestampMs($to);
                    $detectionId = null;

                    if ($this->gapRecorder !== null) {
                        try {
                            $detectionId = $this->gapRecorder->recordGapDetected(
                                symbol: $symbol,
                                interval: $value,
                                gapFrom: $gapFrom,
                                gapTo: $gapTo,
                                channel: 'WS',
                                provider: 'bybit',
                                category: 'linear',
                            );
                        } catch (Throwable $e) {
                            $this->log->warning('QIM gap detection hook caught exception', [
                                'exception' => $e::class,
                                'message' => $e->getMessage(),
                            ]);
                        }
                    }

                    try {
                        $report = $this->synchronizer->sync(new CandleQuery('linear', $symbol, $interval, $from, $to));

                        if ($this->gapRecorder !== null && $detectionId !== null) {
                            try {
                                if ($report->missing === []) {
                                    $this->gapRecorder->recordGapRecovered(
                                        detectionId: $detectionId,
                                        symbol: $symbol,
                                        interval: $value,
                                        gapFrom: $gapFrom,
                                        gapTo: $gapTo,
                                        outcome: 'RECOVERED',
                                        remainingMissingCount: 0,
                                    );
                                } else {
                                    $this->gapRecorder->recordGapRecoveryFailed(
                                        detectionId: $detectionId,
                                        symbol: $symbol,
                                        interval: $value,
                                        gapFrom: $gapFrom,
                                        gapTo: $gapTo,
                                        outcome: 'PARTIAL',
                                        remainingMissingCount: count($report->missing),
                                    );
                                }
                            } catch (Throwable $e) {
                                $this->log->warning('QIM gap resolution hook caught exception', [
                                    'exception' => $e::class,
                                    'message' => $e->getMessage(),
                                ]);
                            }
                        }
                    } catch (Throwable $syncException) {
                        if ($this->gapRecorder !== null && $detectionId !== null) {
                            try {
                                $this->gapRecorder->recordGapRecoveryFailed(
                                    detectionId: $detectionId,
                                    symbol: $symbol,
                                    interval: $value,
                                    gapFrom: $gapFrom,
                                    gapTo: $gapTo,
                                    outcome: 'FAILED',
                                    remainingMissingCount: 0,
                                    metadata: ['error' => $syncException->getMessage()],
                                );
                            } catch (Throwable $e) {
                                $this->log->warning('QIM gap failure hook caught exception', [
                                    'exception' => $e::class,
                                    'message' => $e->getMessage(),
                                ]);
                            }
                        }
                        throw $syncException;
                    }
                }

                // Drain any frames arrived during REST recovery and maintain heartbeats
                $this->drainPendingWebSocketFrames($connection);
                $this->maintainHeartbeat($connection, $lastHeartbeat);
            }
        }
    }

    private function maintainHeartbeat(WebSocketConnection $connection, int &$lastHeartbeat): void
    {
        $now = time();
        if ($now - $lastHeartbeat >= $this->heartbeatSeconds) {
            $connection->send(json_encode([
                'req_id' => 'heartbeat-gap-'.((string) $now),
                'op' => 'ping',
            ], JSON_THROW_ON_ERROR));
            $lastHeartbeat = $now;
        }
    }

    private function drainPendingWebSocketFrames(WebSocketConnection $connection): void
    {
        $drained = 0;
        while (! $this->stopping && $drained < 50) {
            $message = $connection->receive();
            if ($message === null) {
                break;
            }
            if (! $this->isPongMessage($message) && ! $this->isSubscriptionMessage($message)) {
                $this->ingestor->ingest($message);
            }
            $drained++;
        }
    }

    private function isPongMessage(string $message): bool
    {
        if (! str_contains($message, 'pong')) {
            return false;
        }

        try {
            $data = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
            if (is_array($data)) {
                return ($data['op'] ?? null) === 'pong'
                    || ($data['ret_msg'] ?? null) === 'pong';
            }
        } catch (JsonException) {
        }

        return false;
    }

    private function isSubscriptionMessage(string $message): bool
    {
        if (! str_contains($message, 'subscribe')) {
            return false;
        }

        try {
            $data = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
            if (is_array($data)) {
                return ($data['op'] ?? null) === 'subscribe';
            }
        } catch (JsonException) {
        }

        return false;
    }

    private function installSignalHandlers(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->requestStop());
        pcntl_signal(SIGINT, fn () => $this->requestStop());
    }
}
