<?php

declare(strict_types=1);

namespace Tests\Unit\Research\Decision;

use App\Research\Domain\Decision\DecisionGate;
use App\Research\Domain\Decision\DecisionInputPackage;
use App\Research\Domain\Decision\DecisionOutcome;
use App\Research\Domain\Decision\DecisionState;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * PROFIT-1 / P-6 — fail-closed behaviour of the research decision gate.
 */
final class DecisionGateTest extends TestCase
{
    private DecisionGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new DecisionGate;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function package(string $code, array $overrides = []): DecisionInputPackage
    {
        return new DecisionInputPackage(
            packageCode: $code,
            generationId: $overrides['generationId'] ?? ($code.'-generation-0001'),
            status: $overrides['status'] ?? 'SUCCEEDED',
            accountingScope: $overrides['accountingScope'] ?? 'FULL_LIFECYCLE',
            asOf: $overrides['asOf'] ?? CarbonImmutable::now('UTC')->subHour()->toIso8601String(),
            sampleSufficiency: $overrides['sampleSufficiency'] ?? null,
            coverageMet: $overrides['coverageMet'] ?? null,
            reconciled: $overrides['reconciled'] ?? null,
            provenance: 'TEST',
        );
    }

    /**
     * @return list<DecisionInputPackage>
     */
    private function completeInputs(): array
    {
        return [
            $this->package('P-1', ['reconciled' => true]),
            $this->package('P-2', ['sampleSufficiency' => 'SUFFICIENT', 'coverageMet' => true]),
            $this->package('P-4', ['sampleSufficiency' => 'SUFFICIENT', 'coverageMet' => true, 'reconciled' => true]),
            $this->package('P-5', ['sampleSufficiency' => 'SUFFICIENT', 'coverageMet' => true]),
        ];
    }

    /**
     * @param  list<DecisionInputPackage>  $inputs
     * @param  array<string, mixed>  $request
     */
    private function evaluate(array $inputs, array $request = []): DecisionOutcome
    {
        return $this->gate->evaluate($inputs, array_merge([
            'claim_type' => DecisionGate::CLAIM_PROFITABILITY,
            'preregistration_hash' => 'prereg-hash-1',
            'evidence_bundle_hash' => 'bundle-hash-1',
            'now' => CarbonImmutable::now('UTC'),
        ], $request));
    }

    public function test_unknown_claim_type_is_refused(): void
    {
        $outcome = $this->evaluate($this->completeInputs(), ['claim_type' => 'MADE_UP_CLAIM']);

        self::assertSame(DecisionState::INSUFFICIENT_EVIDENCE, $outcome->state);
        self::assertContains('UNSUPPORTED_CLAIM_TYPE', $outcome->reasons);
    }

    public function test_missing_required_package_blocks_the_conclusion(): void
    {
        $inputs = array_values(array_filter($this->completeInputs(), static fn (DecisionInputPackage $p): bool => $p->packageCode !== 'P-5'));

        $outcome = $this->evaluate($inputs);

        self::assertSame(DecisionState::INSUFFICIENT_EVIDENCE, $outcome->state);
        self::assertContains('MISSING_INPUT_PACKAGE', $outcome->reasons);
        self::assertSame(['P-5'], $outcome->missingInputs);
    }

    public function test_scope_mixed_inputs_are_refused(): void
    {
        $inputs = $this->completeInputs();
        $inputs[2] = $this->package('P-4', [
            'sampleSufficiency' => 'SUFFICIENT',
            'coverageMet' => true,
            'reconciled' => true,
            'accountingScope' => 'WINDOW_FLOW',
        ]);

        $outcome = $this->evaluate($inputs);

        self::assertSame(DecisionState::INSUFFICIENT_EVIDENCE, $outcome->state);
        self::assertContains('SCOPE_MIXED_INPUTS', $outcome->reasons);
    }

    public function test_partial_input_generation_asks_for_more_data(): void
    {
        $inputs = $this->completeInputs();
        $inputs[1] = $this->package('P-2', ['status' => 'PARTIAL', 'sampleSufficiency' => 'SUFFICIENT']);

        $outcome = $this->evaluate($inputs);

        self::assertSame(DecisionState::COLLECT_MORE_DATA, $outcome->state);
        self::assertContains('INPUT_NOT_ACCEPTED', $outcome->reasons);
    }

