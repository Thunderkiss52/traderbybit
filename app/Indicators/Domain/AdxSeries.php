<?php

declare(strict_types=1);

namespace App\Indicators\Domain;

final readonly class AdxSeries
{
    /** @param list<?string> $adx @param list<?string> $plusDi @param list<?string> $minusDi @param list<?string> $dx */
    public function __construct(
        public array $adx,
        public array $plusDi,
        public array $minusDi,
        public array $dx,
    ) {}
}
