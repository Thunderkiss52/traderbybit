<?php

declare(strict_types=1);

namespace App\Research\Domain\Decision;

use Carbon\CarbonImmutable;

/**
 * PROFIT-1 / P-6 — the research decision gate (§14).
 *
 * The gate is a pure function: it never writes, never changes a strategy and
 * never authorises anything on its own. It evaluates the evidence that is
 * handed to it and returns the state the decision record must carry.
 *
 * Fail-closed rules implemented here:
 *  - a missing, partial, failed, stale, scope-mixed or unreconciled input
 *    blocks the conclusion that depends on it;
 *  - a sample that is not sufficient (or not declared as sufficient) blocks an
 *    owner-review conclusion;
 *  - a preregistration hash and an evidence-bundle hash are mandatory;
 *  - `REJECTED` / `EVIDENCE_NEGATIVE` are only reachable through an explicitly
 *    supplied registered-test outcome, never through missing evidence;
 *  - `OWNER_APPROVED_FOR_SEPARATE_PAPER_EXPERIMENT` requires an explicit human
 *    authorization on top of a previous `ELIGIBLE_FOR_OWNER_REVIEW` state.
 */
final class DecisionGate
{
    public const CLAIM_PROFITABILITY = 'PROFITABILITY_CLAIM';

    public const CLAIM_IMPROVEMENT = 'IMPROVEMENT_CLAIM';

    public const CLAIM_CONCENTRATION_RISK = 'CONCENTRATION_RISK_CLAIM';

    public const CLAIM_COST_STRUCTURE = 'COST_STRUCTURE_CLAIM';

    public const CLAIM_RISK_REJECTED_OPPORTUNITY = 'RISK_REJECTED_OPPORTUNITY_CLAIM';

    /**
     * @var array<string, list<string>>
     */
    public const REQUIRED_PACKAGES = [
        self::CLAIM_PROFITABILITY => ['P-1', 'P-2', 'P-4', 'P-5'],
        self::CLAIM_IMPROVEMENT => ['P-1', 'P-2', 'P-4', 'P-5'],
        self::CLAIM_CONCENTRATION_RISK => ['P-2', 'P-5'],
        self::CLAIM_COST_STRUCTURE => ['P-1', 'P-4'],
        // P-3 is prohibited in this project, so the claim type is recognised but
        // can never pass the P3_NOT_USED gate.
        self::CLAIM_RISK_REJECTED_OPPORTUNITY => [],
    ];

    /**
     * Packages that carry an inferential (statistical) claim and therefore have
     * to meet their own declared sample and coverage requirements.
     *
     * @var list<string>
     */
    public const INFERENTIAL_PACKAGES = ['P-4', 'P-5'];

    /**
     * Packages that must report an explicit reconciliation flag.
     *
     * @var list<string>
     */
    public const RECONCILIATION_REQUIRED_PACKAGES = ['P-1', 'P-4'];

    /**
     * Packages that are attribution/accounting packages and must not be
     * assigned an ESS requirement.
     *
     * @var list<string>
     */
    public const ATTRIBUTION_ONLY_PACKAGES = ['P-1'];

    public const MAX_INPUT_AGE_SECONDS = 86400;

    public const MAX_SAMPLE_AGE_SECONDS = 86400;

