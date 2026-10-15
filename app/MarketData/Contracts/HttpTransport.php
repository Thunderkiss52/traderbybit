<?php

declare(strict_types=1);

namespace App\MarketData\Contracts;

use App\MarketData\Infrastructure\Http\TransportResponse;

interface HttpTransport
{
    /** @param array<string, scalar> $query */
    public function get(string $path, array $query): TransportResponse;
}
