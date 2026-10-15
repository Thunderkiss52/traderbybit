<?php

declare(strict_types=1);

namespace App\PaperTrading\Domain;

final readonly class PositionSize
{
    public function __construct(
        public bool $allowed,
        public ?string $quantity,
        public string $riskCapital,
        public ?string $effectiveLossPerUnit,
        public ?string $notional,
        public ?string $reason = null,
        public ?string $qtyStep = null,
    ) {}
}
