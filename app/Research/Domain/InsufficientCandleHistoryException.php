<?php

declare(strict_types=1);

namespace App\Research\Domain;

use RuntimeException;

final class InsufficientCandleHistoryException extends RuntimeException
{
    public function __construct(
        public readonly int $availableDays,
        public readonly int $requiredDays,
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        $msg = $message !== ''
            ? $message
            : "Insufficient contiguous candle history for candidate evaluation: {$availableDays} days available, minimum {$requiredDays} days required.";

        parent::__construct($msg, $code, $previous);
    }
}
