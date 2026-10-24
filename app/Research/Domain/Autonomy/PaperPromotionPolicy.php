<?php

declare(strict_types=1);

namespace App\Research\Domain\Autonomy;

/**
 * A2 — the Gate-6 gated, paper-only promotion decision.
 *
 * The policy reuses the thresholds from `config('research.autonomy.gates')`
 * and never weakens them. It fails closed: without a prospective shadow
 * history the verdict is `NOT_ELIGIBLE / SHADOW_HISTORY_ABSENT`, which is the
 * correct outcome of an unattended build. Nothing in this class can change a
 * strategy, a risk budget or an account: it only produces a verdict.
 */
final class PaperPromotionPolicy
{
    public const VERDICT_ELIGIBLE = 'ELIGIBLE';

    public const VERDICT_NOT_ELIGIBLE = 'NOT_ELIGIBLE';

    public const VERDICT_BLOCKED_FLAG_OFF = 'BLOCKED_FLAG_OFF';

    public const VERDICT_BLOCKED_KILL_SWITCH = 'BLOCKED_KILL_SWITCH';

    public const REASON_SHADOW_HISTORY_ABSENT = 'SHADOW_HISTORY_ABSENT';

    public const REASON_FLAG_OFF = 'PAPER_PROMOTION_FLAG_OFF';

    public const REASON_KILL_SWITCH = 'KILL_SWITCH_ACTIVE';

    public function __construct(
        private readonly DataSufficiencyPolicy $sufficiency = new DataSufficiencyPolicy,
    ) {}

    /**
     * @param  array<string, mixed>  $evidence
     * @param  array<string, mixed>  $gates
     * @return array<string, mixed>
     */
    public function evaluate(array $evidence, array $gates, bool $flagEnabled, bool $killSwitchActive = false): array
    {
        $failedGates = [];
        $reasons = [];

        if (! $flagEnabled) {
            return [
                'verdict' => self::VERDICT_BLOCKED_FLAG_OFF,
                'reason' => self::REASON_FLAG_OFF,
                'failed_gates' => ['paper_promotion_flag'],
                'reasons' => [self::REASON_FLAG_OFF],
                'data_sufficiency' => null,
                'shadow_history_present' => $this->shadowHistoryPresent($evidence),
                'kill_switch_active' => $killSwitchActive,
                'approval_content' => PromotionControlPolicy::approvalContent($evidence),
                'research_only' => true,
                'applied' => false,
            ];
        }

        if ($killSwitchActive) {
            return [
                'verdict' => self::VERDICT_BLOCKED_KILL_SWITCH,
                'reason' => self::REASON_KILL_SWITCH,
                'failed_gates' => ['kill_switch'],
                'reasons' => [self::REASON_KILL_SWITCH],
                'data_sufficiency' => null,
                'shadow_history_present' => $this->shadowHistoryPresent($evidence),
                'kill_switch_active' => true,
                'approval_content' => PromotionControlPolicy::approvalContent($evidence),
                'research_only' => true,
                'applied' => false,
            ];
        }

        $observations = [
            'valid_utc_days' => $evidence['valid_utc_days'] ?? null,
            'ess' => $evidence['ess'] ?? null,
            'train_observations' => $evidence['train_observations'] ?? null,
            'oos_observations' => $evidence['oos_observations'] ?? null,
            'shadow_observations' => $evidence['shadow_observations'] ?? null,
            'shadow_days' => $evidence['shadow_days'] ?? null,
            'shadow_trades' => $evidence['shadow_trades'] ?? null,
        ];

        $sufficiency = $this->sufficiency->evaluate($observations, $gates);
        if ($sufficiency['status'] !== DataSufficiencyPolicy::STATUS_SUFFICIENT) {
            $failedGates[] = 'data_sufficiency';
            $reasons = array_merge($reasons, $sufficiency['reasons']);
        }

        if (! $this->shadowHistoryPresent($evidence)) {
            $failedGates[] = 'shadow_history';
            $reasons[] = self::REASON_SHADOW_HISTORY_ABSENT;
        }

        foreach ([
            'net_improvement_lcb' => ['gate' => 'min_net_improvement_lcb', 'comparison' => 'positive'],
            'max_drawdown_degradation_pct' => ['gate' => 'max_drawdown_degradation_pct', 'comparison' => 'maximum'],
            'max_concentration_degradation' => ['gate' => 'max_concentration_degradation', 'comparison' => 'maximum'],
        ] as $metric => $rule) {
            $rawValue = $evidence[$metric] ?? null;
            $value = DataSufficiencyPolicy::finiteNumber($rawValue);
            $limit = DataSufficiencyPolicy::finiteNumber($gates[$rule['gate']] ?? null);

            if ($rawValue === null) {
                $failedGates[] = $metric;
                $reasons[] = 'MISSING_OBSERVATION:'.$metric;
            } elseif ($value === null) {
                $failedGates[] = $metric;
                $reasons[] = 'INVALID_OBSERVATION:'.$metric;
            } elseif ($limit === null) {
                $failedGates[] = $metric;
                $reasons[] = 'INVALID_GATE_THRESHOLD:'.$rule['gate'];
            } elseif ($rule['comparison'] === 'positive' ? $value <= $limit : $value > $limit) {
                $failedGates[] = $metric;
                $reasons[] = 'THRESHOLD_BREACHED:'.$metric;
            }
        }

        $failedGates = array_values(array_unique($failedGates));

        return [
            'verdict' => $failedGates === [] ? self::VERDICT_ELIGIBLE : self::VERDICT_NOT_ELIGIBLE,
            'reason' => $failedGates === [] ? 'ALL_GATE_6_REQUIREMENTS_MET' : (in_array('shadow_history', $failedGates, true) ? self::REASON_SHADOW_HISTORY_ABSENT : 'GATE_6_REQUIREMENTS_NOT_MET'),
            'failed_gates' => $failedGates,
            'reasons' => $reasons === [] ? ['ALL_GATE_6_REQUIREMENTS_MET'] : $reasons,
            'data_sufficiency' => $sufficiency,
            'shadow_history_present' => $this->shadowHistoryPresent($evidence),
            'kill_switch_active' => false,
            'approval_content' => PromotionControlPolicy::approvalContent($evidence),
            'research_only' => true,
            'applied' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function shadowHistoryPresent(array $evidence): bool
    {
        $observations = $evidence['shadow_observations'] ?? null;
        $days = $evidence['shadow_days'] ?? null;
        $trades = $evidence['shadow_trades'] ?? null;

        if ($observations === null || $days === null || $trades === null) {
            return false;
        }

        foreach ([$observations, $days, $trades] as $count) {
            $number = DataSufficiencyPolicy::finiteNumber($count);
            if ($number === null || $number <= 0 || floor($number) !== $number) {
                return false;
            }
        }

        return true;
    }
}
