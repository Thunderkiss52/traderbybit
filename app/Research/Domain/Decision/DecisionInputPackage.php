<?php

declare(strict_types=1);

namespace App\Research\Domain\Decision;

/**
 * PROFIT-1 / P-6 — one accepted (or rejected) measurement package that a
 * conclusion may depend on (§14.1).
 *
 * Every field that the decision gate relies on is explicit. `null` never means
 * "good": it means "not declared", and the gate treats it as a failure.
 */
final class DecisionInputPackage
{
    public function __construct(
        public readonly string $packageCode,
        public readonly ?string $generationId,
        public readonly string $status,
        public readonly string $accountingScope,
        public readonly ?string $asOf,
        public readonly ?string $sampleSufficiency = null,
        public readonly ?bool $coverageMet = null,
        public readonly ?bool $reconciled = null,
        public readonly ?string $provenance = null,
    ) {}

    /**
     * A package is usable only when its generation succeeded and it has a
     * generation id. Staleness and reconciliation are evaluated explicitly by
     * the gate, so that an unreconciled package is reported as
     * `UNRECONCILED_INPUT` instead of being hidden behind a generic status.
     */
    public function isAccepted(): bool
    {
        return $this->status === 'SUCCEEDED'
            && $this->generationId !== null
            && $this->generationId !== '';
    }

    public function hasDeclaredSampleSufficiency(): bool
    {
        return $this->sampleSufficiency !== null && $this->sampleSufficiency !== '';
    }

    public function isSampleSufficient(): bool
    {
        return $this->sampleSufficiency === 'SUFFICIENT';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'package_code' => $this->packageCode,
            'generation_id' => $this->generationId,
            'status' => $this->status,
            'accounting_scope' => $this->accountingScope,
            'as_of' => $this->asOf,
            'sample_sufficiency' => $this->sampleSufficiency,
            'coverage_met' => $this->coverageMet,
            'reconciled' => $this->reconciled,
            'provenance' => $this->provenance,
        ];
    }
}
