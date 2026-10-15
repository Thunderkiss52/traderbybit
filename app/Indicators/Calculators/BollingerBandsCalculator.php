<?php

declare(strict_types=1);

namespace App\Indicators\Calculators;

use App\Indicators\Domain\BollingerBandsSeries;
use App\Indicators\Math\Decimal;
use App\Indicators\Math\SeriesValidator;

final readonly class BollingerBandsCalculator
{
    public function __construct(
        private Decimal $decimal,
        private SeriesValidator $validator,
    ) {}

    /** @param list<string> $values */
    public function calculate(array $values, int $period, string $standardDeviations = '2'): BollingerBandsSeries
    {
        $this->validator->numeric($values, $period);
        $multiplier = $this->decimal->of($standardDeviations);
        $count = count($values);
        $middle = $upper = $lower = $deviation = array_fill(0, $count, null);

        for ($index = $period - 1; $index < $count; $index++) {
            $window = array_map(
                fn (string $value) => $this->decimal->of($value),
                array_slice($values, $index - $period + 1, $period),
            );
            $mean = $this->decimal->average($window);
            $squaredDifferences = [];
            foreach ($window as $value) {
                $difference = $this->decimal->subtract($value, $mean);
                $squaredDifferences[] = $this->decimal->multiply($difference, $difference);
            }
            $standardDeviation = $this->decimal->sqrt($this->decimal->average($squaredDifferences));
            $width = $this->decimal->multiply($standardDeviation, $multiplier);
            $middle[$index] = $this->decimal->output($mean);
            $deviation[$index] = $this->decimal->output($standardDeviation);
            $upper[$index] = $this->decimal->output($this->decimal->add($mean, $width));
            $lower[$index] = $this->decimal->output($this->decimal->subtract($mean, $width));
        }

        return new BollingerBandsSeries($middle, $upper, $lower, $deviation);
    }
}
