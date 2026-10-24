<?php

declare(strict_types=1);

namespace App\Strategy\Research;

use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\SignalSide;
use App\Strategy\Domain\StrategyInput;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final readonly class VolatilityCompressionBreakoutStrategy extends CandidateStrategy
{
    public function id(): string
    {
        return 'volatility_compression_breakout';
    }

    public function description(): string
    {
        return 'Precommitted volatility compression followed by a close beyond a prior rolling extreme.';
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
        $compression = (int) $this->config['compression_bars'];
        $base = (int) $this->config['base_bars'];
        $recent = $this->meanTrueRange($bars, $index - $compression, $index);
        $baseline = $this->meanTrueRange($bars, $index - $compression - $base, $index - $compression);
        if ($recent === null || $baseline === null || ! $baseline->isPositive()) {
            return $this->skip($input, ['insufficient_candidate_history']);
        }
        if ($recent->isGreaterThan($baseline->multipliedBy((string) $this->config['compression_rate']))) {
            return $this->skip($input, ['volatility_not_compressed']);
        }
        $lookback = (int) $this->config['breakout_lookback'];
        $extremes = $this->priorExtremes($bars, $index - $lookback, $index);
        if ($extremes === null) {
            return $this->skip($input, ['insufficient_candidate_history']);
        }
        [$lowest, $highest] = $extremes;
        $close = BigDecimal::of($bars[$index]->close);
        $side = null;
        $reason = '';
        if ($close->isGreaterThan($highest)) {
            $side = SignalSide::Buy;
            $reason = 'COMPRESSION_BREAKOUT_UP';
        } elseif ($close->isLessThan($lowest)) {
            $side = SignalSide::Sell;
            $reason = 'COMPRESSION_BREAKOUT_DOWN';
        }
        if ($side === null) {
            return $this->skip($input, ['no_compression_breakout']);
        }
        $volumeConfirmed = $this->decimal($bars[$index]->relativeVolume)?->isGreaterThanOrEqualTo('1.5') ?? false;

        return $this->emit(
            $input,
            $side,
            [$reason, 'compression_established_before_breakout_bar'],
            score: $volumeConfirmed ? 85 : 80,
            risks: ['compression_ratio_is_window_sensitive', 'breakout_can_fail_without_follow_through'],
            extraSnapshot: [
                'candidate_features' => [
                    'breakout_level_high' => (string) $highest,
                    'breakout_level_low' => (string) $lowest,
                    'recent_true_range' => (string) $recent,
                    'baseline_true_range' => (string) $baseline,
                    'compression_ratio' => (string) $recent->dividedBy($baseline, 12, RoundingMode::HalfEven),
                ],
            ],
        );
    }
}
