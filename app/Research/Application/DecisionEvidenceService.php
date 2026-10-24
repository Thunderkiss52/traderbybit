<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\Research\Domain\Decision\DecisionGate;
use App\Research\Domain\Decision\DecisionInputPackage;
use App\Research\Domain\Decision\DecisionState;
use App\Research\Domain\Decision\HypothesisPriority;
use App\Research\Domain\ResearchDecisionRecord;
use App\Research\Domain\ResearchGenerationRecord;
use App\Research\Domain\StrategyRiskMetricRecord;
use App\Research\Exceptions\IdempotencyConflictException;
use Carbon\CarbonImmutable;
use Ramsey\Uuid\Uuid;

/**
 * PROFIT-1 / P-6 — research decision gate (§14).
 *
 * The service assembles the accepted measurement generations of P-1, P-2, P-4
 * and P-5, evaluates the pure `DecisionGate` and appends one decision record.
 *
 * It never changes a strategy, never touches the paper ledger, never creates
 * an order and never authorises anything by itself: the only state that a
 * human can reach is `OWNER_APPROVED_FOR_SEPARATE_PAPER_EXPERIMENT`, and even
 * that state does not implement an experiment.
 */
final class DecisionEvidenceService
{
    public const PACKAGE_CODE = 'P-6';

    public const METRIC_SCHEMA_VERSION = '1.0.0';

    public const ALGORITHM_VERSION = '1.0.0';

    /**
     * @var list<string>
     */
    public const CONSUMED_PACKAGES = ['P-1', 'P-2', 'P-4', 'P-5'];

    public function __construct(
        private readonly DecisionGate $gate = new DecisionGate,
        private readonly HypothesisPriority $hypothesisPriority = new HypothesisPriority,
    ) {}

    /**
     * Evaluate the evidence and append one decision record.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     *
     * @throws IdempotencyConflictException
     */
    public function evaluate(array $request = []): array
    {
        $claimType = (string) ($request['claim_type'] ?? '');
        $accountingScope = (string) ($request['accounting_scope'] ?? 'FULL_LIFECYCLE');
        $asOf = isset($request['as_of']) ? CarbonImmutable::parse((string) $request['as_of'], 'UTC') : CarbonImmutable::now('UTC');
        $idempotencyKey = (string) ($request['idempotency_key'] ?? Uuid::uuid4()->toString());

        $normalizedParams = [
            'package_code' => self::PACKAGE_CODE,
            'claim_type' => $claimType,
            'accounting_scope' => $accountingScope,
            'as_of' => $asOf->toIso8601String(),
            'preregistration_hash' => $request['preregistration_hash'] ?? null,
            'evidence_bundle_hash' => $request['evidence_bundle_hash'] ?? null,
            'test_outcome' => $request['test_outcome'] ?? null,
            'owner_authorization' => $request['owner_authorization'] ?? null,
            'previous_state' => $request['previous_state'] ?? null,
            'algorithm_version' => self::ALGORITHM_VERSION,
        ];
        $payloadHash = hash('sha256', json_encode($normalizedParams, JSON_THROW_ON_ERROR));

        $existingGeneration = ResearchGenerationRecord::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existingGeneration !== null) {
            if ($existingGeneration->request_payload_hash !== $payloadHash) {
                throw new IdempotencyConflictException(
                    "Idempotency conflict: Key [{$idempotencyKey}] was previously used with a different request payload."
                );
            }

            $existingRecord = ResearchDecisionRecord::query()
                ->where('generation_id', $existingGeneration->id)
                ->latest('created_at')
                ->first();

            return [
                'status' => $existingGeneration->status,
                'generation_id' => $existingGeneration->id,
                'decision' => $existingRecord === null ? null : $this->formatRecord($existingRecord),
                'idempotent_replay' => true,
            ];
        }

        $generationId = Uuid::uuid4()->toString();
        $generation = ResearchGenerationRecord::query()->create([
            'id' => $generationId,
            'package_code' => self::PACKAGE_CODE,
            'accounting_scope' => $accountingScope,
            'as_of' => $asOf,
            'status' => 'RUNNING',
            'idempotency_key' => $idempotencyKey,
            'request_payload_hash' => $payloadHash,
            'versioned_config' => [
                'algorithm_version' => self::ALGORITHM_VERSION,
                'metric_schema_version' => self::METRIC_SCHEMA_VERSION,
                'decision_states' => DecisionState::all(),
                'consumed_packages' => self::CONSUMED_PACKAGES,
            ],
            'heartbeat_at' => CarbonImmutable::now('UTC'),
            'stale_after_seconds' => 300,
        ]);

