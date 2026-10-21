<?php

declare(strict_types=1);

namespace App\Research\Domain\Concentration;

use Carbon\CarbonImmutable;

/**
 * PROFIT-1 / P-5 — a dependence group produced by the overlap algorithm (§10.2).
 *
 * A cluster is a dependence group, NOT proof of a single causal market event
 * and NOT a set of independent observations.
 */
final class OverlapCluster
{
    /**
     * @param  list<PositionInterval>  $members
     */
    public function __construct(
        public readonly string $clusterId,
        public readonly string $viewCode,
        public readonly string $symbol,
        public readonly ?string $sideFamily,
        public readonly array $members,
        public readonly CarbonImmutable $startAt,
        public readonly CarbonImmutable $endAt,
        public readonly string $netPnl,
    ) {}

    /**
     * @return list<string>
     */
    public function memberIds(): array
    {
        return array_map(static fn (PositionInterval $m): string => $m->positionId, $this->members);
    }

    public function memberCount(): int
    {
        return count($this->members);
    }
}
