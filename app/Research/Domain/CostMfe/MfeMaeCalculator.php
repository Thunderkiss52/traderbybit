<?php

declare(strict_types=1);

namespace App\Research\Domain\CostMfe;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * PROFIT-1 / P-4 — observed-position MFE/MAE calculator (§9.2).
 *
 * Rules (deterministic, no future data):
 *  - only closed candles whose close time lies strictly inside the observed
 *    holding interval [holdingStart, holdingEnd) and is not after `asOf`;
 *  - MFE = favourable price excursion, MAE = adverse price excursion, both
 *    clamped at 0 because an excursion cannot be negative;
 *  - R multiples use the initial stop distance (risk per unit) and are NULL
 *    when the stop is missing or invalid;
 *  - ambiguity is flagged when a single candle could have touched both the
 *    stop and the target, because the intra-candle sequence is unknown.
 */
final class MfeMaeCalculator
{
    private const SCALE = 12;

    public function calculate(
        string $positionId,
        string $accountId,
        string $symbol,
        string $side,
        string $entryReference,
        string $quantity,
        ?string $stopLoss,
        ?string $takeProfit,
        CarbonImmutable $holdingStart,
        CarbonImmutable $holdingEnd,
        array $candles,
        CarbonImmutable $asOf,
    ): MfeMaeResult {
        $isBuy = strtoupper($side) === 'BUY';

        $eligible = [];
        foreach ($candles as $candle) {
            if (! $candle instanceof PriceCandle) {
                continue;
            }

            if ($candle->symbol !== $symbol) {
                continue;
            }

            if ($candle->closeTime->lessThanOrEqualTo($holdingStart)) {
                continue;
            }

            if (! $candle->closeTime->lessThan($holdingEnd)) {
                continue;
            }

            if ($candle->closeTime->greaterThan($asOf)) {
                continue;
            }

            $eligible[] = $candle;
        }

        usort($eligible, static fn (PriceCandle $a, PriceCandle $b): int => $a->closeTime->getTimestamp() <=> $b->closeTime->getTimestamp()
            ?: strcmp($a->candleId, $b->candleId));

        $candleIds = [];
        $candleTimes = [];
        $mfeCandle = null;
        $maeCandle = null;
        $mfeExcursion = BigDecimal::zero()->toScale(self::SCALE, RoundingMode::HalfEven);
        $maeExcursion = BigDecimal::zero()->toScale(self::SCALE, RoundingMode::HalfEven);
        $ambiguous = false;
        $ambiguityReason = null;

        $entry = BigDecimal::of($entryReference);

        foreach ($eligible as $candle) {
            $candleIds[] = $candle->candleId;
            $candleTimes[] = $candle->closeTime->toIso8601String();

            $high = BigDecimal::of($candle->high);
            $low = BigDecimal::of($candle->low);

            $favourable = $isBuy ? $high->minus($entry) : $entry->minus($low);
            $adverse = $isBuy ? $entry->minus($low) : $high->minus($entry);

            if ($favourable->isPositive() && $favourable->isGreaterThan($mfeExcursion)) {
                $mfeExcursion = $favourable;
                $mfeCandle = $candle;
            }

            if ($adverse->isPositive() && $adverse->isGreaterThan($maeExcursion)) {
                $maeExcursion = $adverse;
                $maeCandle = $candle;
            }

            if (! $ambiguous) {
                $reason = $this->ambiguityReason($candle, $isBuy, $stopLoss, $takeProfit);
                if ($reason !== null) {
                    $ambiguous = true;
                    $ambiguityReason = $reason;
                }
            }
        }

        if ($eligible === []) {
            return new MfeMaeResult(
                positionId: $positionId,
                accountId: $accountId,
                symbol: $symbol,
                side: strtoupper($side),
                entryReference: $entryReference,
                quantity: $quantity,
                holdingStart: $holdingStart,
                holdingEnd: $holdingEnd,
                status: MfeMaeResult::STATUS_NO_CANDLES,
                mfePrice: null,
                maePrice: null,
                mfeUsdt: null,
                maeUsdt: null,
                mfeR: null,
                maeR: null,
                mfeCandleId: null,
                mfeCandleCloseTime: null,
                maeCandleId: null,
                maeCandleCloseTime: null,
                candleCount: 0,
                ambiguousPath: false,
                ambiguityReason: null,
                sourceCandleIds: [],
                sourceCandleCloseTimes: [],
                riskReason: 'NO_ELIGIBLE_CANDLES',
            );
        }

        $qty = BigDecimal::of($quantity);
        $riskPerUnit = $this->riskPerUnit($entry, $stopLoss, $isBuy);
        $riskReason = null;
        if ($riskPerUnit === null) {
            $riskReason = 'MISSING_OR_INVALID_STOP';
        }

        return new MfeMaeResult(
            positionId: $positionId,
            accountId: $accountId,
            symbol: $symbol,
            side: strtoupper($side),
            entryReference: $entryReference,
            quantity: $quantity,
            holdingStart: $holdingStart,
            holdingEnd: $holdingEnd,
            status: MfeMaeResult::STATUS_COMPUTED,
            mfePrice: $this->normalize($mfeExcursion),
            maePrice: $this->normalize($maeExcursion),
            mfeUsdt: $this->normalize($mfeExcursion->multipliedBy($qty)),
            maeUsdt: $this->normalize($maeExcursion->multipliedBy($qty)),
            mfeR: $riskPerUnit === null ? null : $this->normalize($mfeExcursion->dividedBy($riskPerUnit, self::SCALE, RoundingMode::HalfEven)),
            maeR: $riskPerUnit === null ? null : $this->normalize($maeExcursion->dividedBy($riskPerUnit, self::SCALE, RoundingMode::HalfEven)),
            mfeCandleId: $mfeCandle?->candleId,
            mfeCandleCloseTime: $mfeCandle?->closeTime->toIso8601String(),
            maeCandleId: $maeCandle?->candleId,
            maeCandleCloseTime: $maeCandle?->closeTime->toIso8601String(),
            candleCount: count($eligible),
            ambiguousPath: $ambiguous,
            ambiguityReason: $ambiguityReason,
            sourceCandleIds: $candleIds,
            sourceCandleCloseTimes: $candleTimes,
            riskReason: $riskReason,
        );
    }

