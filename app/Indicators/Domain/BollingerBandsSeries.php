<?php

declare(strict_types=1);

namespace App\Indicators\Domain;

final readonly class BollingerBandsSeries
{
    /** @param list<?string> $middle @param list<?string> $upper @param list<?string> $lower @param list<?string> $standardDeviation */
    public function __construct(
        public array $middle,
        public array $upper,
        public array $lower,
        public array $standardDeviation,
    ) {}
}
