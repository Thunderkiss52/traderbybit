<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\Research\Domain\Autonomy\AllocationProposal;
use App\Research\Domain\Autonomy\CandidateHypothesis;
use App\Research\Domain\Autonomy\CandidateRegistry;
use App\Research\Domain\Autonomy\ChallengerExperiment;
use App\Research\Domain\Autonomy\DataSufficiencyPolicy;
use App\Research\Domain\Autonomy\PaperPromotionPolicy;
use App\Research\Domain\Autonomy\PromotionControlPolicy;
use App\Research\Domain\ResearchCandidateHypothesisRecord;
use App\Research\Domain\ResearchChallengerExperimentRecord;
use App\Research\Domain\ResearchPromotionControlRecord;
use App\Research\Domain\ResearchPromotionDecisionRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * A0-A3 — autonomous paper-research services (overnight spec §8).
 *
 * Everything in this service is research-only:
 *  - it never places an order and never touches an exchange credential;
 *  - it never writes to the eight primary paper accounts, never merges a
 *    ledger and never changes a risk budget;
 *  - the paper-promotion feature flag is OFF by default and the promotion
 *    verdict is fail-closed.
 */
final class AutonomyResearchService
{
    public function __construct(
        private readonly DataSufficiencyPolicy $sufficiency = new DataSufficiencyPolicy,
        private readonly PaperPromotionPolicy $promotion = new PaperPromotionPolicy,
        private readonly AllocationProposal $allocation = new AllocationProposal,
        private readonly AutonomyPermissionService $permissions = new AutonomyPermissionService,
    ) {}

    /**
     * A0 — the immutable candidate registry.
     *
     * @return array<string, mixed>
     */
    public function registry(): array
    {
        $candidates = array_map(
            static fn (CandidateHypothesis $hypothesis): array => $hypothesis->toArray(),
            CandidateRegistry::all()
        );

        return [
            'registry_hash' => CandidateRegistry::hash(),
            'candidate_count' => count($candidates),
            'candidates' => $candidates,
            'familywise_experiment_count' => (int) config('research.autonomy.familywise_experiment_count', 5),
            'paper_promotion_enabled' => $this->permissions->isAllowed('auto_promote_paper'),
            'challenger_execution_enabled' => $this->permissions->isAllowed('start_paper_trials'),
            'note' => 'Research candidates only. They are not strategies, not accounts and they never trade.',
        ];
    }

    /**
     * A0 — the data-sufficiency statement of the current evidence.
     *
     * @param  array<string, mixed>  $observations
     * @return array<string, mixed>
     */
    public function evaluateDataSufficiency(array $observations): array
    {
        $gates = (array) config('research.autonomy.gates', []);

        return $this->sufficiency->evaluate($observations, $gates);
    }

