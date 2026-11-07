<?php

declare(strict_types=1);

namespace App\MarketData\Domain;

use InvalidArgumentException;

enum Interval: string
{
    case OneMinute = '1';
    case ThreeMinutes = '3';
    case FiveMinutes = '5';
    case FifteenMinutes = '15';
    case ThirtyMinutes = '30';
    case OneHour = '60';
    case FourHours = '240';
    case OneDay = 'D';

    public function milliseconds(): int
    {
        return match ($this) {
            self::OneMinute => 60_000,
            self::ThreeMinutes => 180_000,
            self::FiveMinutes => 300_000,
            self::FifteenMinutes => 900_000,
            self::ThirtyMinutes => 1_800_000,
            self::OneHour => 3_600_000,
            self::FourHours => 14_400_000,
            self::OneDay => 86_400_000,
        };
    }

    public function seconds(): int
    {
        return intdiv($this->milliseconds(), 1000);
    }

    public function floor(int $timestampMs): int
    {
        return intdiv($timestampMs, $this->milliseconds()) * $this->milliseconds();
    }

    public static function parse(string $value): self
    {
        return self::tryFrom($value)
            ?? throw new InvalidArgumentException("Unsupported interval [{$value}]. Allowed: 1, 3, 5, 15, 30, 60, 240, D.");
    }
}
