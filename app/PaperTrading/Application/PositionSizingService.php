<?php

declare(strict_types=1);

namespace App\PaperTrading\Application;

use App\PaperTrading\Domain\InstrumentConstraints;
use App\PaperTrading\Domain\PositionSize;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final readonly class PositionSizingService
{
    public function __construct(private array $config) {}

    /** @param array{taker_rate: string, slippage_rate: string}|null $executionCosts */
    public function calculate(string $equity, string $riskPercent, string $entry, string $stop, InstrumentConstraints $limits, ?array $executionCosts = null): PositionSize
    {
        $d = static fn (string $v): BigDecimal => BigDecimal::of($v);
        $costs = $executionCosts ?? ['taker_rate' => (string) $this->config['fees']['taker_rate'], 'slippage_rate' => (string) $this->config['slippage_rate']];
        foreach (['taker_rate', 'slippage_rate'] as $key) {
            if (! is_string($costs[$key] ?? null) || ! preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $costs[$key])) {
                return new PositionSize(false, null, '0', null, null, 'invalid_execution_costs');
            }
        }
        foreach ([$limits->qtyStep, $limits->minOrderQty, $limits->maxOrderQty, $limits->minNotionalValue] as $value) {
            if ($d($value)->isLessThanOrEqualTo(BigDecimal::zero())) {
                return new PositionSize(false, null, '0', null, null, 'invalid_instrument_constraints');
            }
        }
        if ($d($equity)->isLessThanOrEqualTo(0) || $d($riskPercent)->isLessThanOrEqualTo(0)
            || $d($riskPercent)->isGreaterThan('0.50') || $d($entry)->isLessThanOrEqualTo(0)
            || $d($stop)->isLessThanOrEqualTo(0)) {
            return new PositionSize(false, null, '0', null, null, 'invalid_sizing_inputs');
        }
        $riskCapital = $d($equity)->multipliedBy($riskPercent)->dividedBy(100, 24, RoundingMode::HalfEven);
        $fee = $costs['taker_rate'];
        $slippage = $costs['slippage_rate'];
        $effectiveLoss = $d($entry)->minus($stop)->abs()
            ->plus($d($entry)->multipliedBy($slippage))
            ->plus($d($stop)->multipliedBy($slippage))
            ->plus($d($entry)->multipliedBy($fee))
            ->plus($d($stop)->multipliedBy($fee));
        if ($effectiveLoss->isZero()) {
            return new PositionSize(false, null, (string) $riskCapital, null, null, 'zero_effective_loss');
        }
        $step = $d($limits->qtyStep);
        $quantity = $riskCapital->dividedBy($effectiveLoss, 24, RoundingMode::Floor)->dividedBy($step, 0, RoundingMode::Floor)->multipliedBy($step);
        $notional = $quantity->multipliedBy($entry);
        if ($quantity->isLessThan($d($limits->minOrderQty)) || $notional->isLessThan($d($limits->minNotionalValue))) {
            return new PositionSize(false, (string) $quantity, (string) $riskCapital, (string) $effectiveLoss, (string) $notional, 'below_minimum_order');
        }
        if ($quantity->isGreaterThan($d($limits->maxOrderQty))) {
            return new PositionSize(false, (string) $quantity, (string) $riskCapital, (string) $effectiveLoss, (string) $notional, 'above_maximum_quantity');
        }
        $maxNotional = $d($equity)->multipliedBy(BigDecimal::min(
            $d((string) $this->config['portfolio']['maximum_notional_percent']), $d('200'),
        ))->dividedBy(100, 24, RoundingMode::HalfEven);
        $maxLeverageNotional = $d($equity)->multipliedBy(BigDecimal::min(
            $d((string) $this->config['portfolio']['maximum_model_leverage']), $d('2.00'),
        ));
        if ($notional->isGreaterThan($maxNotional) || $notional->isGreaterThan($maxLeverageNotional)) {
            return new PositionSize(false, (string) $quantity, (string) $riskCapital, (string) $effectiveLoss, (string) $notional, 'notional_limit_exceeded');
        }

        return new PositionSize(true, (string) $quantity, (string) $riskCapital, (string) $effectiveLoss, (string) $notional, qtyStep: $limits->qtyStep);
    }
}
