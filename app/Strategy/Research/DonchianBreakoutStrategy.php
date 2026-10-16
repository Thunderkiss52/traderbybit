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
            if (! $this->trendAligned($input, SignalSide::Buy)) {
                return $this->skip($input, ['hourly_trend_conflicts']);
            }
            if (! $this->setupAligned($input, SignalSide::Buy)) {
                return $this->skip($input, ['thirty_minute_setup_conflicts']);
            }

            return $this->confirmationAligned($input, SignalSide::Buy)
                ? $this->emit($input, SignalSide::Buy, ['hourly_trend', 'thirty_minute_setup', 'prior_donchian_high_breakout', 'relative_volume_confirmation', 'five_minute_confirmation'])
                : $this->skip($input, ['five_minute_confirmation_conflicts']);
        }
        if ((float) $now->close < $lower) {
            if (! $this->trendAligned($input, SignalSide::Sell)) {
                return $this->skip($input, ['hourly_trend_conflicts']);
            }
            if (! $this->setupAligned($input, SignalSide::Sell)) {
                return $this->skip($input, ['thirty_minute_setup_conflicts']);
            }

            return $this->confirmationAligned($input, SignalSide::Sell)
                ? $this->emit($input, SignalSide::Sell, ['hourly_trend', 'thirty_minute_setup', 'prior_donchian_low_breakout', 'relative_volume_confirmation', 'five_minute_confirmation'])
                : $this->skip($input, ['five_minute_confirmation_conflicts']);
        }

        return $this->skip($input, ['no_donchian_breakout']);
    }
}
