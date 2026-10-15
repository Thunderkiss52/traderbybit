<?php

declare(strict_types=1);

namespace App\Indicators\Calculators;

use App\Indicators\Math\Decimal;
use App\Indicators\Math\SeriesValidator;
use Brick\Math\BigDecimal;

final readonly class RsiCalculator
{
    public function __construct(
        private Decimal $decimal,
        private SeriesValidator $validator,
    ) {}

    /** @param list<string> $closes @return list<?string> */
    public function calculate(array $closes, int $period): array
    {
        $this->validator->numeric($closes, $period);
        $count = count($closes);
        $result = array_fill(0, $count, null);
        if ($count <= $period) {
            return $result;
        }

        $gains = [];
        $losses = [];
        for ($index = 1; $index <= $period; $index++) {
            [$gain, $loss] = $this->movement($closes[$index - 1], $closes[$index]);
            $gains[] = $gain;
            $losses[] = $loss;
        }

        $averageGain = $this->decimal->average($gains);
        $averageLoss = $this->decimal->average($losses);
        $result[$period] = $this->rsi($averageGain, $averageLoss);

        for ($index = $period + 1; $index < $count; $index++) {
            [$gain, $loss] = $this->movement($closes[$index - 1], $closes[$index]);
            $averageGain = $this->decimal->divide(
                $this->decimal->add($this->decimal->multiply($averageGain, $period - 1), $gain),
                $period,
            );
            $averageLoss = $this->decimal->divide(
                $this->decimal->add($this->decimal->multiply($averageLoss, $period - 1), $loss),
                $period,
            );
            $result[$index] = $this->rsi($averageGain, $averageLoss);
        }

        return $result;
    }

    private function movement(string $previous, string $current): array
    {
        $change = $this->decimal->subtract($this->decimal->of($current), $this->decimal->of($previous));
        if ($change->isPositive()) {
            return [$change, $this->decimal->zero()];
        }

        return [$this->decimal->zero(), $change->abs()];
    }

    private function rsi(BigDecimal $averageGain, BigDecimal $averageLoss): string
    {
        if ($averageGain->isZero() && $averageLoss->isZero()) {
            return $this->decimal->output($this->decimal->of(50));
        }
        if ($averageLoss->isZero()) {
            return $this->decimal->output($this->decimal->of(100));
        }

        $relativeStrength = $this->decimal->divide($averageGain, $averageLoss);
        $value = $this->decimal->subtract(
            $this->decimal->of(100),
            $this->decimal->divide($this->decimal->of(100), $this->decimal->add($this->decimal->of(1), $relativeStrength)),
        );

        return $this->decimal->output($value);
    }
}
