<?php

declare(strict_types=1);

namespace App\MarketData\Infrastructure\WebSocket;

use App\MarketData\Contracts\WebSocketConnection;
use Phrity\Net\StreamException;
use RuntimeException;
use Throwable;
use WebSocket\Client;
use WebSocket\Exception\ClientException;
use WebSocket\Exception\CloseException;
use WebSocket\Exception\ConnectionClosedException;
use WebSocket\Exception\ConnectionFailureException;
use WebSocket\Exception\ConnectionTimeoutException;

final readonly class PhrityWebSocketConnection implements WebSocketConnection
{
    public function __construct(
        private Client $client,
        private int $receiveTimeoutSeconds = 1,
    ) {}

    public function send(string $payload): void
    {
        $this->client->text($payload);
    }

    public function receive(): ?string
    {
        if (! $this->isConnected()) {
            throw new RuntimeException('WebSocket is not connected; connection dead.');
        }

        if ($this->client instanceof BybitPhrityClient) {
            $resource = $this->client->getSocketResource();
            if (is_resource($resource)) {
                $read = [$resource];
                $write = $except = [];
                $changed = @stream_select($read, $write, $except, $this->receiveTimeoutSeconds, 0);
                if ($changed === false) {
                    throw new RuntimeException('WebSocket stream_select failed; connection dead.');
                }
                if ($changed === 0) {
                    // Clean timeout: no incoming data on socket
                    return null;
                }
            }
        }

        try {
            return $this->client->receive()->getContent();
        } catch (ConnectionTimeoutException) {
            return null;
        } catch (ConnectionClosedException $e) {
            throw new RuntimeException('WebSocket connection closed by peer (EOF).', previous: $e);
        } catch (ConnectionFailureException $e) {
            throw new RuntimeException('WebSocket connection failure: '.$e->getMessage(), previous: $e);
        } catch (CloseException $e) {
            throw new RuntimeException('WebSocket close frame received: '.$e->getMessage(), previous: $e);
        } catch (StreamException $e) {
            throw new RuntimeException('WebSocket stream error: '.$e->getMessage(), previous: $e);
        } catch (ClientException $e) {
            throw new RuntimeException('WebSocket client error: '.$e->getMessage(), previous: $e);
        }
    }

    public function isConnected(): bool
    {
        return $this->client->isConnected();
    }

    public function close(): void
    {
        try {
            if ($this->client->isConnected()) {
                $this->client->close(1000, 'shutdown');
            }
        } catch (Throwable) {
            try {
                $this->client->disconnect();
            } catch (Throwable) {
            }
        }
    }
}