    /**
     * @param  list<DecisionInputPackage>  $inputs
     * @param  array<string, mixed>  $request
     */
    public function evaluate(array $inputs, array $request = []): DecisionOutcome
    {
        $now = isset($request['now']) && $request['now'] instanceof CarbonImmutable
            ? $request['now']
            : CarbonImmutable::now('UTC');

        $claimType = (string) ($request['claim_type'] ?? '');
        $previousState = isset($request['previous_state']) && $request['previous_state'] !== null
            ? (string) $request['previous_state']
            : null;
        $preregistrationHash = $this->nullableString($request['preregistration_hash'] ?? null);
        $evidenceBundleHash = $this->nullableString($request['evidence_bundle_hash'] ?? null);
        $testOutcome = $this->nullableString($request['test_outcome'] ?? null);
        $ownerAuthorization = $request['owner_authorization'] ?? null;

        $gateResults = [];
        $reasons = [];
        $missingInputs = [];
        $context = [
            'claim_type' => $claimType,
            'required_packages' => self::REQUIRED_PACKAGES[$claimType] ?? [],
            'inferential_packages' => self::INFERENTIAL_PACKAGES,
            'attribution_only_packages' => self::ATTRIBUTION_ONLY_PACKAGES,
            'max_input_age_seconds' => self::MAX_INPUT_AGE_SECONDS,
            'inputs' => array_map(static fn (DecisionInputPackage $p): array => $p->toArray(), $inputs),
            'previous_state' => $previousState,
        ];

        $blocked = null; // [state, reason]
        $gate = function (string $name, bool $passed, string $detail) use (&$gateResults): bool {
            $gateResults[] = ['gate' => $name, 'passed' => $passed, 'detail' => $detail];

            return $passed;
        };

        // G1 — the claim type must be a known, supported one.
        if (! $gate('CLAIM_TYPE_SUPPORTED', isset(self::REQUIRED_PACKAGES[$claimType]), 'claim_type='.$claimType)) {
            $blocked = [DecisionState::INSUFFICIENT_EVIDENCE, 'UNSUPPORTED_CLAIM_TYPE'];
        }

        // G2 — P-3 is prohibited in this project, and risk-rejected-opportunity
        // conclusions are refused outright.
        if ($blocked === null) {
            $hasP3 = false;
            foreach ($inputs as $input) {
                if ($input->packageCode === 'P-3') {
                    $hasP3 = true;
                    break;
                }
            }
            $passed = ! $hasP3 && $claimType !== self::CLAIM_RISK_REJECTED_OPPORTUNITY;
            if (! $gate('P3_NOT_USED', $passed, $hasP3 ? 'P-3 input supplied' : 'no P-3 input')) {
                $blocked = [DecisionState::INSUFFICIENT_EVIDENCE, 'P3_NOT_AUTHORIZED'];
            }
        }

        // G3 — every required package must be present.
        if ($blocked === null) {
            $required = self::REQUIRED_PACKAGES[$claimType];
            $present = array_map(static fn (DecisionInputPackage $p): string => $p->packageCode, $inputs);
            $missing = array_values(array_diff($required, $present));
            $missingInputs = $missing;
            if (! $gate('REQUIRED_INPUTS_PRESENT', $missing === [], 'missing='.implode(',', $missing))) {
                $blocked = [DecisionState::INSUFFICIENT_EVIDENCE, 'MISSING_INPUT_PACKAGE'];
            }
        }

        // G4 — the inputs must share one accounting scope.
        if ($blocked === null) {
            $scopes = array_values(array_unique(array_map(
                static fn (DecisionInputPackage $p): string => $p->accountingScope,
                $inputs
            )));
            $passed = count($scopes) === 1;
            if (! $gate('SCOPE_CONSISTENT', $passed, 'scopes='.implode(',', $scopes))) {
                $blocked = [DecisionState::INSUFFICIENT_EVIDENCE, 'SCOPE_MIXED_INPUTS'];
            }
        }

        // G5 — every input generation must have succeeded.
        if ($blocked === null) {
            $failed = [];
            $notAccepted = [];
            foreach ($inputs as $input) {
                if ($input->status === 'FAILED') {
                    $failed[] = $input->packageCode;
                } elseif (! $input->isAccepted()) {
                    $notAccepted[] = $input->packageCode.':'.$input->status;
                }
            }
            if ($failed !== []) {
                $gate('INPUT_GENERATIONS_ACCEPTED', false, 'failed='.implode(',', $failed));
                $blocked = [DecisionState::INSUFFICIENT_EVIDENCE, 'INPUT_GENERATION_FAILED'];
            } elseif ($notAccepted !== []) {
                $gate('INPUT_GENERATIONS_ACCEPTED', false, 'not_accepted='.implode(',', $notAccepted));
                $blocked = [DecisionState::COLLECT_MORE_DATA, 'INPUT_NOT_ACCEPTED'];
            } else {
                $gate('INPUT_GENERATIONS_ACCEPTED', true, 'all inputs succeeded');
            }
        }

        // G6 — an explicit "not reconciled" always blocks; P-1 and P-4 have to
        // report reconciliation explicitly.
        if ($blocked === null) {
            $unreconciled = [];
            $notDeclared = [];
            foreach ($inputs as $input) {
                if ($input->reconciled === false) {
                    $unreconciled[] = $input->packageCode;
                } elseif ($input->reconciled === null
                    && in_array($input->packageCode, self::RECONCILIATION_REQUIRED_PACKAGES, true)) {
                    $notDeclared[] = $input->packageCode;
                }
            }
            if ($unreconciled !== []) {
                $gate('INPUTS_RECONCILED', false, 'unreconciled='.implode(',', $unreconciled));
                $blocked = [DecisionState::INSUFFICIENT_EVIDENCE, 'UNRECONCILED_INPUT'];
            } elseif ($notDeclared !== []) {
                $gate('INPUTS_RECONCILED', false, 'not_declared='.implode(',', $notDeclared));
                $blocked = [DecisionState::INSUFFICIENT_EVIDENCE, 'RECONCILIATION_NOT_DECLARED'];
            } else {
                $gate('INPUTS_RECONCILED', true, 'reconciliation ok');
            }
        }

        // G7 — the inputs must not be stale.
        if ($blocked === null) {
            $stale = [];
            $missingAsOf = [];
            foreach ($inputs as $input) {
                if ($input->asOf === null || $input->asOf === '') {
                    $missingAsOf[] = $input->packageCode;

                    continue;
                }
                $age = $now->getTimestamp() - CarbonImmutable::parse($input->asOf, 'UTC')->getTimestamp();
                if ($age > self::MAX_INPUT_AGE_SECONDS) {
                    $stale[] = $input->packageCode.':'.$age.'s';
                }
            }
            if ($missingAsOf !== []) {
                $gate('INPUTS_NOT_STALE', false, 'missing_as_of='.implode(',', $missingAsOf));
                $blocked = [DecisionState::INSUFFICIENT_EVIDENCE, 'INPUT_AS_OF_MISSING'];
            } elseif ($stale !== []) {
                $gate('INPUTS_NOT_STALE', false, 'stale='.implode(',', $stale));
                $blocked = [DecisionState::COLLECT_MORE_DATA, 'INPUT_STALE'];
            } else {
                $gate('INPUTS_NOT_STALE', true, 'all inputs fresh');
            }
        }

        // G8 — P-2 must report a sufficient sample for an inferential claim.
        if ($blocked === null) {
            $p2 = $this->findPackage($inputs, 'P-2');
            $needsSample = in_array($claimType, [self::CLAIM_PROFITABILITY, self::CLAIM_IMPROVEMENT], true);
            if ($p2 === null || ! $needsSample) {
                $gate('P2_SAMPLE_SUFFICIENCY', true, 'not applicable');
            } elseif (! $p2->hasDeclaredSampleSufficiency()) {
                $gate('P2_SAMPLE_SUFFICIENCY', false, 'P-2 did not declare sample sufficiency');
                $blocked = [DecisionState::COLLECT_MORE_DATA, 'SAMPLE_NOT_DECLARED'];
            } elseif (! $p2->isSampleSufficient()) {
                $gate('P2_SAMPLE_SUFFICIENCY', false, 'P-2 sample_sufficiency='.$p2->sampleSufficiency);
                $blocked = [DecisionState::COLLECT_MORE_DATA, 'SAMPLE_INSUFFICIENT'];
            } else {
                $gate('P2_SAMPLE_SUFFICIENCY', true, 'P-2 sample_sufficiency=SUFFICIENT');
            }
        }

        // G9 — every inferential input package must meet its own declared
        // sample and coverage requirements.
        if ($blocked === null) {
            $required = self::REQUIRED_PACKAGES[$claimType];
            $failing = [];
            $notDeclared = [];
            foreach ($required as $packageCode) {
                if (! in_array($packageCode, self::INFERENTIAL_PACKAGES, true)) {
                    continue;
                }
                $package = $this->findPackage($inputs, $packageCode);
                if ($package === null) {
                    continue;
                }
                if (! $package->hasDeclaredSampleSufficiency()) {
                    $notDeclared[] = $packageCode;
                } elseif (! $package->isSampleSufficient()) {
                    $failing[] = $packageCode.':'.$package->sampleSufficiency;
                } elseif ($package->coverageMet === false) {
                    $failing[] = $packageCode.':COVERAGE_NOT_MET';
                }
            }
            if ($notDeclared !== []) {
                $gate('INFERENTIAL_PACKAGES_READY', false, 'not_declared='.implode(',', $notDeclared));
                $blocked = [DecisionState::COLLECT_MORE_DATA, 'INFERENTIAL_SAMPLE_NOT_DECLARED'];
            } elseif ($failing !== []) {
                $gate('INFERENTIAL_PACKAGES_READY', false, 'not_ready='.implode(',', $failing));
                $blocked = [DecisionState::COLLECT_MORE_DATA, 'INFERENTIAL_SAMPLE_INSUFFICIENT'];
            } else {
                $gate('INFERENTIAL_PACKAGES_READY', true, 'inferential packages ready');
            }
        }

        // G10/G11 — the registration and the evidence bundle are mandatory.
        if ($blocked === null) {
            if (! $gate('PREREGISTRATION_PRESENT', $preregistrationHash !== null, 'preregistration_hash='.($preregistrationHash ?? 'MISSING'))) {
                $blocked = [DecisionState::INSUFFICIENT_EVIDENCE, 'PREREGISTRATION_MISSING'];
            }
        }
        if ($blocked === null) {
            if (! $gate('EVIDENCE_BUNDLE_PRESENT', $evidenceBundleHash !== null, 'evidence_bundle_hash='.($evidenceBundleHash ?? 'MISSING'))) {
                $blocked = [DecisionState::INSUFFICIENT_EVIDENCE, 'EVIDENCE_BUNDLE_MISSING'];
            }
        }

        // G12 — a registered test outcome may move the state to a negative one.
        $state = null;
        if ($blocked === null) {
            if ($testOutcome === null) {
                $gate('REGISTERED_TEST_OUTCOME', true, 'no registered test outcome supplied');
            } elseif ($testOutcome === 'REJECT') {
                $gate('REGISTERED_TEST_OUTCOME', true, 'registered decision rule rejected the hypothesis');
                $state = DecisionState::REJECTED;
            } elseif ($testOutcome === 'NEGATIVE') {
                $gate('REGISTERED_TEST_OUTCOME', true, 'registered test provides evidence against');
                $state = DecisionState::EVIDENCE_NEGATIVE;
            } elseif ($testOutcome === 'POSITIVE') {
                $gate('REGISTERED_TEST_OUTCOME', true, 'registered test outcome is positive');
            } else {
                $gate('REGISTERED_TEST_OUTCOME', false, 'unknown test outcome='.$testOutcome);
                $blocked = [DecisionState::INSUFFICIENT_EVIDENCE, 'UNKNOWN_TEST_OUTCOME'];
            }
        }

        // G13 — owner authorisation is only possible on top of an eligible
        // state, and it never changes any strategy.
        if ($blocked === null && $state === null) {
            if ($ownerAuthorization === null) {
                $gate('OWNER_AUTHORISATION', true, 'no owner authorisation supplied');
                $state = DecisionState::ELIGIBLE_FOR_OWNER_REVIEW;
            } else {
                $actor = is_array($ownerAuthorization) ? ($ownerAuthorization['actor'] ?? null) : null;
                $authorizationHash = is_array($ownerAuthorization) ? ($ownerAuthorization['authorization_hash'] ?? null) : null;
                $isValid = is_string($actor) && $actor !== ''
                    && is_string($authorizationHash) && $authorizationHash !== '';
                $previousIsEligible = $previousState === DecisionState::ELIGIBLE_FOR_OWNER_REVIEW;
                if (! $gate('OWNER_AUTHORISATION', $isValid && $previousIsEligible, 'valid='.($isValid ? '1' : '0').' previous='.($previousState ?? 'NONE'))) {
                    $blocked = [DecisionState::INSUFFICIENT_EVIDENCE, 'OWNER_AUTHORISATION_INVALID'];
                } else {
                    $state = DecisionState::OWNER_APPROVED_FOR_SEPARATE_PAPER_EXPERIMENT;
                }
            }
        }

        if ($blocked !== null) {
            $state = $blocked[0];
            $reasons[] = $blocked[1];
        } elseif ($state === DecisionState::ELIGIBLE_FOR_OWNER_REVIEW) {
            $reasons[] = 'ALL_EVIDENCE_GATES_PASSED';
        } elseif ($state === DecisionState::REJECTED) {
            $reasons[] = 'REGISTERED_DECISION_RULE_REJECTED';
        } elseif ($state === DecisionState::EVIDENCE_NEGATIVE) {
            $reasons[] = 'PREREGISTERED_TEST_PROVIDES_EVIDENCE_AGAINST';
        } elseif ($state === DecisionState::OWNER_APPROVED_FOR_SEPARATE_PAPER_EXPERIMENT) {
            $reasons[] = 'OWNER_EXPLICITLY_AUTHORIZED_SEPARATE_PAPER_EXPERIMENT';
        }

        $transitionAllowed = $previousState === null
            ? DecisionState::isValid($state)
            : DecisionState::canTransition($previousState, $state);

        $context['transition_allowed'] = $transitionAllowed;
        $context['target_state'] = $state;

        return new DecisionOutcome(
            state: $state,
            gateResults: $gateResults,
            reasons: $reasons,
            missingInputs: $missingInputs,
            context: $context,
        );
    }

    private function findPackage(array $inputs, string $packageCode): ?DecisionInputPackage
    {
        foreach ($inputs as $input) {
            if ($input->packageCode === $packageCode) {
                return $input;
            }
        }

        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
