<?php

declare(strict_types=1);

namespace App\Strategy\Research;

use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\SignalSide;
use App\Strategy\Domain\StrategyInput;

final readonly class DonchianBreakoutStrategy extends ResearchStrategy
{
    public function id(): string
    {
        return 'donchian_atr_breakout';
    }

    public function description(): string
    {
        return 'Prior-bar Donchian breakout with relative-volume and ATR filters.';
    }

    public function evaluate(StrategyInput $input): SignalDecision
    {
        if ($failure = $this->preflight($input)) {
            return $failure;
        }
        $bars = $input->entry->bars;
        $now = array_pop($bars);
        $period = (int) $this->config['period'];
        if (! $now || count($bars) < $period) {
            return $this->skip($input, ['insufficient_donchian_warmup']);
        }
        $prior = array_slice($bars, -$period);
        $upper = max(array_map(fn ($b) => (float) $b->high, $prior));
        $lower = min(array_map(fn ($b) => (float) $b->low, $prior));
        if ($now->relativeVolume === null || $this->lt($now->relativeVolume, (string) $this->config['relative_volume_min']) || $now->atr === null) {
            return $this->skip($input, ['volume_or_atr_filter_rejected']);
        }
        if ((float) $now->close > $upper) {
            return $this->breakoutDecision($input, SignalSide::Buy, 'prior_donchian_high_breakout');
        }
        if ((float) $now->close < $lower) {
            return $this->breakoutDecision($input, SignalSide::Sell, 'prior_donchian_low_breakout');
        }

        return $this->skip($input, ['no_donchian_breakout']);
    }

    private function breakoutDecision(StrategyInput $input, SignalSide $side, string $breakoutReason): SignalDecision
    {
        $trend = $this->trendAligned($input, $side);
        $setup = $this->setupAligned($input, $side);
        $confirmation = $this->confirmationAligned($input, $side);
        if ($this->confirmationMode() === 'STRICT') {
            if (! $trend) {
                return $this->skip($input, ['hourly_trend_conflicts']);
            }
            if (! $setup) {
                return $this->skip($input, ['thirty_minute_setup_conflicts']);
            }
            if (! $confirmation) {
                return $this->skip($input, ['five_minute_confirmation_conflicts']);
            }
        }
        $score = ($trend ? 20 : 0) + ($setup ? 20 : 0) + 35 + 15 + ($confirmation ? 10 : 0);
        if (! $this->acceptsWeightedScore($score, [$trend || $setup])) {
            return $this->skip($input, ['weighted_confirmation_below_threshold'], $score);
        }
        $reasons = [$breakoutReason, 'relative_volume_confirmation'];
        if ($trend) {
            $reasons[] = 'hourly_trend';
        }
        if ($setup) {
            $reasons[] = 'thirty_minute_setup';
        }
        if ($confirmation) {
            $reasons[] = 'five_minute_confirmation';
        }

        return $this->emit($input, $side, $reasons, score: $score);
    }
}
