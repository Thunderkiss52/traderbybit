<?php

declare(strict_types=1);

namespace App\MarketData\Contracts;

interface WebSocketConnection
{
    public function send(string $payload): void;

    public function receive(): ?string;

    public function close(): void;
}