    public function test_failed_input_generation_is_insufficient_evidence(): void
    {
        $inputs = $this->completeInputs();
        $inputs[1] = $this->package('P-2', ['status' => 'FAILED', 'sampleSufficiency' => 'SUFFICIENT']);

        $outcome = $this->evaluate($inputs);

        self::assertSame(DecisionState::INSUFFICIENT_EVIDENCE, $outcome->state);
        self::assertContains('INPUT_GENERATION_FAILED', $outcome->reasons);
    }

    public function test_unreconciled_attribution_input_is_refused(): void
    {
        $inputs = $this->completeInputs();
        $inputs[0] = $this->package('P-1', ['reconciled' => false]);

        $outcome = $this->evaluate($inputs);

        self::assertSame(DecisionState::INSUFFICIENT_EVIDENCE, $outcome->state);
        self::assertContains('UNRECONCILED_INPUT', $outcome->reasons);
    }

    public function test_attribution_package_without_a_declared_reconciliation_is_refused(): void
    {
        $inputs = $this->completeInputs();
        $inputs[0] = $this->package('P-1');

        $outcome = $this->evaluate($inputs);

        self::assertSame(DecisionState::INSUFFICIENT_EVIDENCE, $outcome->state);
        self::assertContains('RECONCILIATION_NOT_DECLARED', $outcome->reasons);
    }

    public function test_stale_input_asks_for_more_data(): void
    {
        $inputs = $this->completeInputs();
        $inputs[3] = $this->package('P-5', [
            'sampleSufficiency' => 'SUFFICIENT',
            'coverageMet' => true,
            'asOf' => CarbonImmutable::now('UTC')->subDays(3)->toIso8601String(),
        ]);

        $outcome = $this->evaluate($inputs);

        self::assertSame(DecisionState::COLLECT_MORE_DATA, $outcome->state);
        self::assertContains('INPUT_STALE', $outcome->reasons);
    }

    public function test_insufficient_p2_sample_asks_for_more_data(): void
    {
        $inputs = $this->completeInputs();
        $inputs[1] = $this->package('P-2', ['sampleSufficiency' => 'INSUFFICIENT_DATA']);

        $outcome = $this->evaluate($inputs);

        self::assertSame(DecisionState::COLLECT_MORE_DATA, $outcome->state);
        self::assertContains('SAMPLE_INSUFFICIENT', $outcome->reasons);
    }

    public function test_undeclared_p2_sample_asks_for_more_data(): void
    {
        $inputs = $this->completeInputs();
        $inputs[1] = $this->package('P-2', ['sampleSufficiency' => null]);

        $outcome = $this->evaluate($inputs);

        self::assertSame(DecisionState::COLLECT_MORE_DATA, $outcome->state);
        self::assertContains('SAMPLE_NOT_DECLARED', $outcome->reasons);
    }

    public function test_inferential_package_without_a_declared_sample_requirement_asks_for_more_data(): void
    {
        $inputs = $this->completeInputs();
        $inputs[3] = $this->package('P-5', ['coverageMet' => true]);

        $outcome = $this->evaluate($inputs);

        self::assertSame(DecisionState::COLLECT_MORE_DATA, $outcome->state);
        self::assertContains('INFERENTIAL_SAMPLE_NOT_DECLARED', $outcome->reasons);
    }

    public function test_inferential_package_with_an_insufficient_sample_asks_for_more_data(): void
    {
        $inputs = $this->completeInputs();
        $inputs[2] = $this->package('P-4', ['sampleSufficiency' => 'INSUFFICIENT_DATA', 'reconciled' => true]);

        $outcome = $this->evaluate($inputs);

        self::assertSame(DecisionState::COLLECT_MORE_DATA, $outcome->state);
        self::assertContains('INFERENTIAL_SAMPLE_INSUFFICIENT', $outcome->reasons);
    }

    public function test_missing_preregistration_blocks_the_conclusion(): void
    {
        $outcome = $this->evaluate($this->completeInputs(), ['preregistration_hash' => null]);

        self::assertSame(DecisionState::INSUFFICIENT_EVIDENCE, $outcome->state);
        self::assertContains('PREREGISTRATION_MISSING', $outcome->reasons);
    }

    public function test_missing_evidence_bundle_blocks_the_conclusion(): void
    {
        $outcome = $this->evaluate($this->completeInputs(), ['evidence_bundle_hash' => null]);

        self::assertSame(DecisionState::INSUFFICIENT_EVIDENCE, $outcome->state);
        self::assertContains('EVIDENCE_BUNDLE_MISSING', $outcome->reasons);
    }

