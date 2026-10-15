<?php

declare(strict_types=1);

namespace App\MarketData\Infrastructure\WebSocket;

use App\MarketData\Contracts\WebSocketConnection;
use App\MarketData\Contracts\WebSocketConnector;
use Psr\Log\LoggerInterface;
use WebSocket\Client;
use WebSocket\Configuration;
use WebSocket\Middleware\CloseHandler;
use WebSocket\Middleware\PingResponder;

final readonly class PhrityWebSocketConnector implements WebSocketConnector
{
    public function __construct(
        private LoggerInterface $logger,
        private int $receiveTimeoutSeconds,
    ) {}

    public function connect(string $url): WebSocketConnection
    {
        $configuration = new Configuration(
            logger: $this->logger,
            timeout: $this->receiveTimeoutSeconds,
        );
        $client = new Client($url, $configuration);
        $client->addMiddleware(new CloseHandler);
        $client->addMiddleware(new PingResponder);

        return new PhrityWebSocketConnection($client);
    }
}
