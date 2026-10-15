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
        if (! $now || ! $previous || ! $trend || $trend->adx === null || ! $this->lt($trend->adx, (string) $this->config['adx_max'])) {
            return $this->skip($input, ['range_regime_required']);
        }
        if ($previous->bollingerLower !== null && $now->bollingerLower !== null && $this->lt($previous->close, $previous->bollingerLower) && $this->gt($now->close, $now->bollingerLower) && $this->lt($now->rsi, (string) $this->config['rsi_buy_max'])) {
            return $this->emit($input, SignalSide::Buy, ['closed_back_inside_lower_band', 'rsi_oversold'], (string) $this->config['target_r']);
        }
        if ($previous->bollingerUpper !== null && $now->bollingerUpper !== null && $this->gt($previous->close, $previous->bollingerUpper) && $this->lt($now->close, $now->bollingerUpper) && $this->gt($now->rsi, (string) $this->config['rsi_sell_min'])) {
            return $this->emit($input, SignalSide::Sell, ['closed_back_inside_upper_band', 'rsi_overbought'], (string) $this->config['target_r']);
        }

        return $this->skip($input, ['bollinger_reentry_not_confirmed']);
    }
}
