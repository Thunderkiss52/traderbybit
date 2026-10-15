<?php

declare(strict_types=1);

namespace App\Strategy\Domain;

use DateTimeImmutable;

final readonly class BarState
{
    public function __construct(
        public DateTimeImmutable $openTime,
        public DateTimeImmutable $closeTime,
        public string $open,
        public string $high,
        public string $low,
        public string $close,
        public string $turnover,
        public ?string $emaEntry,
        public ?string $emaFast,
        public ?string $emaSlow,
        public ?string $rsi,
        public ?string $macd,
        public ?string $macdSignal,
        public ?string $macdHistogram,
        public ?string $atr,
        public ?string $adx,
        public ?string $plusDi,
        public ?string $minusDi,
        public ?string $relativeVolume,
        public ?string $averageTurnover,
        public ?string $support,
        public ?string $resistance,
        public ?string $bollingerUpper = null,
        public ?string $bollingerMiddle = null,
        public ?string $bollingerLower = null,
    ) {}
}
