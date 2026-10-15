<?php

declare(strict_types=1);

namespace App\Strategy\Research;

use App\Strategy\Domain\StrategyInput;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final readonly class MarketRegimeClassifier
{
    public function __construct(private array $config) {}

    public function classify(StrategyInput $input): string
    {
        $bar = $input->trend->latest();
        $previous = $input->trend->previous();
        if (! $bar || ! $previous || $bar->adx === null || $bar->atr === null || $bar->emaFast === null || $bar->emaSlow === null || BigDecimal::of($bar->close)->isZero()) {
            return 'UNCERTAIN';
        }
        $atrPercent = BigDecimal::of($bar->atr)->dividedBy($bar->close, 12, RoundingMode::HalfEven);
        if ($atrPercent->isGreaterThanOrEqualTo((string) $this->config['high_volatility_atr_percent'])) {
            return 'HIGH_VOLATILITY';
        }
        $separation = BigDecimal::of($bar->emaFast)->minus($bar->emaSlow)->abs()->dividedBy($bar->close, 12, RoundingMode::HalfEven);
        $sloped = $previous->emaFast !== null && ! BigDecimal::of($bar->emaFast)->isEqualTo($previous->emaFast);
        if (BigDecimal::of($bar->adx)->isGreaterThanOrEqualTo((string) $this->config['trend_adx_min']) && $separation->isGreaterThanOrEqualTo((string) $this->config['trend_ema_separation_min']) && $sloped) {
            return 'TREND';
        }
        if (BigDecimal::of($bar->adx)->isLessThan((string) $this->config['range_adx_max'])) {
            return 'RANGE';
        }

        return 'UNCERTAIN';
    }
}
