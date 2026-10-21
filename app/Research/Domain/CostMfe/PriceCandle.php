<?php

declare(strict_types=1);

namespace App\Research\Domain\CostMfe;

use Carbon\CarbonImmutable;

/**
 * PROFIT-1 / P-4 — a single closed market candle used for excursion
 * diagnostics (§9.2).
 *
 * OHLC candles are estimates, not tick-level path truth: the intra-candle
 * sequence is unknown, which is why the excursion calculator flags ambiguity.
 */
final class PriceCandle
{
    public function __construct(
        public readonly string $candleId,
        public readonly string $symbol,
        public readonly string $interval,
        public readonly CarbonImmutable $openTime,
        public readonly CarbonImmutable $closeTime,
        public readonly string $open,
        public readonly string $high,
        public readonly string $low,
        public readonly string $close,
    ) {}
}
