<?php

declare(strict_types=1);

namespace App\Exchanges\Application;

/**
 * A technical PAPER fill/settlement contract, independent from operator approval.
 * The existing engine only settles linear USDT-denominated perpetual positions.
 * Spot, inverse and arbitrary settlement currencies need separate implementations.
 */
final class PaperExecutionModelContract
{
    public static function supported(
        string $marketType,
        array $capabilities,
        array $assumptions,
        array $costModel,
        bool $hasAdapter,
    ): bool {
        if (! $hasAdapter || $marketType !== 'linear_perpetual'
            || ($assumptions['execution_model'] ?? null) !== 'NEXT_CANDLE_OPEN'
            || empty($capabilities['has_candles'])) {
            return false;
        }

        // These are base requirements. Live quotes, funding, source identity,
        // warmup and risk/account eligibility are checked independently.
        foreach (['price_precision', 'qty_precision'] as $key) {
            if (! isset($capabilities[$key]) || ! is_int($capabilities[$key])
                || $capabilities[$key] < 0 || $capabilities[$key] > 18) {
                return false;
            }
        }
        foreach (['min_qty', 'min_notional'] as $key) {
            if (! self::validDecimal($capabilities[$key] ?? null, strictlyPositive: true)) {
                return false;
            }
        }
        foreach (['maker_rate', 'taker_rate', 'slippage_rate'] as $key) {
            if (! self::validDecimal($costModel[$key] ?? null, strictlyPositive: false)
                || (float) $costModel[$key] >= 0.1) {
                return false;
            }
        }

        return true;
    }

    private static function validDecimal(mixed $value, bool $strictlyPositive): bool
    {
        if (! is_string($value) && ! is_int($value)) {
            return false;
        }
        $text = (string) $value;
        if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $text) !== 1) {
            return false;
        }

        return $strictlyPositive ? (float) $text > 0 : true;
    }
}
