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

    public const STATUS_ROLLBACK_REQUIRED = 'ROLLBACK_REQUIRED';

    public const STATUS_NO_TRIGGER_BREACHED = 'NO_TRIGGER_BREACHED';

    public const STATUS_INCOMPLETE_EVIDENCE = 'INCOMPLETE_EVIDENCE';

    /**
     * Evaluate the monitoring metrics against the precommitted rollback
     * triggers.
     *
     * Missing, malformed or ambiguous evidence is never treated as proof that
     * a trigger is safe: it is classified as `unobserved` (missing/null) or
     * `invalid` (wrong type, non-finite number) and makes the overall status
     * `INCOMPLETE_EVIDENCE`. A genuine breach always wins and is preserved
     * even when other observations are unavailable.
     *
     * @param  array<string, mixed>  $monitoring
     * @return array{breached: list<string>, unobserved: list<string>, invalid: list<string>, satisfied: list<string>, status: string}
     */
    public static function evaluateTriggers(array $monitoring): array
    {
        $breached = [];
        $unobserved = [];
        $invalid = [];
        $satisfied = [];

        foreach (self::rollbackTriggers() as $trigger) {
            $id = (string) $trigger['id'];
            $metric = (string) $trigger['metric'];

            if (! array_key_exists($metric, $monitoring) || $monitoring[$metric] === null) {
                $unobserved[] = $id;

                continue;
            }

            $value = $monitoring[$metric];

            if ($metric === 'source_generation_revoked') {
                if (! is_bool($value)) {
                    $invalid[] = $id;

                    continue;
                }

                $breach = $value === true;
            } else {
                $numeric = self::finiteNumber($value);
                if ($numeric === null) {
                    $invalid[] = $id;

                    continue;
                }

                $threshold = (float) $trigger['threshold'];
                $breach = match ($trigger['operator']) {
                    '>=' => $numeric >= $threshold,
                    '>' => $numeric > $threshold,
                    '<' => $numeric < $threshold,
                    '<=' => $numeric <= $threshold,
                    default => false,
                };
            }

            if ($breach) {
                $breached[] = $id;
            } else {
                $satisfied[] = $id;
            }
        }

        return [
            'breached' => $breached,
            'unobserved' => $unobserved,
            'invalid' => $invalid,
            'satisfied' => $satisfied,
            'status' => match (true) {
                $breached !== [] => self::STATUS_ROLLBACK_REQUIRED,
                $unobserved !== [] || $invalid !== [] => self::STATUS_INCOMPLETE_EVIDENCE,
                default => self::STATUS_NO_TRIGGER_BREACHED,
            },
        ];
    }

    private static function finiteNumber(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }
}
