<?php

declare(strict_types=1);

namespace App\Exchanges\Domain;

enum CapabilityStatus: string
{
    case Supported = 'SUPPORTED';
    case PartiallySupported = 'PARTIALLY_SUPPORTED';
    case Unsupported = 'UNSUPPORTED';
    case TemporarilyUnavailable = 'TEMPORARILY_UNAVAILABLE';
    case NotAssessed = 'NOT_ASSESSED';

    public function isOperational(): bool
    {
        return $this === self::Supported || $this === self::PartiallySupported;
    }

    public function displayName(): string
    {
        return match ($this) {
            self::Supported => 'Supported',
            self::PartiallySupported => 'Partially Supported',
            self::Unsupported => 'Unsupported',
            self::TemporarilyUnavailable => 'Temporarily Unavailable',
            self::NotAssessed => 'Not Assessed',
        };
    }
}
