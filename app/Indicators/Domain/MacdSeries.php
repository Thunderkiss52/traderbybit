<?php

declare(strict_types=1);

namespace App\Indicators\Domain;

final readonly class MacdSeries
{
    /** @param list<?string> $macd @param list<?string> $signal @param list<?string> $histogram */
    public function __construct(
        public array $macd,
        public array $signal,
        public array $histogram,
    ) {}
}
