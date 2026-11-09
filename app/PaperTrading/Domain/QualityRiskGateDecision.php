<?php

declare(strict_types=1);

namespace App\PaperTrading\Domain;

/**
 * Result of the Pre-Execution Quality and Risk Gate.
 */
enum QualityRiskGateDecision: string
{
    case Eligible = 'ELIGIBLE';
    case ConditionallyEligible = 'CONDITIONALLY_ELIGIBLE';
    case ObservationOnly = 'OBSERVATION_ONLY';
    case InsufficientEvidence = 'INSUFFICIENT_EVIDENCE';
    case Rejected = 'REJECTED';

    public function isPermitted(): bool
    {
        return $this === self::Eligible || $this === self::ConditionallyEligible;
    }
}
