<?php

declare(strict_types=1);

namespace App\PaperTrading\Domain;

use DomainException;

enum PositionStatus: string
{
    case Open = 'OPEN';
    case PartiallyClosed = 'PARTIALLY_CLOSED';
    case ClosedTp = 'CLOSED_TP';
    case ClosedSl = 'CLOSED_SL';
    case ClosedTimeout = 'CLOSED_TIMEOUT';
    case ClosedRisk = 'CLOSED_RISK';

    public function isClosed(): bool
    {
        return in_array($this, [self::ClosedTp, self::ClosedSl, self::ClosedTimeout, self::ClosedRisk], true);
    }

    public function transitionTo(self $next): void
    {
        $allowed = match ($this) {
            self::Open => [self::PartiallyClosed, self::ClosedTp, self::ClosedSl, self::ClosedTimeout, self::ClosedRisk],
            self::PartiallyClosed => [self::ClosedTp, self::ClosedSl, self::ClosedTimeout, self::ClosedRisk],
            default => [],
        };
        if (! in_array($next, $allowed, true)) {
            throw new DomainException("Invalid position transition {$this->value} -> {$next->value}.");
        }
    }
}
