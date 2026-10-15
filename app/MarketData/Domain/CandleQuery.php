<?php

declare(strict_types=1);

namespace App\MarketData\Domain;

use InvalidArgumentException;

final readonly class CandleQuery
{
    public function __construct(
        public string $category,
        public string $symbol,
        public Interval $interval,
        public int $fromMs,
        public int $toMs,
    ) {
        if ($fromMs < 0 || $toMs <= $fromMs) {
            throw new InvalidArgumentException('Candle range must be non-empty and use non-negative Unix milliseconds.');
        }
        if (! preg_match('/^[A-Z0-9]+$/', $symbol)) {
            throw new InvalidArgumentException('Symbol must be uppercase alphanumeric.');
        }
    }
}
