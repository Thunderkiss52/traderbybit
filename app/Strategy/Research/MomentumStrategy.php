<?php

declare(strict_types=1);

namespace App\Strategy\Research;

use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\SignalSide;
use App\Strategy\Domain\StrategyInput;

final readonly class MomentumStrategy extends ResearchStrategy
{
    public function id(): string
    {
        return 'momentum';
    }

    public function description(): string
    {
        return 'Independent RSI, MACD acceleration, volume and strengthening-ADX momentum strategy.';
    }

    public function evaluate(StrategyInput $input): SignalDecision
    {
        if ($failure = $this->preflight($input)) {
            return $failure;
        }
        $entry = $input->entry->latest();
        $previous = $input->entry->previous();
        $timing = $input->confirmation->latest();
        $timingPrevious = $input->confirmation->previous();
        if ($entry === null || $previous === null || $timing === null || $timingPrevious === null
            || $entry->macdHistogram === null || $previous->macdHistogram === null || $entry->rsi === null) {
            return $this->skip($input, ['momentum_history_unavailable']);
        }

        $buy = $this->gte($entry->rsi, (string) $this->config['rsi_buy_min'])
            && $this->gt($entry->macdHistogram, $previous->macdHistogram)
            && $this->gte($entry->macdHistogram, '0');
        $sell = $this->lte($entry->rsi, (string) $this->config['rsi_sell_max'])
            && $this->lt($entry->macdHistogram, $previous->macdHistogram)
            && $this->lte($entry->macdHistogram, '0');
        $side = $buy ? SignalSide::Buy : ($sell ? SignalSide::Sell : SignalSide::Skip);
        if ($side === SignalSide::Skip) {
            return $this->skip($input, ['no_directional_momentum']);
        }

        $timingAligned = $timing->macdHistogram !== null && $timingPrevious->macdHistogram !== null
            && $timing->rsi !== null && $timing->emaEntry !== null
            && ($side === SignalSide::Buy
                ? $this->gt($timing->macdHistogram, $timingPrevious->macdHistogram) && $this->gte($timing->rsi, '50') && $this->gt($timing->close, $timing->emaEntry)
                : $this->lt($timing->macdHistogram, $timingPrevious->macdHistogram) && $this->lte($timing->rsi, '50') && $this->lt($timing->close, $timing->emaEntry));
        $volume = $this->gte($entry->relativeVolume, (string) $this->config['relative_volume_min']);
        $strengthening = $entry->adx !== null && $previous->adx !== null
            && $this->gte($entry->adx, (string) $this->config['adx_min']) && $this->gt($entry->adx, $previous->adx);
        $setup = $this->setupAligned($input, $side);
        $trend = $this->trendAligned($input, $side);
        $score = 45 + ($timingAligned ? 20 : 0) + ($volume ? 10 : 0) + ($strengthening ? 10 : 0)
            + ($setup ? 10 : 0) + ($trend ? 5 : 0);

        if ($this->confirmationMode() === 'STRICT' && (! $timingAligned || ! $setup)) {
            return $this->skip($input, ['strict_momentum_confirmation_failed'], $score);
        }
        if (! $this->acceptsWeightedScore($score, [true])) {
            return $this->skip($input, ['weighted_momentum_below_threshold'], $score);
        }

        $reasons = ['fifteen_minute_macd_acceleration', 'fifteen_minute_rsi_momentum'];
        foreach ([[$timingAligned, 'five_minute_momentum_timing'], [$volume, 'relative_volume_expansion'],
            [$strengthening, 'adx_strengthening'], [$setup, 'thirty_minute_alignment'], [$trend, 'hourly_alignment']] as [$condition, $reason]) {
            if ($condition) {
                $reasons[] = $reason;
            }
        }

        return $this->emit($input, $side, $reasons, score: $score);
    }
}
