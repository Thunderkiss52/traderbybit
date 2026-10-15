<?php

declare(strict_types=1);

namespace App\Indicators\Calculators;

use App\Indicators\Math\Decimal;
use App\Indicators\Math\SeriesValidator;
use Brick\Math\BigDecimal;

final readonly class EmaCalculator
{
    public function __construct(
        private Decimal $decimal,
        private SeriesValidator $validator,
    ) {}

    /** @param list<string> $values @return list<?string> */
    public function calculate(array $values, int $period): array
    {
        return array_map(
            fn (?BigDecimal $value): ?string => $value === null ? null : $this->decimal->output($value),
            $this->calculateDecimal($values, $period),
        );
    }

    /** @param list<string> $values @return list<?BigDecimal> */
    public function calculateDecimal(array $values, int $period): array
    {
        $this->validator->numeric($values, $period);
        $count = count($values);
        $result = array_fill(0, $count, null);
        if ($count < $period) {
            return $result;
        }

        $seedValues = array_map(
            fn (string $value): BigDecimal => $this->decimal->of($value),
            array_slice($values, 0, $period),
        );
        $ema = $this->decimal->average($seedValues);
        $result[$period - 1] = $ema;
        $alpha = $this->decimal->divide($this->decimal->of(2), $period + 1);

        for ($index = $period; $index < $count; $index++) {
            $price = $this->decimal->of($values[$index]);
            $ema = $this->decimal->add(
                $ema,
                $this->decimal->multiply($this->decimal->subtract($price, $ema), $alpha),
            );
            $result[$index] = $ema;
        }

        return $result;
    }
}
