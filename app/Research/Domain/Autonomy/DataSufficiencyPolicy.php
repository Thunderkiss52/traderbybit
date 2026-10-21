<?php

declare(strict_types=1);

namespace App\Research\Domain\Autonomy;

/**
 * A0/A2 — the shared data-sufficiency evaluation.
 *
 * The policy is deliberately fail-closed: a missing observation is a missing
 * observation, never an implicit pass. The thresholds come from
 * `config('research.autonomy.gates')` and are never weakened at runtime.
 */
final class DataSufficiencyPolicy
{
    public const STATUS_SUFFICIENT = 'SUFFICIENT';

    public const STATUS_INSUFFICIENT = 'INSUFFICIENT_DATA';

    /**
     * @param  array<string, mixed>  $observations
     * @param  array<string, mixed>  $gates
     * @return array{status: string, reasons: list<string>, passed: list<string>, missing: list<string>}
     */
    public function evaluate(array $observations, array $gates): array
    {
        $requirements = [
            'min_valid_utc_days' => 'valid_utc_days',
            'min_ess' => 'ess',
            'min_train_observations' => 'train_observations',
            'min_oos_observations' => 'oos_observations',
            'min_shadow_observations' => 'shadow_observations',
            'min_shadow_days' => 'shadow_days',
            'min_shadow_trades' => 'shadow_trades',
        ];

        $passed = [];
        $missing = [];
        $reasons = [];

        foreach ($requirements as $gateKey => $observationKey) {
            if (! array_key_exists($gateKey, $gates)) {
                continue;
            }

            $required = (float) $gates[$gateKey];
            $value = $observations[$observationKey] ?? null;

            if ($value === null) {
                $missing[] = $observationKey;
                $reasons[] = 'MISSING_OBSERVATION:'.$observationKey;

                continue;
            }

            if ((float) $value < $required) {
                $missing[] = $observationKey;
                $reasons[] = 'INSUFFICIENT_OBSERVATION:'.$observationKey.'='.$value.' < '.$gateKey.'='.$required;

                continue;
            }

            $passed[] = $observationKey;
        }

        return [
            'status' => $missing === [] ? self::STATUS_SUFFICIENT : self::STATUS_INSUFFICIENT,
            'reasons' => $reasons,
            'passed' => $passed,
            'missing' => $missing,
        ];
    }
}
