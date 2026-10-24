<?php

declare(strict_types=1);

namespace App\Strategy\Research;

use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\SignalSide;
use App\Strategy\Domain\StrategyInput;
use Brick\Math\BigDecimal;

final readonly class LiquiditySweepReversalStrategy extends CandidateStrategy
{
    public function id(): string
    {
        return 'liquidity_sweep_reversal';
    }

    public function description(): string
    {
        return 'False-break family: sweep of a prior rolling extreme followed by close recapture/rejection.';
    }

    public function evaluate(StrategyInput $input): SignalDecision
    {
        if (($invalid = $this->preflight($input)) !== null) {
            return $invalid;
        }
        $bars = $this->decisionBars($input);
        $index = count($bars) - 1;
        if ($index < $this->minimumBars()) {
            return $this->skip($input, ['insufficient_candidate_history']);
        }
        $lookback = (int) $this->config['level_lookback'];
        $extremes = $this->priorExtremes($bars, $index - $lookback, $index);
        if ($extremes === null) {
            return $this->skip($input, ['insufficient_candidate_history']);
        }
        [$support, $resistance] = $extremes;
        $bar = $bars[$index];
        $low = BigDecimal::of($bar->low);
        $high = BigDecimal::of($bar->high);
        $close = BigDecimal::of($bar->close);
        $margin = BigDecimal::of((string) ($this->config['min_level_margin'] ?? '0'));
        $side = null;
        $reason = '';
        if ($low->isLessThan($support) && $close->isGreaterThan($support) && $support->minus($low)->isGreaterThanOrEqualTo($margin)) {
            $side = SignalSide::Buy;
            $reason = 'PRIOR_SUPPORT_SWEEP_RECAPTURE';
        } elseif ($high->isGreaterThan($resistance) && $close->isLessThan($resistance) && $high->minus($resistance)->isGreaterThanOrEqualTo($margin)) {
            $side = SignalSide::Sell;
            $reason = 'PRIOR_RESISTANCE_SWEEP_REJECTION';
        }
        if ($side === null) {
            return $this->skip($input, ['no_prior_level_sweep_reversal']);
        }
        $volumeConfirmed = $this->decimal($bar->relativeVolume)?->isGreaterThanOrEqualTo('1.2') ?? false;

        return $this->emit(
            $input,
            $side,
            [$reason, 'close_recaptured_the_prior_level'],
            score: $volumeConfirmed ? 85 : 80,
            risks: ['sweep_levels_are_rolling_extremes_not_confirmed_swing_points', 'stop_and_target_are_atr_based_not_structure_based'],
            extraSnapshot: [
                'candidate_features' => [
                    'prior_support' => (string) $support,
                    'prior_resistance' => (string) $resistance,
                    'close' => (string) $close,
                ],
            ],
        );
    }
}
