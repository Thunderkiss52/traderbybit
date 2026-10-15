<?php

declare(strict_types=1);

namespace App\MarketData\Infrastructure\Http;

use App\MarketData\Contracts\Sleeper;

final class NativeSleeper implements Sleeper
{
    public function sleepMilliseconds(int $milliseconds): void
    {
        usleep(max(0, $milliseconds) * 1000);
    }
}
