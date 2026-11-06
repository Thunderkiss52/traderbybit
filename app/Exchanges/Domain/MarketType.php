<?php

declare(strict_types=1);

namespace App\Exchanges\Domain;

enum MarketType: string
{
    case Spot = 'spot';
    case Futures = 'futures';
    case Perpetual = 'perpetual';
    case Options = 'options';

    public function displayName(): string
    {
        return match ($this) {
            self::Spot => 'Spot',
            self::Futures => 'Dated Futures',
            self::Perpetual => 'Perpetual Futures',
            self::Options => 'Options',
        };
    }

    public function isDerivatives(): bool
    {
        return match ($this) {
            self::Spot => false,
            self::Futures, self::Perpetual, self::Options => true,
        };
    }

    public function hasFunding(): bool
    {
        return $this === self::Perpetual;
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            'spot' => self::Spot,
            'futures', 'dated_futures', 'delivery' => self::Futures,
            'perpetual', 'linear_perpetual', 'inverse_perpetual', 'swap' => self::Perpetual,
            'options' => self::Options,
            default => throw new \InvalidArgumentException("Unknown market type: '{$value}'"),
        };
    }
}
