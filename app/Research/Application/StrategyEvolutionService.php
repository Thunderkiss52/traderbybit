<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\Research\Infrastructure\Persistence\LearningCandidateStrategyRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

final class StrategyEvolutionService
{
    /**
     * @return list<string>
     */
    public function families(): array
    {
        return array_values(array_unique((array) config('research.evolution.families', [
            'baseline',
            'trend_pullback',
            'bollinger_mean_reversion',
            'donchian_atr_breakout',
            'regime_switching',
            'momentum',
            'support_resistance_bounce',
            'support_resistance_breakout',
        ])));
    }

    /**
     * Search a bounded parameter space and generate an evolved candidate strategy.
     */
    public function generateCandidate(string $parentStrategyId = 'trend_pullback', ?int $seed = null): LearningCandidateStrategyRecord
    {
        if (! in_array($parentStrategyId, $this->families(), true)) {
            throw new \InvalidArgumentException("Unknown strategy family: {$parentStrategyId}");
        }

        $seed = $seed ?? random_int(1000, 999999);
        $baseParams = $this->baseParameters($parentStrategyId);
        $space = (array) config('research.evolution.spaces.'.$parentStrategyId, []);
        if ($space === []) {
            throw new \RuntimeException("No bounded mutation space configured for {$parentStrategyId}.");
        }

        $mutatedParams = $baseParams;
        $parameterDiff = [];

        mt_srand($seed);
        $keys = array_keys($space);
        $mutationCount = min(count($keys), mt_rand(1, 2));
        shuffle($keys);
        foreach (array_slice($keys, 0, $mutationCount) as $key) {
            $spec = (array) $space[$key];
            $current = (float) Arr::get($baseParams, $key);
            $step = (float) $spec['step'];
            $min = (float) $spec['min'];
            $max = (float) $spec['max'];
            $steps = max(1, (int) floor(($max - $min) / $step));
            $offset = mt_rand(-3, 3);
            $mutated = $current + ($offset * $step);
            if ($mutated < $min) {
                $mutated = $min + (($min - $mutated > 0) ? $step : 0);
            }
            if ($mutated > $max) {
                $mutated = $max;
            }
            $mutated = round($mutated, 4);
            if ($mutated === $current) {
                continue;
            }
            Arr::set($mutatedParams, $key, $mutated);
            $parameterDiff[$key] = ['from' => $current, 'to' => $mutated];
        }

        if ($parameterDiff === []) {
            $key = (string) array_key_first($space);
            $spec = (array) $space[$key];
            $current = (float) Arr::get($baseParams, $key);
            $mutated = min((float) $spec['max'], $current + (float) $spec['step']);
            if ($mutated === $current) {
                $mutated = max((float) $spec['min'], $current - (float) $spec['step']);
            }
            Arr::set($mutatedParams, $key, round($mutated, 4));
            $parameterDiff[$key] = ['from' => $current, 'to' => round($mutated, 4)];
        }

        $configurationHash = hash('sha256', $parentStrategyId.'|'.json_encode($mutatedParams, JSON_THROW_ON_ERROR));
        $candidateVersion = 'v1.'.substr($configurationHash, 0, 8);
        $mutatedParams['version'] = $candidateVersion;
        $configurationHash = hash('sha256', $parentStrategyId.'|'.json_encode($mutatedParams, JSON_THROW_ON_ERROR));
        $hypothesis = "Evolving {$parentStrategyId} with bounded parameter mutation: ".json_encode($parameterDiff, JSON_THROW_ON_ERROR).'. Objective: positive out-of-sample expectancy under the drawdown ceiling.';

        return LearningCandidateStrategyRecord::query()->create([
            'id' => (string) Str::uuid(),
            'name' => ucwords(str_replace('_', ' ', $parentStrategyId))." [{$candidateVersion}]",
            'parent_strategy_id' => $parentStrategyId,
            'base_version' => (string) (config("research.strategies.{$parentStrategyId}.version") ?? config('strategy.version')),
            'candidate_version' => $candidateVersion,
            'configuration_hash' => $configurationHash,
            'hypothesis' => $hypothesis,
            'parameters' => $mutatedParams,
            'parameter_diff' => $parameterDiff,
            'search_metadata' => [
                'seed' => $seed,
                'trial_count' => 1,
                'family' => $parentStrategyId,
                'objective' => 'MAX_EXPECTANCY_UNDER_DRAWDOWN_LIMIT',
                'bounds_enforced' => true,
                'evaluation_status' => 'PENDING',
                'evaluation_attempts' => 0,
            ],
            'status' => 'RESEARCH_ONLY',
        ]);
    }

    /**
     * Build the immutable strategy configuration used to backtest and run a candidate.
     *
     * @return array<string, mixed>
     */
    public function strategyConfig(LearningCandidateStrategyRecord $candidate): array
    {
        $config = (array) config('strategy');
        $parameters = (array) $candidate->parameters;

        if ($candidate->parent_strategy_id === 'baseline') {
            foreach ($parameters as $path => $value) {
                Arr::set($config, (string) $path, $value);
            }
            $config['version'] = (string) $candidate->candidate_version;
            $config['_research_strategy_id'] = 'baseline';

            return $config;
        }

        $research = (array) config('research.strategies.'.$candidate->parent_strategy_id, []);
        $merged = array_replace($research, $parameters);
        $merged['version'] = (string) $candidate->candidate_version;

        $config['version'] = (string) $candidate->candidate_version;
        $config['_research_strategy_id'] = (string) $candidate->parent_strategy_id;
        $config['_research_parameters'] = $merged;
        $config['_research_optimization'] = [
            'grid' => [],
            'max_combinations' => 1,
            'minimum_trades' => 5,
            'drawdown_penalty' => '0.50',
            'stability_penalty' => '0.25',
        ];

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private function baseParameters(string $parentStrategyId): array
    {
        if ($parentStrategyId === 'baseline') {
            $config = (array) config('strategy');

            return [
                'trend.adx_min' => (string) data_get($config, 'trend.adx_min'),
                'price_model.stop_atr' => (string) data_get($config, 'price_model.stop_atr'),
                'triggers.breakout.relative_volume_min' => (string) data_get($config, 'triggers.breakout.relative_volume_min'),
                'structure.minimum_level_space_r' => (string) data_get($config, 'structure.minimum_level_space_r'),
            ];
        }

        $parameters = (array) config('research.strategies.'.$parentStrategyId, []);
        if ($parameters === [] || ! isset($parameters['version'])) {
            throw new \RuntimeException("Missing strategy parameters for {$parentStrategyId}.");
        }
        unset($parameters['version']);

        return $parameters;
    }
}
