<?php

declare(strict_types=1);

namespace App\Strategy\Exceptions;

use DateTimeInterface;
use RuntimeException;

/**
 * Thrown when a strategy-observation slot is already being evaluated by a
 * concurrent worker. This is a benign scheduling event, NOT a data or
 * computation failure: the owning worker will complete the canonical slot and
 * downstream pipeline stages will terminalize the duplicate attempt.
 *
 * Callers must never map this exception to a terminal FAILED state.
 */
final class StrategyObservationActiveException extends RuntimeException
{
    public function __construct(string $message = 'Strategy observation is already running.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function forSlot(string $scope, DateTimeInterface $at): self
    {
        return new self("Strategy observation is already running for {$scope} at {$at->format(DATE_ATOM)}.");
    }
}