    private function riskPerUnit(BigDecimal $entry, ?string $stopLoss, bool $isBuy): ?BigDecimal
    {
        if ($stopLoss === null || trim($stopLoss) === '') {
            return null;
        }

        $stop = BigDecimal::of($stopLoss);
        $risk = $isBuy ? $entry->minus($stop) : $stop->minus($entry);

        if (! $risk->isPositive()) {
            return null;
        }

        return $risk;
    }

    private function ambiguityReason(PriceCandle $candle, bool $isBuy, ?string $stopLoss, ?string $takeProfit): ?string
    {
        $high = BigDecimal::of($candle->high);
        $low = BigDecimal::of($candle->low);

        $stopTouched = false;
        if ($stopLoss !== null && trim($stopLoss) !== '') {
            $stop = BigDecimal::of($stopLoss);
            $stopTouched = $isBuy ? $low->isLessThanOrEqualTo($stop) : $high->isGreaterThanOrEqualTo($stop);
        }

        $targetTouched = false;
        if ($takeProfit !== null && trim($takeProfit) !== '') {
            $target = BigDecimal::of($takeProfit);
            $targetTouched = $isBuy ? $high->isGreaterThanOrEqualTo($target) : $low->isLessThanOrEqualTo($target);
        }

        if ($stopTouched && $targetTouched) {
            return 'CANDLE_SPANS_STOP_AND_TARGET_INTRACANDLE_SEQUENCE_UNKNOWN';
        }

        return null;
    }

    private function normalize(BigDecimal $value): string
    {
        return (string) $value->toScale(self::SCALE, RoundingMode::HalfEven);
    }
}
