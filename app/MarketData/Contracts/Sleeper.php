<?php

declare(strict_types=1);

namespace App\MarketData\Contracts;

interface Sleeper
{
    public function sleepMilliseconds(int $milliseconds): void;
}
