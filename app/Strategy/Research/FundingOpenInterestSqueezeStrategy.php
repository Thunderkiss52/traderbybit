<?php

declare(strict_types=1);

namespace App\Strategy\Research;

use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\SignalSide;
use App\Strategy\Domain\StrategyInput;
use Brick\Math\BigDecimal;

final readonly class FundingOpenInterestSqueezeStrategy extends CandidateStrategy
{
    public function id(): string
    {
        return 'funding_open_interest_squeeze';
    }

    public function description(): string
    {
        return 'Funding and open-interest crowding reversal: a crowded side that fails to extend the price move is assumed to squeeze.';
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
        $context = $input->marketContext;
        $funding = $this->decimal($context?->fundingRate);
        $openInterestChange = $this->decimal($context?->openInterestChange);
        if ($funding === null || $openInterestChange === null) {
            return $this->skip($input, ['funding_or_open_interest_unavailable']);
        }
        $threshold = BigDecimal::of((string) $this->config['funding_rate_threshold']);
        $openInterestMinimum = BigDecimal::of((string) $this->config['open_interest_min_change']);
        if ($funding->abs()->isLessThan($threshold) || $openInterestChange->isLessThanOrEqualTo($openInterestMinimum)) {
            return $this->skip($input, ['crowding_conditions_not_met']);
        }
        $lookback = (int) $this->config['price_momentum_lookback'];
        if ($index - $lookback < 0) {
            return $this->skip($input, ['insufficient_candidate_history']);
        }
        $close = BigDecimal::of($bars[$index]->close);
        $priceMove = $close->minus(BigDecimal::of($bars[$index - $lookback]->close));
        if ($priceMove->abs()->isLessThan(BigDecimal::of((string) $this->config['price_momentum_threshold']))) {
            return $this->skip($input, ['price_momentum_below_threshold']);
        }

        $continuation = strtoupper((string) ($this->config['mode'] ?? 'CROWDING_REVERSAL')) === 'CROWDING_CONTINUATION';
        $side = null;
        $reason = '';
        if ($continuation) {
            if ($funding->isPositive() && $priceMove->isPositive()) {
                $side = SignalSide::Buy;
                $reason = 'FUNDING_CROWD_LONG_CONTINUATION';
            } elseif ($funding->isNegative() && $priceMove->isNegative()) {
                $side = SignalSide::Sell;
                $reason = 'FUNDING_CROWD_SHORT_CONTINUATION';
            }
        } else {
            if ($funding->isPositive() && $priceMove->isNegative()) {
                $side = SignalSide::Buy;
                $reason = 'FUNDING_CROWD_LONG_FAILED_SQUEEZE';
            } elseif ($funding->isNegative() && $priceMove->isPositive()) {
                $side = SignalSide::Sell;
                $reason = 'FUNDING_CROWD_SHORT_FAILED_SQUEEZE';
            }
        }
        if ($side === null) {
            return $this->skip($input, ['crowding_has_not_failed_to_extend']);
        }

        $extreme = $funding->abs()->isGreaterThanOrEqualTo($threshold->multipliedBy(2));

        return $this->emit(
            $input,
            $side,
            [$reason, 'funding_rate_extreme', 'open_interest_rising', 'price_momentum_failure'],
            score: $extreme ? 85 : 80,
            risks: ['funding_is_context_not_a_directional_forecast', 'open_interest_snapshot_may_lag_the_decision'],
            extraSnapshot: [
                'candidate_features' => [
                    'funding_rate' => (string) $funding,
                    'open_interest_change' => (string) $openInterestChange,
                    'price_move' => (string) $priceMove,
                    'mode' => $continuation ? 'CROWDING_CONTINUATION' : 'CROWDING_REVERSAL',
                ],
            ],
        );
    }
}
