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
    public function calculate(string $equity, string $riskPercent, string $entry, string $stop, InstrumentConstraints $limits, ?array $executionCosts = null, ?array $sizingSettings = null): PositionSize
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
        try {
            $settings = PaperSizingSettings::normalize($sizingSettings);
        } catch (\DomainException) {
            return new PositionSize(false, null, '0', null, null, 'invalid_sizing_settings');
        }
        if ($settings === null || $settings['mode'] === 'risk_percent') {
            $quantity = $riskCapital->dividedBy($effectiveLoss, 24, RoundingMode::Floor)
                ->dividedBy($step, 0, RoundingMode::Floor)->multipliedBy($step);
        } else {
            $targetNotional = $d($settings['amount']);
            if ($settings['mode'] === 'fixed_margin') {
                $targetNotional = $targetNotional->multipliedBy($settings['leverage']);
            }
            $quantity = $targetNotional->dividedBy($entry, 24, RoundingMode::Floor)
                ->dividedBy($step, 0, RoundingMode::Floor)->multipliedBy($step);
        }
        // Reserve actual worst-case stop loss + estimated round-trip trading costs.
        // Unlike fixed notional, risk_percent intentionally reserves its requested budget.
        $actualRisk = $quantity->multipliedBy($effectiveLoss);
        if ($actualRisk->isGreaterThan($riskCapital)) {
            return new PositionSize(false, (string) $quantity, (string) $actualRisk, (string) $effectiveLoss,
                (string) $quantity->multipliedBy($entry), 'sizing_exceeds_signal_risk_limit');
        }
        if ($settings !== null && $settings['mode'] !== 'risk_percent') {
            $riskCapital = $actualRisk;
        }
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

        $leverage = $settings !== null && isset($settings['leverage']) ? (string) $settings['leverage'] : '1.00';
        $margin = $settings !== null && $settings['mode'] === 'fixed_margin'
            ? (string) $settings['amount']
            : (string) $notional->dividedBy($leverage, 24, RoundingMode::HalfEven);

        return new PositionSize(
            allowed: true,
            quantity: (string) $quantity,
            riskCapital: (string) $riskCapital,
            effectiveLossPerUnit: (string) $effectiveLoss,
            notional: (string) $notional,
            reason: null,
            qtyStep: $limits->qtyStep,
            allocatedMargin: $margin,
            leverage: $leverage,
        );
    }
}
