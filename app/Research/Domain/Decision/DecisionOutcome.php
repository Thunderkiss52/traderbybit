<?php

declare(strict_types=1);

namespace App\Research\Domain\Decision;

/**
 * PROFIT-1 / P-6 — deterministic result of the research decision gate (§14).
 */
final class DecisionOutcome
{
    /**
     * @param  list<array{gate: string, passed: bool, detail: string}>  $gateResults
     * @param  list<string>  $reasons
     * @param  list<string>  $missingInputs
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $state,
        public readonly array $gateResults,
        public readonly array $reasons,
        public readonly array $missingInputs,
        public readonly array $context = [],
    ) {}

    public function isEligibleForOwnerReview(): bool
    {
        return $this->state === DecisionState::ELIGIBLE_FOR_OWNER_REVIEW;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'reasons' => $this->reasons,
            'missing_inputs' => $this->missingInputs,
            'gate_results' => $this->gateResults,
            'context' => $this->context,
        ];
    }
}
