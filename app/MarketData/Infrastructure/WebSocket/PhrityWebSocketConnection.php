<?php

declare(strict_types=1);

namespace App\MarketData\Infrastructure\WebSocket;

use App\MarketData\Contracts\WebSocketConnection;
use WebSocket\Client;
use WebSocket\Exception\ConnectionTimeoutException;

final readonly class PhrityWebSocketConnection implements WebSocketConnection
{
    public function __construct(private Client $client) {}

    public function send(string $payload): void
    {
        $this->client->text($payload);
    }

    public function receive(): ?string
    {
        try {
            return $this->client->receive()->getContent();
        } catch (ConnectionTimeoutException) {
            return null;
        }
    }

    public function close(): void
    {
        $this->client->close(1000, 'shutdown');
    }
}
