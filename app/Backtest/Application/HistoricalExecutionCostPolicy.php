<?php

declare(strict_types=1);

namespace App\Backtest\Application;

use RuntimeException;

/**
 * A historical simulation must never inherit another exchange's fees or slippage.
 * This is a research-only assumption contract, not proof of execution readiness.
 */
final class HistoricalExecutionCostPolicy
{
    /** @param array<string, mixed> $paper @param array<string, mixed>|null $costModel
     * @return array<string, mixed>
     */
    public static function apply(string $exchange, array $paper, ?string $marketType, ?array $costModel): array
    {
        if ($marketType !== 'linear_perpetual') {
            throw new RuntimeException("Historical execution model unavailable for {$exchange}: unsupported market type.");
        }

        foreach (['maker_rate', 'taker_rate', 'slippage_rate'] as $key) {
            $rate = $costModel[$key] ?? null;
            if ((! is_string($rate) && ! is_int($rate))
                || preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', (string) $rate) !== 1
                || (float) $rate >= 0.1) {
                throw new RuntimeException("Historical execution cost unavailable for {$exchange}: {$key}.");
            }
        }

        $paper['fees']['taker_rate'] = (string) $costModel['taker_rate'];
        $paper['slippage_rate'] = (string) $costModel['slippage_rate'];

        return $paper;
    }
}
