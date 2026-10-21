<?php

declare(strict_types=1);

namespace App\Research\Domain\Concentration;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * PROFIT-1 / P-5 — single source of truth for the position interval contract
 * (§10.1).
 *
 * The same rules are applied by the database-backed research service and by
 * the read-only archive evaluator, so an offline result can never diverge
 * from the stored one.
 *
 * Rules:
 *  - an interval is half-open [opened_at, end_at);
 *  - a position that is not fully closed at the cutoff is clipped to the
 *    cutoff and flagged `openAtCutoff`;
 *  - a partial close (non-zero remaining quantity) never ends the interval;
 *  - a fully closed position contributes its realized net PnL; an open
 *    position contributes no realized net PnL and its unrealized PnL is kept
 *    separate.
 */
final class PositionIntervalFactory
{
    public const SCALE = 12;

    public function make(
        string $positionId,
        string $accountId,
        string $symbol,
        string $side,
        CarbonImmutable $openedAt,
        ?CarbonImmutable $closedAt,
        string $initialQuantity,
        string $remainingQuantity,
        string $netPnl,
        string $unrealizedPnl,
        string $entryPrice,
        CarbonImmutable $asOf,
        ?CarbonImmutable $windowEnd = null
    ): ?PositionInterval {
        $remaining = BigDecimal::of($remainingQuantity);
        $initial = BigDecimal::of($initialQuantity);

        $isClosed = $closedAt !== null
            && $remaining->isEqualTo(BigDecimal::zero())
            && $closedAt->getTimestamp() <= $asOf->getTimestamp();
        $partialNotEnding = $closedAt !== null && ! $remaining->isEqualTo(BigDecimal::zero());
        $openAtCutoff = ! $isClosed;

        if ($isClosed) {
            $endAt = $closedAt;
        } elseif ($windowEnd !== null) {
            $endAt = $closedAt !== null && $closedAt->getTimestamp() < $windowEnd->getTimestamp()
                ? $closedAt
                : $windowEnd;
        } else {
            $endAt = $asOf;
        }

        if (! $openedAt->lessThan($endAt)) {
            return null;
        }

        $realizedNetPnl = $isClosed
            ? BigDecimal::of($netPnl)->toScale(self::SCALE, RoundingMode::HalfEven)
            : BigDecimal::zero()->toScale(self::SCALE, RoundingMode::HalfEven);
        $unrealized = BigDecimal::of($unrealizedPnl)->toScale(self::SCALE, RoundingMode::HalfEven);
        $entryNotional = BigDecimal::of($entryPrice)
            ->multipliedBy($initial)
            ->toScale(self::SCALE, RoundingMode::HalfEven);

        return new PositionInterval(
            positionId: $positionId,
            accountId: $accountId,
            symbol: $symbol,
            side: strtoupper($side),
            openedAt: $openedAt,
            endAt: $endAt,
            openAtCutoff: $openAtCutoff,
            partialCloseNotEnding: $partialNotEnding,
            netPnl: $realizedNetPnl->__toString(),
            unrealizedPnl: $unrealized->__toString(),
            entryNotional: $entryNotional->__toString(),
        );
    }
}
