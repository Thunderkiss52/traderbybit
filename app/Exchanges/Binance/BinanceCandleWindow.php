<?php

declare(strict_types=1);

namespace App\Exchanges\Binance;

/**
 * A shared, deterministic market-history readiness gate for Binance PAPER
 * activation and the 13x2 research portability report. Input is newest first.
 * Only timestamp integrity is asserted here; source-specific OHLC validation
 * happens during ingestion, before a candle is persisted.
 */
final class BinanceCandleWindow
{
    public static function status(array $newestFirst, int $intervalSeconds, int $nowUtc, int $required = 220): string
    {
        if ($intervalSeconds <= 0 || $required < 2 || count($newestFirst) !== $required) {
            return 'CANDLE_HISTORY_INSUFFICIENT';
        }
        foreach ($newestFirst as $i => $openedAt) {
            if (! is_int($openedAt) || $openedAt < 0) {
                return 'CANDLE_HISTORY_INVALID_TIMESTAMP';
            }
            // A perfectly contiguous sequence can still be poisoned if every
            // candle starts off the exchange's UTC timeframe grid.
            if ($openedAt % $intervalSeconds !== 0) {
                return 'CANDLE_HISTORY_MISALIGNED';
            }
            if ($i > 0 && $intervalSeconds !== $newestFirst[$i - 1] - $openedAt) {
                return 'CANDLE_HISTORY_GAPPED';
            }
        }
        $age = $nowUtc - $newestFirst[0];
        // An incomplete candle must never be accepted as a closed bar even if
        // a poisoned database row carries is_closed=true. The newest complete
        // bar may be one interval late, allowing bounded ingestion latency.
        if ($age < $intervalSeconds || $age > 2 * $intervalSeconds + 300) {
            return 'CANDLE_HISTORY_STALE_OR_FUTURE';
        }

        return 'READY';
    }
}