    public function test_all_gates_passed_produces_the_owner_review_state_only(): void
    {
        $outcome = $this->evaluate($this->completeInputs());

        self::assertSame(DecisionState::ELIGIBLE_FOR_OWNER_REVIEW, $outcome->state);
        self::assertTrue($outcome->isEligibleForOwnerReview());
        self::assertContains('ALL_EVIDENCE_GATES_PASSED', $outcome->reasons);
    }

    public function test_attribution_package_is_not_imbued_with_an_ess_requirement(): void
    {
        $inputs = $this->completeInputs();
        // P-1 is an attribution package: no sample sufficiency is declared and none is required.
        $inputs[0] = $this->package('P-1', ['reconciled' => true, 'sampleSufficiency' => null]);

        $outcome = $this->evaluate($inputs);

        self::assertSame(DecisionState::ELIGIBLE_FOR_OWNER_REVIEW, $outcome->state);
    }

    public function test_registered_test_outcome_can_reject_the_hypothesis(): void
    {
        $outcome = $this->evaluate($this->completeInputs(), ['test_outcome' => 'REJECT']);

        self::assertSame(DecisionState::REJECTED, $outcome->state);
        self::assertContains('REGISTERED_DECISION_RULE_REJECTED', $outcome->reasons);
    }

    public function test_registered_test_outcome_can_report_negative_evidence(): void
    {
        $outcome = $this->evaluate($this->completeInputs(), ['test_outcome' => 'NEGATIVE']);

        self::assertSame(DecisionState::EVIDENCE_NEGATIVE, $outcome->state);
        self::assertContains('PREREGISTERED_TEST_PROVIDES_EVIDENCE_AGAINST', $outcome->reasons);
    }

    public function test_unknown_test_outcome_is_refused(): void
    {
        $outcome = $this->evaluate($this->completeInputs(), ['test_outcome' => 'MAYBE']);

        self::assertSame(DecisionState::INSUFFICIENT_EVIDENCE, $outcome->state);
        self::assertContains('UNKNOWN_TEST_OUTCOME', $outcome->reasons);
    }

    public function test_p3_input_is_refused(): void
    {
        $inputs = $this->completeInputs();
        $inputs[] = $this->package('P-3');

        $outcome = $this->evaluate($inputs);

        self::assertSame(DecisionState::INSUFFICIENT_EVIDENCE, $outcome->state);
        self::assertContains('P3_NOT_AUTHORIZED', $outcome->reasons);
    }

    public function test_risk_rejected_opportunity_claim_is_refused(): void
    {
        $outcome = $this->evaluate($this->completeInputs(), [
            'claim_type' => DecisionGate::CLAIM_RISK_REJECTED_OPPORTUNITY,
        ]);

        self::assertSame(DecisionState::INSUFFICIENT_EVIDENCE, $outcome->state);
        self::assertContains('P3_NOT_AUTHORIZED', $outcome->reasons);
    }

    public function test_owner_authorisation_requires_a_previous_eligible_state(): void
    {
        $outcome = $this->evaluate($this->completeInputs(), [
            'previous_state' => DecisionState::COLLECT_MORE_DATA,
            'owner_authorization' => ['actor' => 'owner', 'authorization_hash' => 'auth-1'],
        ]);

        self::assertSame(DecisionState::INSUFFICIENT_EVIDENCE, $outcome->state);
        self::assertContains('OWNER_AUTHORISATION_INVALID', $outcome->reasons);
    }

    public function test_owner_authorisation_on_top_of_an_eligible_state_is_recorded(): void
    {
        $outcome = $this->evaluate($this->completeInputs(), [
            'previous_state' => DecisionState::ELIGIBLE_FOR_OWNER_REVIEW,
            'owner_authorization' => ['actor' => 'owner', 'authorization_hash' => 'auth-1'],
        ]);

        self::assertSame(DecisionState::OWNER_APPROVED_FOR_SEPARATE_PAPER_EXPERIMENT, $outcome->state);
        self::assertTrue($outcome->context['transition_allowed']);
    }

    public function test_illegal_transition_is_reported_and_not_silently_applied(): void
    {
        $outcome = $this->evaluate($this->completeInputs(), [
            'previous_state' => DecisionState::REJECTED,
        ]);

        self::assertFalse($outcome->context['transition_allowed']);
    }
}
