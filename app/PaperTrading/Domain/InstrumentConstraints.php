<?php

declare(strict_types=1);

namespace App\PaperTrading\Domain;

final readonly class InstrumentConstraints
{
    public function __construct(
        public string $qtyStep,
        public string $minOrderQty,
        public string $maxOrderQty,
        public string $minNotionalValue,
    ) {}
}
