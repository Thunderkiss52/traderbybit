<?php

declare(strict_types=1);

namespace App\Strategy\Research;

use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\SignalSide;
use App\Strategy\Domain\StrategyInput;
use Brick\Math\BigDecimal;

final readonly class TrendPullbackStrategy extends ResearchStrategy
{
    public function id(): string
    {
        return 'trend_pullback';
    }

    public function description(): string
    {
        return 'EMA trend with ADX, EMA20 pullback, RSI and MACD confirmation.';
    }

    public function evaluate(StrategyInput $input): SignalDecision
    {
        if ($failure = $this->preflight($input)) {
            return $failure;
        }
        $trend = $input->trend->latest();
        $now = $input->entry->latest();
        $previous = $input->entry->previous();
        if (! $trend || ! $now || ! $previous || ! $this->gt($trend->adx, (string) $this->config['adx_min'])) {
            return $this->skip($input, ['trend_filter_rejected']);
        }
        $side = $this->gt($trend->emaFast, $trend->emaSlow) ? SignalSide::Buy : ($this->lt($trend->emaFast, $trend->emaSlow) ? SignalSide::Sell : SignalSide::Skip);
        if ($side === SignalSide::Skip) {
            return $this->skip($input, ['neutral_trend']);
        }
        $setupAligned = $this->setupAligned($input, $side);
        $confirmationAligned = $this->confirmationAligned($input, $side);
        $pullback = $previous->emaEntry !== null && $previous->atr !== null && BigDecimal::of($previous->close)->minus($previous->emaEntry)->abs()->isLessThanOrEqualTo(BigDecimal::of($previous->atr)->multipliedBy((string) $this->config['pullback_atr']));
        $momentum = $side === SignalSide::Buy ? $this->gt($now->rsi, '50') && $this->gt($now->macdHistogram, $previous->macdHistogram) : $this->lt($now->rsi, '50') && $this->lt($now->macdHistogram, $previous->macdHistogram);

        if ($this->confirmationMode() === 'STRICT') {
            if (! $setupAligned) {
                return $this->skip($input, ['thirty_minute_setup_conflicts']);
            }
            if (! $confirmationAligned) {
                return $this->skip($input, ['five_minute_confirmation_conflicts']);
            }
        }

        $score = 25 + ($setupAligned ? 20 : 0) + ($pullback ? 25 : 0) + ($momentum ? 20 : 0) + ($confirmationAligned ? 10 : 0);
        if (! $this->acceptsWeightedScore($score, [$pullback])) {
            return $this->skip($input, ['weighted_confirmation_below_threshold'], $score);
        }

        $reasons = ['hourly_ema_trend', 'ema20_pullback'];
        if ($setupAligned) {
            $reasons[] = 'thirty_minute_setup';
        }
        if ($momentum) {
            $reasons[] = 'rsi_macd_confirmation';
        }
        if ($confirmationAligned) {
            $reasons[] = 'five_minute_confirmation';
        }

        return $this->emit($input, $side, $reasons, score: $score);
    }
}
