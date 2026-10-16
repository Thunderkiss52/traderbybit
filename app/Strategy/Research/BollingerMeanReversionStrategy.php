<?php

declare(strict_types=1);

namespace App\Strategy\Research;

use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\SignalSide;
use App\Strategy\Domain\StrategyInput;

final readonly class BollingerMeanReversionStrategy extends ResearchStrategy
{
    public function id(): string
    {
        return 'bollinger_mean_reversion';
    }

    public function description(): string
    {
        return 'Range-only Bollinger re-entry with RSI confirmation.';
    }

    public function evaluate(StrategyInput $input): SignalDecision
    {
        if ($failure = $this->preflight($input)) {
            return $failure;
        }
        $now = $input->entry->latest();
        $previous = $input->entry->previous();
        $trend = $input->trend->latest();
        $setup = $input->setup->latest();
        if (! $now || ! $previous || ! $trend || ! $setup || $trend->adx === null || $setup->adx === null) {
            return $this->skip($input, ['range_regime_unavailable']);
        }
        $trendRange = $this->lt($trend->adx, (string) $this->config['adx_max']);
        $setupRange = $this->lt($setup->adx, (string) $this->config['adx_max']);
        if ($this->confirmationMode() === 'STRICT' && (! $trendRange || ! $setupRange)) {
            return $this->skip($input, ['range_regime_required']);
        }

        $side = null;
        $rsiConfirmed = false;
        if ($previous->bollingerLower !== null && $now->bollingerLower !== null && $this->lt($previous->close, $previous->bollingerLower) && $this->gt($now->close, $now->bollingerLower)) {
            $side = SignalSide::Buy;
            $rsiConfirmed = $this->lt($now->rsi, (string) $this->config['rsi_buy_max']);
        } elseif ($previous->bollingerUpper !== null && $now->bollingerUpper !== null && $this->gt($previous->close, $previous->bollingerUpper) && $this->lt($now->close, $now->bollingerUpper)) {
            $side = SignalSide::Sell;
            $rsiConfirmed = $this->gt($now->rsi, (string) $this->config['rsi_sell_min']);
        }
        if ($side === null) {
            return $this->skip($input, ['bollinger_reentry_not_confirmed']);
        }
        $confirmation = $this->confirmationAligned($input, $side);
        if ($this->confirmationMode() === 'STRICT' && ! $confirmation) {
            return $this->skip($input, ['five_minute_confirmation_conflicts']);
        }
        $score = ($trendRange ? 15 : 0) + ($setupRange ? 20 : 0) + 40 + ($rsiConfirmed ? 15 : 0) + ($confirmation ? 10 : 0);
        if (! $this->acceptsWeightedScore($score, [$trendRange || $setupRange])) {
            return $this->skip($input, ['weighted_confirmation_below_threshold'], $score);
        }
        $reasons = ['closed_back_inside_'.($side === SignalSide::Buy ? 'lower' : 'upper').'_band'];
        if ($trendRange) {
            $reasons[] = 'hourly_range_regime';
        }
        if ($setupRange) {
            $reasons[] = 'thirty_minute_range_regime';
        }
        if ($rsiConfirmed) {
            $reasons[] = $side === SignalSide::Buy ? 'rsi_oversold' : 'rsi_overbought';
        }
        if ($confirmation) {
            $reasons[] = 'five_minute_confirmation';
        }

        return $this->emit($input, $side, $reasons, (string) $this->config['target_r'], $score);
    }
}
