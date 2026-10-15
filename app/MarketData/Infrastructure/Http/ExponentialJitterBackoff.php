<?php

declare(strict_types=1);

namespace App\MarketData\Infrastructure\Http;

use App\MarketData\Contracts\Backoff;
use Random\Randomizer;

final readonly class ExponentialJitterBackoff implements Backoff
{
    public function __construct(
        private int $baseMilliseconds,
        private int $maxMilliseconds,
        private Randomizer $randomizer = new Randomizer,
    ) {}

    public function milliseconds(int $retryNumber): int
    {
        $cap = min($this->maxMilliseconds, $this->baseMilliseconds * (2 ** max(0, $retryNumber - 1)));

        return $this->randomizer->getInt((int) floor($cap / 2), $cap);
    }
}
