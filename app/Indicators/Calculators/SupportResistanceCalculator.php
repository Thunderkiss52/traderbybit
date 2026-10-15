<?php

declare(strict_types=1);

namespace App\Indicators\Calculators;

use App\Indicators\Domain\SupportResistancePoint;
use App\Indicators\Exceptions\InvalidSeriesException;
use App\Indicators\Math\Decimal;
use App\Indicators\Math\SeriesValidator;
use App\MarketData\Domain\Candle;
use Brick\Math\BigDecimal;

final readonly class SupportResistanceCalculator
{
    public function __construct(
        private Decimal $decimal,
        private SeriesValidator $validator,
    ) {}

    /** @param list<Candle> $candles @return list<SupportResistancePoint> */
    public function calculate(
        array $candles,
        int $lookback,
        int $leftBars,
        int $rightBars,
        string $clusterTolerance,
    ): array {
        $this->validator->candles($candles);
        if ($lookback < 1 || $leftBars < 1 || $rightBars < 1) {
            throw new InvalidSeriesException('Support/resistance lookback and pivot widths must be positive.');
        }
        $tolerance = $this->decimal->of($clusterTolerance);
        if ($tolerance->isNegative()) {
            throw new InvalidSeriesException('Support/resistance cluster tolerance cannot be negative.');
        }

        $points = [];
        $pivotHighs = [];
        $pivotLows = [];
        foreach ($candles as $index => $candle) {
            $confirmedPivot = $index - $rightBars;
            if ($confirmedPivot >= $leftBars) {
                if ($this->isPivotHigh($candles, $confirmedPivot, $leftBars, $rightBars)) {
                    $pivotHighs[$confirmedPivot] = $this->decimal->of($candles[$confirmedPivot]->high);
                }
                if ($this->isPivotLow($candles, $confirmedPivot, $leftBars, $rightBars)) {
                    $pivotLows[$confirmedPivot] = $this->decimal->of($candles[$confirmedPivot]->low);
                }
            }

            $minimumIndex = max(0, $index - $lookback + 1);
            $pivotHighs = array_filter($pivotHighs, static fn (int $pivotIndex): bool => $pivotIndex >= $minimumIndex, ARRAY_FILTER_USE_KEY);
            $pivotLows = array_filter($pivotLows, static fn (int $pivotIndex): bool => $pivotIndex >= $minimumIndex, ARRAY_FILTER_USE_KEY);
            $close = $this->decimal->of($candle->close);
            $support = $this->nearestSupport($pivotLows, $close);
            $resistance = $this->nearestResistance($pivotHighs, $close);

            $points[] = new SupportResistancePoint(
                support: $support === null ? null : $this->decimal->output($support),
                supportStrength: $support === null ? 0 : $this->strength($pivotLows, $support, $tolerance),
                resistance: $resistance === null ? null : $this->decimal->output($resistance),
                resistanceStrength: $resistance === null ? 0 : $this->strength($pivotHighs, $resistance, $tolerance),
            );
        }

        return $points;
    }

    /** @param list<Candle> $candles */
    private function isPivotHigh(array $candles, int $index, int $left, int $right): bool
    {
        $candidate = $this->decimal->of($candles[$index]->high);
        for ($cursor = $index - $left; $cursor <= $index + $right; $cursor++) {
            if ($cursor !== $index && ! $candidate->isGreaterThan($this->decimal->of($candles[$cursor]->high))) {
                return false;
            }
        }

        return true;
    }

    /** @param list<Candle> $candles */
    private function isPivotLow(array $candles, int $index, int $left, int $right): bool
    {
        $candidate = $this->decimal->of($candles[$index]->low);
        for ($cursor = $index - $left; $cursor <= $index + $right; $cursor++) {
            if ($cursor !== $index && ! $candidate->isLessThan($this->decimal->of($candles[$cursor]->low))) {
                return false;
            }
        }

        return true;
    }

    /** @param array<int, BigDecimal> $levels */
    private function nearestSupport(array $levels, BigDecimal $close): ?BigDecimal
    {
        $nearest = null;
        foreach ($levels as $level) {
            if ($level->isLessThanOrEqualTo($close) && ($nearest === null || $level->isGreaterThan($nearest))) {
                $nearest = $level;
            }
        }

        return $nearest;
    }

    /** @param array<int, BigDecimal> $levels */
    private function nearestResistance(array $levels, BigDecimal $close): ?BigDecimal
    {
        $nearest = null;
        foreach ($levels as $level) {
            if ($level->isGreaterThanOrEqualTo($close) && ($nearest === null || $level->isLessThan($nearest))) {
                $nearest = $level;
            }
        }

        return $nearest;
    }

    /** @param array<int, BigDecimal> $levels */
    private function strength(array $levels, BigDecimal $selected, BigDecimal $tolerance): int
    {
        $absoluteTolerance = $this->decimal->multiply($selected->abs(), $tolerance);
        $strength = 0;
        foreach ($levels as $level) {
            if ($this->decimal->subtract($level, $selected)->abs()->isLessThanOrEqualTo($absoluteTolerance)) {
                $strength++;
            }
        }

        return $strength;
    }
}