    /**
     * A0 — register a candidate hypothesis (append-only).
     *
     * @return array<string, mixed>
     */
    public function registerHypothesis(string $hypothesisId, ?CarbonImmutable $preregisteredAt = null): array
    {
        $hypothesis = CandidateRegistry::lookup($hypothesisId);
        if ($hypothesis === null) {
            return [
                'status' => 'CANDIDATE_NOT_IN_REGISTRY',
                'hypothesis_id' => $hypothesisId,
            ];
        }

        $registrationHash = $hypothesis->registrationHash();
        $existing = ResearchCandidateHypothesisRecord::query()
            ->where('hypothesis_id', $hypothesis->id)
            ->where('version', $hypothesis->version)
            ->first();

        if ($existing !== null) {
            return [
                'status' => 'ALREADY_REGISTERED',
                'hypothesis_id' => $hypothesis->id,
                'registration_hash' => $existing->registration_hash,
                'idempotent_replay' => true,
            ];
        }

        $record = ResearchCandidateHypothesisRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'hypothesis_id' => $hypothesis->id,
            'version' => $hypothesis->version,
            'family' => $hypothesis->family,
            'kind' => $hypothesis->kind,
            'state' => 'REGISTERED',
            'registration_hash' => $registrationHash,
            'preregistered_at' => $preregisteredAt ?? CarbonImmutable::now('UTC'),
            'payload' => $hypothesis->toArray(),
        ]);

        return [
            'status' => 'REGISTERED',
            'hypothesis_id' => $hypothesis->id,
            'registration_hash' => $registrationHash,
            'record_id' => (string) $record->id,
            'idempotent_replay' => false,
        ];
    }

    /**
     * A1 — record a challenger experiment state transition (append-only).
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function recordExperimentTransition(array $request): array
    {
        $hypothesisId = (string) ($request['hypothesis_id'] ?? '');
        $cohortId = (string) ($request['cohort_id'] ?? ('research_cohort_'.Str::slug($hypothesisId)));
        $experimentId = (string) ($request['experiment_id'] ?? (string) Uuid::uuid4());
        $from = (string) ($request['from_state'] ?? ChallengerExperiment::PROPOSED);
        $to = (string) ($request['to_state'] ?? '');

        $transition = ChallengerExperiment::canTransition($from, $to, [
            'candidate_id' => $hypothesisId,
            'preregistration_hash' => $request['preregistration_hash'] ?? null,
            'data_check_done' => $request['data_check_done'] ?? false,
            'oos_viewed' => $request['oos_viewed'] ?? false,
            'promotion_verdict' => $request['promotion_verdict'] ?? null,
        ]);

        if (! $transition['allowed']) {
            return [
                'status' => 'TRANSITION_REFUSED',
                'reason' => $transition['reason'],
                'from_state' => $from,
                'to_state' => $to,
                'recorded' => false,
            ];
        }

        $record = ResearchChallengerExperimentRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'experiment_id' => $experimentId,
            'hypothesis_id' => $hypothesisId,
            'cohort_id' => $cohortId,
            'state' => $to,
            'previous_state' => $from,
            'reason' => (string) ($request['reason'] ?? $transition['reason']),
            'preregistration_hash' => $request['preregistration_hash'] ?? null,
            'rules_hash' => $request['rules_hash'] ?? null,
            'evidence' => $request['evidence'] ?? null,
            'recorded_at' => CarbonImmutable::now('UTC'),
        ]);

        return [
            'status' => 'RECORDED',
            'recorded' => true,
            'record_id' => (string) $record->id,
            'experiment_id' => $experimentId,
            'hypothesis_id' => $hypothesisId,
            'cohort_id' => $cohortId,
            'from_state' => $from,
            'to_state' => $to,
            'reason' => $transition['reason'],
        ];
    }

    /**
     * A2 — evaluate the paper-only promotion and record the decision.
     *
     * The decision is always research-only: `applied` is false and no
     * strategy, budget or account is touched.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function evaluatePromotion(array $request = []): array
    {
        $evidence = (array) ($request['evidence'] ?? []);
        $gates = (array) config('research.autonomy.gates', []);
        if (config()->has('research.autonomy.paper_promotion_enabled') && config('research.autonomy.paper_promotion_enabled') === false) {
            $flagEnabled = false;
        } else {
            $flagEnabled = $this->permissions->isAllowed('auto_promote_paper');
        }
        $killSwitchActive = $this->killSwitchActive() || $this->permissions->getSettings()->kill_switch_active;

        $verdict = $this->promotion->evaluate($evidence, $gates, $flagEnabled, $killSwitchActive);

        if (($request['persist'] ?? false) !== true) {
            return $verdict;
        }

        $record = ResearchPromotionDecisionRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'experiment_id' => $request['experiment_id'] ?? null,
            'hypothesis_id' => $request['hypothesis_id'] ?? null,
            'verdict' => (string) $verdict['verdict'],
            'reason' => (string) $verdict['reason'],
            'failed_gates' => $verdict['failed_gates'],
            'feature_flag_enabled' => $flagEnabled,
            'applied' => false,
            'evidence' => $evidence,
            'approval_content' => $verdict['approval_content'] ?? null,
            'rollback_triggers' => $verdict['approval_content']['rollback_triggers'] ?? null,
            'kill_switch_active' => $killSwitchActive,
            'reversible' => true,
            'rollback_applied' => false,
            'decided_at' => CarbonImmutable::now('UTC'),
        ]);

        $applied = false;
        $promotion = null;
        if ($verdict['verdict'] === PaperPromotionPolicy::VERDICT_ELIGIBLE
            && $flagEnabled
            && ! $killSwitchActive
            && is_string($request['candidate_id'] ?? null)
            && $request['candidate_id'] !== '') {
            try {
                $promoted = app(PromotionPolicyService::class)->promoteCandidate((string) $request['candidate_id'], 'SYSTEM_AUTONOMOUS');
                $applied = $promoted->status === 'PAPER_PROMOTED';
                $promotion = [
                    'candidate_id' => $promoted->id,
                    'status' => $promoted->status,
                    'paper_account_id' => $promoted->paper_account_id,
                ];
                $record->update(['applied' => $applied]);
            } catch (\Throwable $error) {
                $promotion = ['candidate_id' => (string) $request['candidate_id'], 'status' => 'PROMOTION_FAILED', 'error' => $error->getMessage()];
            }
        }

        return $verdict + ['record_id' => (string) $record->id, 'applied' => $applied, 'promotion' => $promotion];
    }

    /**
     * A2 — activate the paper-only kill switch (append-only).
     *
     * The kill switch can only block a promotion; it never changes a strategy,
     * a budget or an account.
     *
     * @return array<string, mixed>
     */
    public function activateKillSwitch(string $reason, ?string $actor = null): array
    {
        $record = $this->recordControl([
            'promotion_decision_id' => null,
            'action' => PromotionControlPolicy::ACTION_KILL_SWITCH_ACTIVATED,
            'reason' => $reason === '' ? PromotionControlPolicy::REASON_MANUAL : $reason,
            'actor' => $actor,
            'payload' => ['scope' => 'PAPER_ONLY_RESEARCH_PROMOTION'],
        ]);

        return [
            'status' => 'KILL_SWITCH_ACTIVATED',
            'action' => (string) $record->action,
            'reason' => (string) $record->reason,
            'actor' => $record->actor,
            'record_id' => (string) $record->id,
            'kill_switch_active' => true,
            'research_only' => true,
            'applied' => false,
        ];
    }

    /**
     * A2 — release the kill switch.
     *
     * The release is disabled by default: `research.autonomy.kill_switch_release_enabled`
     * must be enabled explicitly, and the release is recorded either way.
     *
     * @return array<string, mixed>
     */
    public function releaseKillSwitch(string $reason, ?string $actor = null): array
    {
        if (! (bool) config('research.autonomy.kill_switch_release_enabled', false)) {
            return [
                'status' => 'RELEASE_REFUSED',
                'reason' => 'KILL_SWITCH_RELEASE_FLAG_OFF',
                'kill_switch_active' => $this->killSwitchActive(),
                'research_only' => true,
                'applied' => false,
            ];
        }

        $record = $this->recordControl([
            'promotion_decision_id' => null,
            'action' => PromotionControlPolicy::ACTION_KILL_SWITCH_RELEASED,
            'reason' => $reason === '' ? 'AUTHORISED_RELEASE' : $reason,
            'actor' => $actor,
            'payload' => ['scope' => 'PAPER_ONLY_RESEARCH_PROMOTION'],
        ]);

        return [
            'status' => 'KILL_SWITCH_RELEASED',
            'action' => (string) $record->action,
            'reason' => (string) $record->reason,
            'actor' => $record->actor,
            'record_id' => (string) $record->id,
            'kill_switch_active' => false,
            'research_only' => true,
            'applied' => false,
        ];
    }

    /**
     * A2 — roll a paper challenger back.
     *
     * The promotion was never applied, so the rollback cannot change an
     * operational state. It is recorded as an append-only control event so
     * that the rollback of a challenger is an auditable, testable path.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function rollbackPromotion(array $request = []): array
    {
        $decisionId = (string) ($request['promotion_decision_id'] ?? '');
        $decision = ResearchPromotionDecisionRecord::query()->where('id', $decisionId)->first();

        if ($decision === null) {
            return [
                'status' => 'ROLLBACK_REFUSED',
                'reason' => 'PROMOTION_DECISION_NOT_FOUND',
                'promotion_decision_id' => $decisionId,
                'research_only' => true,
                'applied' => false,
            ];
        }

        $monitoring = (array) ($request['monitoring'] ?? []);
        $triggers = PromotionControlPolicy::evaluateTriggers($monitoring);

        $record = $this->recordControl([
            'promotion_decision_id' => $decisionId,
            'action' => PromotionControlPolicy::ACTION_ROLLBACK_RECORDED,
            'reason' => (string) ($request['reason'] ?? 'MANUAL_ROLLBACK'),
            'actor' => $request['actor'] ?? null,
            'payload' => [
                'trigger_evaluation' => $triggers,
                'operational_change' => false,
                'paper_ledger_mutated' => false,
            ],
        ]);

        return [
            'status' => 'ROLLBACK_RECORDED',
            'promotion_decision_id' => $decisionId,
            'record_id' => (string) $record->id,
            'trigger_evaluation' => $triggers,
            'operational_change' => false,
            'paper_ledger_mutated' => false,
            'research_only' => true,
            'applied' => false,
        ];
    }

    /**
     * A2 — append a promotion control event.
     *
     * The sequence number makes the control history deterministically
     * ordered: the newest control event is the one with the highest sequence.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function recordControl(array $attributes): ResearchPromotionControlRecord
    {
        return DB::transaction(function () use ($attributes): ResearchPromotionControlRecord {
            $latest = ResearchPromotionControlRecord::query()
                ->orderByDesc('sequence')
                ->lockForUpdate()
                ->first();

            return ResearchPromotionControlRecord::query()->create([
                'id' => Uuid::uuid4()->toString(),
                'sequence' => (int) ($latest?->sequence ?? 0) + 1,
                'promotion_decision_id' => $attributes['promotion_decision_id'] ?? null,
                'action' => (string) $attributes['action'],
                'reason' => (string) $attributes['reason'],
                'actor' => $attributes['actor'] ?? null,
                'payload' => $attributes['payload'] ?? null,
                'created_at' => CarbonImmutable::now('UTC'),
            ]);
        });
    }

    /**
     * A2 — the current kill switch state (the newest control event wins).
     */
    public function killSwitchActive(): bool
    {
        $latest = ResearchPromotionControlRecord::query()
            ->whereIn('action', [
                PromotionControlPolicy::ACTION_KILL_SWITCH_ACTIVATED,
                PromotionControlPolicy::ACTION_KILL_SWITCH_RELEASED,
            ])
            ->orderByDesc('sequence')
            ->first();

        if ($latest === null) {
            return false;
        }

        return (string) $latest->action === PromotionControlPolicy::ACTION_KILL_SWITCH_ACTIVATED;
    }

    /**
     * Read-only view of the promotion control events.
     *
     * @return array<string, mixed>
     */
    public function promotionControls(): array
    {
        return [
            'status' => 'OK',
            'kill_switch_active' => $this->killSwitchActive(),
            'controls' => ResearchPromotionControlRecord::query()->orderBy('created_at')->get()
                ->map(static fn (ResearchPromotionControlRecord $record): array => [
                    'action' => (string) $record->action,
                    'reason' => (string) $record->reason,
                    'actor' => $record->actor,
                    'promotion_decision_id' => $record->promotion_decision_id,
                    'created_at' => $record->created_at?->toIso8601String(),
                ])->all(),
        ];
    }

    /**
     * A3 — research-only allocation proposals.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function proposeAllocations(array $request = []): array
    {
        $current = (array) ($request['current_risk_capital'] ?? []);
        $volatility = (array) ($request['volatility'] ?? []);
        $correlation = (array) ($request['correlation'] ?? []);
        $caps = (array) ($request['caps'] ?? []);
        $budget = (string) ($request['total_risk_budget'] ?? '0');

        if ($current === []) {
            return [
                'status' => 'NO_RISK_CAPITAL_INPUT',
                'research_only' => true,
                'applied' => false,
                'reason' => 'MISSING_OBSERVATION:current_risk_capital',
            ];
        }

        return $this->allocation->propose(
            $current,
            $volatility,
            $correlation,
            $caps,
            $budget,
            (float) config('research.autonomy.allocation.correlation_cluster_threshold', 0.8),
            (float) config('research.autonomy.allocation.max_cluster_share', 0.6),
        );
    }

    /**
     * Read-only view of the recorded experiment transitions.
     *
     * @return array<string, mixed>
     */
    public function experiments(?string $experimentId = null): array
    {
        $query = ResearchChallengerExperimentRecord::query()->orderBy('recorded_at');
        if ($experimentId !== null) {
            $query->where('experiment_id', $experimentId);
        }

        return [
            'status' => 'OK',
            'transitions' => $query->get()->map(static fn (ResearchChallengerExperimentRecord $record): array => [
                'experiment_id' => (string) $record->experiment_id,
                'hypothesis_id' => (string) $record->hypothesis_id,
                'cohort_id' => (string) $record->cohort_id,
                'state' => (string) $record->state,
                'previous_state' => $record->previous_state,
                'reason' => (string) $record->reason,
                'recorded_at' => $record->recorded_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
