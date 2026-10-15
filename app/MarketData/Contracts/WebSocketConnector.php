<?php

declare(strict_types=1);

namespace App\MarketData\Contracts;

interface WebSocketConnector
{
    public function connect(string $url): WebSocketConnection;
}
