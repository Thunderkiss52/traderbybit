<?php

declare(strict_types=1);

namespace App\Research\Domain\CostMfe;

use Carbon\CarbonImmutable;

/**
 * PROFIT-1 / P-4 — observed-position MFE/MAE diagnostic (§9.2).
 *
 * An ex-post diagnostic over the actual observed holding interval, bounded by
 * the selected `as_of`. Undefined values are NULL with a reason, never zero.
 */
final class MfeMaeResult
{
    public const STATUS_COMPUTED = 'COMPUTED';

    public const STATUS_NO_CANDLES = 'NO_ELIGIBLE_CANDLES';

    /**
     * @param  list<string>  $sourceCandleIds
     * @param  list<string>  $sourceCandleCloseTimes
     */
    public function __construct(
        public readonly string $positionId,
        public readonly string $accountId,
        public readonly string $symbol,
        public readonly string $side,
        public readonly string $entryReference,
        public readonly string $quantity,
        public readonly CarbonImmutable $holdingStart,
        public readonly CarbonImmutable $holdingEnd,
        public readonly string $status,
        public readonly ?string $mfePrice,
        public readonly ?string $maePrice,
        public readonly ?string $mfeUsdt,
        public readonly ?string $maeUsdt,
        public readonly ?string $mfeR,
        public readonly ?string $maeR,
        public readonly ?string $mfeCandleId,
        public readonly ?string $mfeCandleCloseTime,
        public readonly ?string $maeCandleId,
        public readonly ?string $maeCandleCloseTime,
        public readonly int $candleCount,
        public readonly bool $ambiguousPath,
        public readonly ?string $ambiguityReason,
        public readonly array $sourceCandleIds,
        public readonly array $sourceCandleCloseTimes,
        public readonly ?string $riskReason,
        public readonly string $limitations = 'OHLC_DERIVED_ESTIMATE_NOT_TICK_LEVEL_PATH_TRUTH',
    ) {}
}
