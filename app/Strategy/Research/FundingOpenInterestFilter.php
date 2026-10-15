<?php

declare(strict_types=1);

namespace App\Strategy\Research;

use App\Strategy\Domain\SignalSide;
use App\Strategy\Domain\StrategyInput;
use Brick\Math\BigDecimal;

final readonly class FundingOpenInterestFilter
{
    public function __construct(private array $config) {}

    public function rejection(StrategyInput $input, SignalSide $side): ?string
    {
        $context = $input->marketContext;
        if (! $context || $context->observedAt > $input->decisionTime) {
            return 'context_unavailable_as_of_decision';
        }
        if ($context->fundingRate !== null && BigDecimal::of($context->fundingRate)->abs()->isGreaterThan((string) $this->config['funding_extreme'])) {
            if (($side === SignalSide::Buy && BigDecimal::of($context->fundingRate)->isPositive()) || ($side === SignalSide::Sell && BigDecimal::of($context->fundingRate)->isNegative())) {
                return 'crowded_funding_direction';
            }
        }
        if ($context->openInterestChange !== null && BigDecimal::of($context->openInterestChange)->abs()->isGreaterThan((string) $this->config['open_interest_change_extreme'])) {
            return 'extreme_open_interest_change';
        }

        return null;
    }
}
