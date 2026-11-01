<?php

declare(strict_types=1);

namespace App\MarketData\Infrastructure\WebSocket;

use Throwable;
use WebSocket\Client;

class BybitPhrityClient extends Client
{
    public function getSocketResource(): mixed
    {
        try {
            return $this->connection()->getStream()->getResource();
        } catch (Throwable) {
            return null;
        }
    }
}
