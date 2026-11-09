<?php

declare(strict_types=1);

namespace App\PaperTrading\Domain;

/**
 * Normalized 4-state Trade Risk classification.
 * Evaluates the financial risk, exposure, distance, leverage, and volatility of a prospective trade.
 */
enum TradeRiskClass: string
{
    case Low = 'LOW';
    case Medium = 'MEDIUM';
    case High = 'HIGH';
    case Unknown = 'UNKNOWN';

    public static function fromString(?string $value): self
    {
        if ($value === null || $value === '' || $value === 'null') {
            return self::Unknown;
        }

        $upper = strtoupper(trim($value));

        return match ($upper) {
            'LOW' => self::Low,
            'MEDIUM', 'MED' => self::Medium,
            'HIGH' => self::High,
            default => self::Unknown,
        };
    }

    public function isAcceptable(): bool
    {
        return $this === self::Low || $this === self::Medium;
    }

    public function label(): string
    {
        return $this->value;
    }
}
