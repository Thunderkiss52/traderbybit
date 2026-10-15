<?php

declare(strict_types=1);

namespace App\PaperTrading\Domain;

use DateTimeImmutable;

final readonly class MarketBar
{
    public function __construct(
        public DateTimeImmutable $openTime,
        public DateTimeImmutable $closeTime,
        public string $open,
        public string $high,
        public string $low,
        public string $close,
        public bool $isClosed,
        public ?string $bid = null,
        public ?string $ask = null,
        public string $source = 'closed_5m_ohlc',
    ) {}
}
