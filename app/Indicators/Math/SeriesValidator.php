<?php

declare(strict_types=1);

namespace App\Indicators\Math;

use App\Indicators\Exceptions\InvalidSeriesException;
use App\MarketData\Domain\Candle;

final class SeriesValidator
{
    /** @param list<string> $values */
    public function numeric(array $values, int $period): void
    {
        if ($period < 1) {
            throw new InvalidSeriesException('Indicator period must be at least one.');
        }

        foreach ($values as $index => $value) {
            if (! is_string($value) || ! preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/', $value)) {
                throw new InvalidSeriesException("Series value at index {$index} must be a decimal string.");
            }
        }
    }

    /** @param list<Candle> $candles */
    public function candles(array $candles): void
    {
        if ($candles === []) {
            return;
        }

        $first = $candles[0];
        $previousTime = null;
        foreach ($candles as $index => $candle) {
            if (! $candle instanceof Candle) {
                throw new InvalidSeriesException("Candle series item at index {$index} is invalid.");
            }
            if (! $candle->isClosed) {
                throw new InvalidSeriesException("Candle series contains an open candle at index {$index}.");
            }
            if ($candle->exchange !== $first->exchange
                || $candle->category !== $first->category
                || $candle->symbol !== $first->symbol
                || $candle->interval !== $first->interval) {
                throw new InvalidSeriesException('All candles must belong to the same market series.');
            }

            $currentTime = $candle->openTimeMs();
            if ($previousTime !== null && $currentTime !== $previousTime + $candle->interval->milliseconds()) {
                throw new InvalidSeriesException('Closed candle series must be strictly ordered and contiguous.');
            }
            $previousTime = $currentTime;
        }
    }
}
