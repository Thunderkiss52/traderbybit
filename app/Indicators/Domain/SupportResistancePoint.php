<?php

declare(strict_types=1);

namespace App\Indicators\Domain;

final readonly class SupportResistancePoint
{
    public function __construct(
        public ?string $support,
        public int $supportStrength,
        public ?string $resistance,
        public int $resistanceStrength,
    ) {}
}
