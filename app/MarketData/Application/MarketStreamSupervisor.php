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
use Psr\Log\LoggerInterface;
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
            try {
                $connection = $this->connector->connect($this->url);
                $this->subscribe($connection);
                // Subscribe first so frames are buffered while REST closes the reconnect gap.
                $this->recoverMissedCandles();
                $this->consume($connection);
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
                $this->sleeper->sleepMilliseconds(min(
                    $this->reconnectMaxSeconds * 1000,
                    $this->backoff->milliseconds($cycle),
                ));
            }
        }
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
    }

    private function consume(WebSocketConnection $connection): void
    {
        $lastHeartbeat = 0;

        while (! $this->stopping) {
            $now = time();
            if ($now - $lastHeartbeat >= $this->heartbeatSeconds) {
                $connection->send(json_encode([
                    'req_id' => 'heartbeat-'.((string) $now),
                    'op' => 'ping',
                ], JSON_THROW_ON_ERROR));
                $lastHeartbeat = $now;
            }

            $message = $connection->receive();
            if ($message !== null) {
                $this->ingestor->ingest($message);
            }
        }
    }

    private function recoverMissedCandles(): void
    {
        $nowMs = (int) floor(microtime(true) * 1000);
        foreach ($this->symbols as $symbol) {
            foreach ($this->intervals as $value) {
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
            }
        }
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
