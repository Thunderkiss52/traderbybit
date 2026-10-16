<?php

declare(strict_types=1);

namespace App\Strategy\Research;

use App\Strategy\Application\LevelInteractionAnalyzer;
use App\Strategy\Application\LevelProbabilityService;
use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\SignalSide;
use App\Strategy\Domain\StrategyInput;

final readonly class SupportResistanceBounceStrategy extends ResearchStrategy
{
    public function __construct(array $config, private LevelInteractionAnalyzer $levels, private LevelProbabilityService $probabilities)
    {
        parent::__construct($config);
    }

    public function id(): string
    {
        return 'support_resistance_bounce';
    }

    public function description(): string
    {
        return 'Statistically gated support bounce and resistance rejection.';
    }

    public function evaluate(StrategyInput $input): SignalDecision
    {
        if ($failure = $this->preflight($input)) {
            return $failure;
        }
        $interaction = $this->levels->analyze($input);
        $event = $interaction['selected_event'] ?? null;
        if (! in_array($event, ['support_rejection', 'resistance_rejection'], true)) {
            return $this->skip($input, ['no_support_resistance_bounce_trigger']);
        }
        $side = $event === 'support_rejection' ? SignalSide::Buy : SignalSide::Sell;

        return $this->probabilityDecision($input, $side, $event);
    }

    private function probabilityDecision(StrategyInput $input, SignalSide $side, string $event): SignalDecision
    {
        $estimate = $this->probabilities->estimate($input->symbol, $event, $input->decisionTime);
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
