<?php

declare(strict_types=1);

namespace App\PaperTrading\Domain;

use DomainException;

enum SignalLifecycle: string
{
    case PendingEntry = 'PENDING_ENTRY';
    case Active = 'ACTIVE';
    case Expired = 'EXPIRED';
    case Rejected = 'REJECTED';
    case Completed = 'COMPLETED';
    case LateSignalNotExecutable = 'LATE_SIGNAL_NOT_EXECUTABLE';

    public function transitionTo(self $next): void
    {
        $allowed = match ($this) {
            self::PendingEntry => [self::Active, self::Expired, self::Rejected, self::LateSignalNotExecutable],
            self::Active => [self::Completed],
            self::Expired, self::Rejected, self::Completed, self::LateSignalNotExecutable => [],
        };
        if (! in_array($next, $allowed, true)) {
            throw new DomainException("Invalid signal transition {$this->value} -> {$next->value}.");
        }
    }
}
