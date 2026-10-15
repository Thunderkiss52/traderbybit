<?php

declare(strict_types=1);

namespace App\Indicators\Calculators;

use App\Indicators\Math\Decimal;
use App\Indicators\Math\SeriesValidator;
use App\MarketData\Domain\Candle;
use Brick\Math\BigDecimal;

final readonly class AtrCalculator
{
    public function __construct(
        private Decimal $decimal,
        private SeriesValidator $validator,
    ) {}

    /** @param list<Candle> $candles @return list<?string> */
    public function calculate(array $candles, int $period): array
    {
        $this->validator->numeric([], $period);
        $this->validator->candles($candles);
        $count = count($candles);
        $result = array_fill(0, $count, null);
        if ($count === 0) {
            return $result;
        }

        $trueRanges = [];
        foreach ($candles as $index => $candle) {
            $high = $this->decimal->of($candle->high);
            $low = $this->decimal->of($candle->low);
            $range = $this->decimal->subtract($high, $low);
            if ($index > 0) {
                $previousClose = $this->decimal->of($candles[$index - 1]->close);
                $range = $this->maximum([
                    $range,
                    $this->decimal->subtract($high, $previousClose)->abs(),
                    $this->decimal->subtract($low, $previousClose)->abs(),
                ]);
            }
            $trueRanges[] = $range;
        }

        if ($count < $period) {
            return $result;
        }

        $atr = $this->decimal->average(array_slice($trueRanges, 0, $period));
        $result[$period - 1] = $this->decimal->output($atr);

        for ($index = $period; $index < $count; $index++) {
            $atr = $this->decimal->divide(
                $this->decimal->add($this->decimal->multiply($atr, $period - 1), $trueRanges[$index]),
                $period,
            );
            $result[$index] = $this->decimal->output($atr);
        }

        return $result;
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
