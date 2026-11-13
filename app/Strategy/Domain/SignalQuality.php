<?php

declare(strict_types=1);

namespace App\Strategy\Domain;

/**
 * Normalized 4-state Signal Quality classification.
 * Evaluates the intrinsic technical edge of the candidate setup, decoupled from portfolio risk.
 */
enum SignalQuality: string
{
    case High = 'HIGH';
    case Medium = 'MEDIUM';
    case Low = 'LOW';
    case Unknown = 'UNKNOWN';

    /**
     * Map from existing QualityClass or score to normalized SignalQuality.
     */
    public static function fromQualityClass(?QualityClass $qualityClass, ?int $score = null): self
    {
        if ($qualityClass !== null) {
            return match ($qualityClass) {
                QualityClass::A => self::High,
                QualityClass::B => self::Medium,
                QualityClass::C => self::Low,
                default => self::Unknown,
            };
        }

        if ($score !== null) {
            foreach (self::scoreThresholds() as $quality => $minimum) {
                if ($score >= $minimum) {
                    return self::from($quality);
                }
            }
        }

        return self::Unknown;
    }

    /** Ordered policy shared by legacy record interpretation and SQL analytics. */
    public static function scoreThresholds(): array
    {
        return [self::High->value => 80, self::Medium->value => 60, self::Low->value => 40];
    }

    public static function fromString(?string $value): self
    {
        if ($value === null || $value === '' || $value === 'null') {
            return self::Unknown;
        }

        $upper = strtoupper(trim($value));

        return match ($upper) {
            'HIGH', 'A' => self::High,
            'MEDIUM', 'MED', 'B' => self::Medium,
            'LOW', 'C' => self::Low,
            default => self::Unknown,
        };
    }

    public function isActionable(): bool
    {
        return $this === self::High || $this === self::Medium;
    }

    public function label(): string
    {
        return $this->value;
    }
}
