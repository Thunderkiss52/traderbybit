<?php

declare(strict_types=1);

namespace App\MarketData\Infrastructure\Http;

final readonly class TransportResponse
{
    /** @param array<string, mixed>|null $json @param array<string, list<string>> $headers */
    public function __construct(
        public int $status,
        public ?array $json,
        public array $headers = [],
    ) {}
}
