<?php

declare(strict_types=1);

namespace App\Strategy\Domain;

use DateTimeImmutable;

final readonly class MarketContext
{
    public function __construct(
        public DateTimeImmutable $observedAt,
        public string $bid,
        public string $ask,
        public string $turnover24h,
        public string $tickSize,
        public ?string $fundingRate = null,
        public ?string $openInterest = null,
        public ?string $openInterestChange = null,
        public ?string $qtyStep = null,
        public ?string $minOrderQty = null,
        public ?string $maxOrderQty = null,
        public ?string $minNotionalValue = null,
    ) {}
}
