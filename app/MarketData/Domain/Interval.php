<?php

declare(strict_types=1);

namespace App\MarketData\Domain;

use InvalidArgumentException;

enum Interval: string
{
    case FiveMinutes = '5';
    case FifteenMinutes = '15';
    case ThirtyMinutes = '30';
    case OneHour = '60';

    public function milliseconds(): int
    {
        return match ($this) {
            self::FiveMinutes => 300_000,
            self::FifteenMinutes => 900_000,
            self::ThirtyMinutes => 1_800_000,
            self::OneHour => 3_600_000,
        };
    }

    public function floor(int $timestampMs): int
    {
        return intdiv($timestampMs, $this->milliseconds()) * $this->milliseconds();
    }

    public static function parse(string $value): self
    {
        return self::tryFrom($value)
            ?? throw new InvalidArgumentException("Unsupported interval [{$value}]. Allowed: 5, 15, 30, 60.");
    }
}
