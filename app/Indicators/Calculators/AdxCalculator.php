<?php

declare(strict_types=1);

namespace App\Indicators\Calculators;

use App\Indicators\Domain\AdxSeries;
use App\Indicators\Math\Decimal;
use App\Indicators\Math\SeriesValidator;
use App\MarketData\Domain\Candle;
use Brick\Math\BigDecimal;

final readonly class AdxCalculator
{
    public function __construct(
        private Decimal $decimal,
        private SeriesValidator $validator,
    ) {}

    /** @param list<Candle> $candles */
    public function calculate(array $candles, int $period): AdxSeries
    {
        $this->validator->numeric([], $period);
        $this->validator->candles($candles);
        $count = count($candles);
        $adx = $plusDi = $minusDi = $dx = array_fill(0, $count, null);
        if ($count <= $period) {
            return new AdxSeries($adx, $plusDi, $minusDi, $dx);
        }

        $trueRanges = $plusDm = $minusDm = array_fill(0, $count, $this->decimal->zero());
        for ($index = 1; $index < $count; $index++) {
            $high = $this->decimal->of($candles[$index]->high);
            $low = $this->decimal->of($candles[$index]->low);
            $previousHigh = $this->decimal->of($candles[$index - 1]->high);
            $previousLow = $this->decimal->of($candles[$index - 1]->low);
            $previousClose = $this->decimal->of($candles[$index - 1]->close);

            $trueRanges[$index] = $this->maximum([
                $this->decimal->subtract($high, $low),
                $this->decimal->subtract($high, $previousClose)->abs(),
                $this->decimal->subtract($low, $previousClose)->abs(),
            ]);
            $upMove = $this->decimal->subtract($high, $previousHigh);
            $downMove = $this->decimal->subtract($previousLow, $low);
            if ($upMove->isPositive() && $upMove->isGreaterThan($downMove)) {
                $plusDm[$index] = $upMove;
            }
            if ($downMove->isPositive() && $downMove->isGreaterThan($upMove)) {
                $minusDm[$index] = $downMove;
            }
        }

        $smoothedTr = $this->decimal->sum(array_slice($trueRanges, 1, $period));
        $smoothedPlusDm = $this->decimal->sum(array_slice($plusDm, 1, $period));
        $smoothedMinusDm = $this->decimal->sum(array_slice($minusDm, 1, $period));
        $dxValues = [];
        $currentAdx = null;

        for ($index = $period; $index < $count; $index++) {
            if ($index > $period) {
                $smoothedTr = $this->wilderSmooth($smoothedTr, $trueRanges[$index], $period);
                $smoothedPlusDm = $this->wilderSmooth($smoothedPlusDm, $plusDm[$index], $period);
                $smoothedMinusDm = $this->wilderSmooth($smoothedMinusDm, $minusDm[$index], $period);
            }

            [$plus, $minus, $directionalIndex] = $this->directionalValues($smoothedTr, $smoothedPlusDm, $smoothedMinusDm);
            $plusDi[$index] = $this->decimal->output($plus);
            $minusDi[$index] = $this->decimal->output($minus);
            $dx[$index] = $this->decimal->output($directionalIndex);
            $dxValues[] = $directionalIndex;

            if (count($dxValues) === $period) {
                $currentAdx = $this->decimal->average($dxValues);
                $adx[$index] = $this->decimal->output($currentAdx);
            } elseif (count($dxValues) > $period) {
                \assert($currentAdx !== null);
                $currentAdx = $this->decimal->divide(
                    $this->decimal->add($this->decimal->multiply($currentAdx, $period - 1), $directionalIndex),
                    $period,
                );
                $adx[$index] = $this->decimal->output($currentAdx);
            }
        }

        return new AdxSeries($adx, $plusDi, $minusDi, $dx);
    }

    private function wilderSmooth(BigDecimal $previous, BigDecimal $current, int $period): BigDecimal
    {
        return $this->decimal->add(
            $this->decimal->subtract($previous, $this->decimal->divide($previous, $period)),
            $current,
        );
    }

    /** @return array{BigDecimal, BigDecimal, BigDecimal} */
    private function directionalValues(BigDecimal $tr, BigDecimal $plusDm, BigDecimal $minusDm): array
    {
        if ($tr->isZero()) {
            $zero = $this->decimal->zero();

            return [$zero, $zero, $zero];
        }

        $plus = $this->decimal->multiply($this->decimal->divide($plusDm, $tr), 100);
        $minus = $this->decimal->multiply($this->decimal->divide($minusDm, $tr), 100);
        $sum = $this->decimal->add($plus, $minus);
        $directionalIndex = $sum->isZero()
            ? $this->decimal->zero()
            : $this->decimal->multiply(
                $this->decimal->divide($this->decimal->subtract($plus, $minus)->abs(), $sum),
                100,
            );

        return [$plus, $minus, $directionalIndex];
    }

    /** @param list<BigDecimal> $values */
    private function maximum(array $values): BigDecimal
    {
        $maximum = $values[0];
        foreach (array_slice($values, 1) as $value) {
            if ($value->isGreaterThan($maximum)) {
                $maximum = $value;
            }
        }

        return $maximum;
    }
}
