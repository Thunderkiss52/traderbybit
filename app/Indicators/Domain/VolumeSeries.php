<?php

declare(strict_types=1);

namespace App\Indicators\Domain;

final readonly class VolumeSeries
{
    /** @param list<string> $volume @param list<?string> $volumeSma @param list<?string> $relativeVolume @param list<?string> $turnoverSma */
    public function __construct(
        public array $volume,
        public array $volumeSma,
        public array $relativeVolume,
        public array $turnoverSma,
    ) {}
}
