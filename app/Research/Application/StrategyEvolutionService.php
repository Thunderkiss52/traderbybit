<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\Research\Infrastructure\Persistence\LearningCandidateStrategyRecord;
use Illuminate\Support\Str;

final class StrategyEvolutionService
{
    /**
     * Search parameter space and generate an evolved candidate strategy.
     */
    public function generateCandidate(string $parentStrategyId = 'trend_pullback', ?int $seed = null): LearningCandidateStrategyRecord
    {
        $seed = $seed ?? mt_rand(1000, 999999);
        mt_srand($seed);

        $baseVersion = '1.0.0';
        $candidateVersion = 'v1.'.substr((string) $seed, 0, 4);

        // Define bounded parameter search spaces per parent strategy
        $baseParams = match ($parentStrategyId) {
            'bollinger_mean_reversion' => [
                'period' => 20,
                'std_dev' => 2.0,
                'rsi_period' => 14,
                'rsi_oversold' => 30,
                'rsi_overbought' => 70,
            ],
            'donchian_atr_breakout' => [
                'channel_period' => 20,
                'atr_period' => 14,
                'atr_multiplier' => 2.0,
            ],
            default => [ // trend_pullback
                'ema_fast' => 20,
                'ema_slow' => 50,
                'adx_threshold' => 25.0,
                'atr_multiplier' => 1.5,
            ],
        };

        // Mutate within safe, risk-preserving bounds (never widen SL > 3.0 or remove filters)
        $mutatedParams = $baseParams;
        $parameterDiff = [];

        if ($parentStrategyId === 'bollinger_mean_reversion') {
            $mutatedParams['std_dev'] = round(1.8 + (mt_rand(0, 4) * 0.1), 1);
            $mutatedParams['rsi_oversold'] = 25 + mt_rand(0, 5);
        } elseif ($parentStrategyId === 'donchian_atr_breakout') {
            $mutatedParams['channel_period'] = 15 + mt_rand(0, 10);
            $mutatedParams['atr_multiplier'] = round(1.5 + (mt_rand(0, 5) * 0.2), 1);
        } else {
            $mutatedParams['adx_threshold'] = (float) (20 + mt_rand(0, 6)); // Range [20, 26]
            $mutatedParams['ema_fast'] = 15 + mt_rand(0, 10);
            $mutatedParams['atr_multiplier'] = round(1.2 + (mt_rand(0, 4) * 0.2), 1);
        }

        foreach ($mutatedParams as $k => $v) {
            if ($v !== $baseParams[$k]) {
                $parameterDiff[$k] = [
                    'from' => $baseParams[$k],
                    'to' => $v,
                ];
            }
        }

        $configHash = hash('sha256', json_encode($mutatedParams).':'.$parentStrategyId);
        $hypothesis = "Evolving {$parentStrategyId} with modified parameters: ".json_encode($parameterDiff).' to improve entry sensitivity without exceeding portfolio risk bounds.';

        return LearningCandidateStrategyRecord::query()->create([
            'id' => (string) Str::uuid(),
            'name' => ucwords(str_replace('_', ' ', $parentStrategyId))." [{$candidateVersion}]",
            'parent_strategy_id' => $parentStrategyId,
            'base_version' => $baseVersion,
            'candidate_version' => $candidateVersion,
            'configuration_hash' => $configHash,
            'hypothesis' => $hypothesis,
            'parameters' => $mutatedParams,
            'parameter_diff' => $parameterDiff,
            'search_metadata' => [
                'seed' => $seed,
                'trial_count' => 1,
                'objective' => 'MAX_EXPECTANCY_UNDER_DRAWDOWN_LIMIT',
                'bounds_enforced' => true,
            ],
            'status' => 'RESEARCH_ONLY',
        ]);
    }
}
