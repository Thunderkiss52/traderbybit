<?php

declare(strict_types=1);

namespace App\Research\Domain\Autonomy;

/**
 * A2 — the immutable approval content, the rollback triggers and the kill
 * switch of the paper-only promotion.
 *
 * The class is pure: it produces the approval artefact and evaluates the
 * monitoring metrics against the precommitted rollback triggers. It never
 * changes a strategy, a budget or an account, and the kill switch it reports
 * can only *block* a promotion.
 */
final class PromotionControlPolicy
{
    public const ACTION_KILL_SWITCH_ACTIVATED = 'KILL_SWITCH_ACTIVATED';

    public const ACTION_KILL_SWITCH_RELEASED = 'KILL_SWITCH_RELEASED';

    public const ACTION_ROLLBACK_RECORDED = 'ROLLBACK_RECORDED';

    public const REASON_MANUAL = 'MANUAL_OPERATOR_KILL_SWITCH';

    /**
     * The precommitted rollback triggers of a paper-only promotion.
     *
     * A trigger is evaluated against the monitoring metrics of the promoted
     * challenger. A breach means the promotion must be rolled back.
     *
     * @return list<array<string, mixed>>
     */
    public static function rollbackTriggers(): array
    {
        return [
            [
                'id' => 'ROLLBACK_MAX_DRAWDOWN',
                'metric' => 'max_drawdown_pct',
                'operator' => '>=',
                'threshold' => '5.0',
                'source' => 'research_promotion_decisions.evidence.max_drawdown_pct',
                'action' => 'ROLLBACK_TO_PREVIOUS_PAPER_VERSION',
            ],
            [
                'id' => 'ROLLBACK_NET_PERFORMANCE',
                'metric' => 'net_r_multiple',
                'operator' => '<',
                'threshold' => '0',
                'source' => 'research_promotion_decisions.evidence.net_r_multiple',
                'action' => 'ROLLBACK_TO_PREVIOUS_PAPER_VERSION',
            ],
            [
                'id' => 'ROLLBACK_CONCENTRATION',
                'metric' => 'concentration_ratio',
                'operator' => '>',
                'threshold' => '0.6',
                'source' => 'research_promotion_decisions.evidence.concentration_ratio',
                'action' => 'ROLLBACK_TO_PREVIOUS_PAPER_VERSION',
            ],
            [
                'id' => 'ROLLBACK_EVIDENCE_REVOKED',
                'metric' => 'source_generation_revoked',
                'operator' => '==',
                'threshold' => 'true',
                'source' => 'research_promotion_decisions.source_generation_ids',
                'action' => 'ROLLBACK_TO_PREVIOUS_PAPER_VERSION',
            ],
        ];
    }

    /**
     * The immutable approval content of a promotion decision.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function approvalContent(array $context): array
    {
        return [
            'approved_at' => (string) ($context['approved_at'] ?? ''),
            'source_generation_ids' => array_values((array) ($context['source_generation_ids'] ?? [])),
            'source_hashes' => (array) ($context['source_hashes'] ?? []),
            'rule_sha' => $context['rule_sha'] ?? null,
            'old_paper_version' => $context['old_paper_version'] ?? null,
            'new_paper_version' => $context['new_paper_version'] ?? null,
            'cohort_id' => $context['cohort_id'] ?? null,
            'safety_verdict' => $context['safety_verdict'] ?? null,
            'monitoring_thresholds' => (array) ($context['monitoring_thresholds'] ?? []),
            'rollback_triggers' => self::rollbackTriggers(),
            'reversible' => true,
            'research_only' => true,
            'applied' => false,
            'note' => 'Approval content of a research-only promotion. No strategy, budget or account was changed.',
        ];
    }

    /**
     * Evaluate the monitoring metrics against the precommitted rollback
     * triggers. A missing metric never counts as a breach: the trigger stays
     * `UNOBSERVED` and the promotion is not rolled back by it.
     *
     * @param  array<string, mixed>  $monitoring
     * @return array{breached: list<string>, unobserved: list<string>, satisfied: list<string>, status: string}
     */
    public static function evaluateTriggers(array $monitoring): array
    {
        $breached = [];
        $unobserved = [];
        $satisfied = [];

        foreach (self::rollbackTriggers() as $trigger) {
            $metric = (string) $trigger['metric'];
            $value = $monitoring[$metric] ?? null;

            if ($value === null) {
                $unobserved[] = (string) $trigger['id'];

                continue;
            }

            $breach = match ($trigger['operator']) {
                '>=' => (float) $value >= (float) $trigger['threshold'],
                '>' => (float) $value > (float) $trigger['threshold'],
                '<' => (float) $value < (float) $trigger['threshold'],
                '<=' => (float) $value <= (float) $trigger['threshold'],
                '==' => (string) $value === (string) $trigger['threshold'],
                default => false,
            };

            if ($breach) {
                $breached[] = (string) $trigger['id'];
            } else {
                $satisfied[] = (string) $trigger['id'];
            }
        }

        return [
            'breached' => $breached,
            'unobserved' => $unobserved,
            'satisfied' => $satisfied,
            'status' => $breached === [] ? 'NO_TRIGGER_BREACHED' : 'ROLLBACK_REQUIRED',
        ];
    }
}
