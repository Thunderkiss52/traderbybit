<?php

declare(strict_types=1);

namespace App\MarketData\Contracts;

interface Backoff
{
    public function milliseconds(int $retryNumber): int;
}
