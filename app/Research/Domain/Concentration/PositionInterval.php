<?php

declare(strict_types=1);

namespace App\Research\Domain\Concentration;

use Carbon\CarbonImmutable;

/**
 * PROFIT-1 / P-5 — position interval contract (§10.1).
 *
 * Rules implemented here:
 *  - intervals are half-open `[opened_at, end_at)`;
 *  - a position that is still open at the generation cutoff ends at the
 *    cutoff and is flagged `openAtCutoff`;
 *  - a partial close does NOT end the interval while a non-zero quantity
 *    remains (`partialCloseNotEnding`);
 *  - realized net PnL and unrealized PnL are never mixed: an open position
 *    contributes its realized net PnL (0 while open) to concentration and its
 *    unrealized PnL is reported separately.
 */
final class PositionInterval
{
    public const SIDE_BUY = 'BUY';

    public const SIDE_SELL = 'SELL';

    public function __construct(
        public readonly string $positionId,
        public readonly string $accountId,
        public readonly string $symbol,
        public readonly string $side,
        public readonly CarbonImmutable $openedAt,
        public readonly CarbonImmutable $endAt,
        public readonly bool $openAtCutoff,
        public readonly bool $partialCloseNotEnding,
        public readonly string $netPnl,
        public readonly string $unrealizedPnl,
        public readonly string $entryNotional,
    ) {}

    public function start(): CarbonImmutable
    {
        return $this->openedAt;
    }

    public function end(): CarbonImmutable
    {
        return $this->endAt;
    }

    public function isBuy(): bool
    {
        return $this->side === self::SIDE_BUY;
    }

    /**
     * Half-open overlap test: `[aStart, aEnd)` ∩ `[bStart, bEnd)` ≠ ∅.
     *
     * A zero-length interval (start == end) never overlaps anything.
     */
    public function overlaps(self $other): bool
    {
        if (! $this->openedAt->lessThan($this->endAt) || ! $other->openedAt->lessThan($other->endAt)) {
            return false;
        }

        return $this->openedAt->lessThan($other->endAt) && $other->openedAt->lessThan($this->endAt);
    }
}
