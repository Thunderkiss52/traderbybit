<?php

declare(strict_types=1);

namespace App\Indicators\Calculators;

use App\Indicators\Math\Decimal;
use App\Indicators\Math\SeriesValidator;
use Brick\Math\BigDecimal;

final readonly class SmaCalculator
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
        $result = array_fill(0, count($values), null);
        $window = [];
        $sum = $this->decimal->zero();

        foreach ($values as $index => $value) {
            $number = $this->decimal->of($value);
            $window[] = $number;
            $sum = $this->decimal->add($sum, $number);

            if (count($window) > $period) {
                /** @var BigDecimal $removed */
                $removed = array_shift($window);
                $sum = $this->decimal->subtract($sum, $removed);
            }

            if (count($window) === $period) {
                $result[$index] = $this->decimal->divide($sum, $period);
            }
        }

        return $result;
    }
}