        try {
            $inputs = $this->buildInputPackages($accountingScope);
            $outcome = $this->gate->evaluate($inputs, [
                'claim_type' => $claimType,
                'now' => $asOf,
                'preregistration_hash' => $request['preregistration_hash'] ?? null,
                'evidence_bundle_hash' => $request['evidence_bundle_hash'] ?? null,
                'test_outcome' => $request['test_outcome'] ?? null,
                'owner_authorization' => $request['owner_authorization'] ?? null,
                'previous_state' => $request['previous_state'] ?? null,
            ]);

            $previousState = isset($request['previous_state']) && $request['previous_state'] !== null
                ? (string) $request['previous_state']
                : null;

            $record = null;
            if ($outcome->context['transition_allowed'] ?? false) {
                $record = ResearchDecisionRecord::query()->create([
                    'id' => Uuid::uuid4()->toString(),
                    'generation_id' => $generationId,
                    'decision_state' => $outcome->state,
                    'previous_state' => $previousState,
                    'actor_source' => (string) ($request['actor_source'] ?? 'system:research-decision-gate'),
                    'reason' => implode(',', $outcome->reasons) ?: 'NO_REASON_RECORDED',
                    'claim_type' => $claimType,
                    'hypothesis' => isset($request['hypothesis']) ? (string) $request['hypothesis'] : null,
                    'input_generation_ids' => array_values(array_filter(array_map(
                        static fn (DecisionInputPackage $p): ?string => $p->generationId,
                        $inputs
                    ))),
                    'preregistration_hash' => isset($request['preregistration_hash']) ? (string) $request['preregistration_hash'] : null,
                    'evidence_bundle_hash' => isset($request['evidence_bundle_hash']) ? (string) $request['evidence_bundle_hash'] : null,
                    'evidence' => [
                        'outcome' => $outcome->toArray(),
                        'inputs' => array_map(static fn (DecisionInputPackage $p): array => $p->toArray(), $inputs),
                        'hypothesis_priority' => $this->hypothesisPriority->build($request['hypotheses'] ?? []),
                        'strategy_paths_modified' => false,
                        'orders_placed' => 0,
                    ],
                    'as_of' => $asOf,
                ]);
            }

            $generation->update([
                'status' => 'SUCCEEDED',
                'output_hash' => hash('sha256', json_encode([
                    'generation_id' => $generationId,
                    'decision_state' => $outcome->state,
                    'input_generation_ids' => array_values(array_filter(array_map(
                        static fn (DecisionInputPackage $p): ?string => $p->generationId,
                        $inputs
                    ))),
                ], JSON_THROW_ON_ERROR)),
                'row_counts' => ['decision_records' => $record === null ? 0 : 1],
                'metadata' => [
                    'claim_type' => $claimType,
                    'decision_state' => $outcome->state,
                    'reasons' => $outcome->reasons,
                    'missing_inputs' => $outcome->missingInputs,
                    'transition_allowed' => (bool) ($outcome->context['transition_allowed'] ?? false),
                    'strategy_paths_modified' => false,
                    'orders_placed' => 0,
                ],
                'heartbeat_at' => CarbonImmutable::now('UTC'),
                'completed_at' => CarbonImmutable::now('UTC'),
            ]);

            return [
                'status' => 'SUCCEEDED',
                'generation_id' => $generationId,
                'decision' => $record === null ? null : $this->formatRecord($record),
                'outcome' => $outcome->toArray(),
                'idempotent_replay' => false,
            ];
        } catch (\Throwable $e) {
            $generation->update([
                'status' => 'FAILED',
                'error_message' => 'Research calculation unavailable.',
                'heartbeat_at' => CarbonImmutable::now('UTC'),
            ]);

            throw $e;
        }
    }

    /**
     * Read-only view of the latest decision record. Zero writes.
     *
     * @return array<string, mixed>
     */
    public function getLatestDecision(string $accountingScope = 'FULL_LIFECYCLE'): array
    {
        $record = ResearchDecisionRecord::query()
            ->whereIn('generation_id', ResearchGenerationRecord::query()
                ->where('package_code', self::PACKAGE_CODE)
                ->where('accounting_scope', $accountingScope)
                ->select('id'))
            ->latest('created_at')
            ->first();

        if ($record === null) {
            return [
                'status' => 'NO_DECISION_AVAILABLE',
                'message' => 'No research decision record exists yet for scope: '.$accountingScope,
            ];
        }

        return [
            'status' => 'OK',
            'decision' => $this->formatRecord($record),
        ];
    }

    /**
     * Assemble the input packages from the accepted research generations.
     *
     * @return list<DecisionInputPackage>
     */
    public function buildInputPackages(string $accountingScope = 'FULL_LIFECYCLE'): array
    {
        $packages = [];

        foreach (self::CONSUMED_PACKAGES as $packageCode) {
            $generation = ResearchGenerationRecord::query()
                ->where('package_code', $packageCode)
                ->where('accounting_scope', $accountingScope)
                ->whereIn('status', ['SUCCEEDED', 'PARTIAL', 'INSUFFICIENT_DATA', 'FAILED'])
                ->latest('created_at')
                ->first();

            if ($generation === null) {
                continue;
            }

            $packages[] = $this->derivePackage($packageCode, $generation);
        }

        return $packages;
    }

    private function derivePackage(string $packageCode, ResearchGenerationRecord $generation): DecisionInputPackage
    {
        $metadata = is_array($generation->metadata) ? $generation->metadata : [];

        $sampleSufficiency = null;
        $coverageMet = null;
        $reconciled = null;

        switch ($packageCode) {
            case 'P-1':
                // Attribution/accounting package: no ESS requirement is imposed.
                $reconciled = $this->boolOrNull($metadata['reconciled'] ?? ($metadata['summary']['reconciled'] ?? null));
                break;
            case 'P-2':
                $sampleSufficiency = $this->deriveP2SampleSufficiency((string) $generation->id)
                    ?? $this->stringOrNull($metadata['sample_sufficiency'] ?? null);
                $coverageMet = isset($metadata['missing_primary_accounts'])
                    ? ((int) $metadata['missing_primary_accounts'] === 0)
                    : null;
                break;
            case 'P-4':
                $sampleSufficiency = $this->stringOrNull($metadata['sample_sufficiency'] ?? null);
                $coverageMet = $this->boolOrNull($metadata['coverage_met'] ?? null);
                $reconciled = $this->boolOrNull($metadata['reconciled'] ?? null);
                break;
            case 'P-5':
                $sampleSufficiency = $this->stringOrNull($metadata['sample_sufficiency'] ?? null);
                $coverageMet = $this->boolOrNull($metadata['coverage_met'] ?? null);
                break;
        }

        return new DecisionInputPackage(
            packageCode: $packageCode,
            generationId: (string) $generation->id,
            status: (string) $generation->status,
            accountingScope: (string) $generation->accounting_scope,
            asOf: $generation->as_of?->toIso8601String(),
            sampleSufficiency: $sampleSufficiency,
            coverageMet: $coverageMet,
            reconciled: $reconciled,
            provenance: 'RESEARCH_GENERATION',
        );
    }

    private function deriveP2SampleSufficiency(string $generationId): ?string
    {
        $values = StrategyRiskMetricRecord::query()
            ->where('generation_id', $generationId)
            ->pluck('sample_sufficiency')
            ->filter(static fn ($value): bool => is_string($value) && $value !== '')
            ->unique()
            ->values();

        if ($values->isEmpty()) {
            return null;
        }

        return $values->every(static fn (string $value): bool => $value === 'SUFFICIENT')
            ? 'SUFFICIENT'
            : 'INSUFFICIENT_DATA';
    }

    /**
     * @return array<string, mixed>
     */
    private function formatRecord(ResearchDecisionRecord $record): array
    {
        return [
            'id' => (string) $record->id,
            'generation_id' => (string) $record->generation_id,
            'decision_state' => (string) $record->decision_state,
            'previous_state' => $record->previous_state,
            'actor_source' => (string) $record->actor_source,
            'reason' => (string) $record->reason,
            'claim_type' => (string) $record->claim_type,
            'hypothesis' => $record->hypothesis,
            'input_generation_ids' => $record->input_generation_ids,
            'preregistration_hash' => $record->preregistration_hash,
            'evidence_bundle_hash' => $record->evidence_bundle_hash,
            'evidence' => $record->evidence,
            'as_of' => $record->as_of?->toIso8601String(),
            'created_at' => $record->created_at?->toIso8601String(),
        ];
    }

    private function boolOrNull(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value) && in_array(strtolower($value), ['true', '1', 'yes'], true)) {
            return true;
        }
        if (is_string($value) && in_array(strtolower($value), ['false', '0', 'no'], true)) {
            return false;
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
