<?php

declare(strict_types=1);

namespace App\Strategy\Research;

use App\Strategy\Application\LevelInteractionAnalyzer;
use App\Strategy\Application\LevelProbabilityService;
use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\SignalSide;
use App\Strategy\Domain\StrategyInput;

final readonly class SupportResistanceBreakoutStrategy extends ResearchStrategy
{
    public function __construct(array $config, private LevelInteractionAnalyzer $levels, private LevelProbabilityService $probabilities)
    {
        parent::__construct($config);
    }

    public function id(): string
    {
        return 'support_resistance_breakout';
    }

    public function description(): string
    {
        return 'Statistically gated resistance breakout and support breakdown.';
    }

    public function evaluate(StrategyInput $input): SignalDecision
    {
        if ($failure = $this->preflight($input)) {
            return $failure;
        }
        $interaction = $this->levels->analyze($input);
        $event = $interaction['selected_event'] ?? null;
        if (! in_array($event, ['resistance_breakout', 'support_breakdown'], true)) {
            return $this->skip($input, ['no_support_resistance_breakout_trigger']);
        }
        $side = $event === 'resistance_breakout' ? SignalSide::Buy : SignalSide::Sell;
        $estimate = $this->probabilities->estimateFor($input, $event, $interaction);
        if ($estimate['validation_status'] === 'INSUFFICIENT_DATA') {
            return $this->skip($input, ['level_probability_insufficient_data']);
        }
        $success = (float) $estimate['success_probability'];
        $failure = (float) $estimate['failure_probability'];
        $targetR = (float) $this->config['tp1_r'];
        $expectedR = $success * $targetR - $failure - (float) $this->config['modeled_cost_r'];
        if ($success < (float) $this->config['minimum_success_probability'] || $expectedR <= (float) $this->config['minimum_expected_r']) {
            return $this->skip($input, ['level_probability_or_expectancy_rejected'], (int) round($success * 100));
        }

        return $this->emit($input, $side, [$event, 'historical_probability_baseline', 'positive_expected_value_after_costs'], score: (int) round($success * 100));
    }
}
