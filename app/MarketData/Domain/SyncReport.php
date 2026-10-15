<?php

declare(strict_types=1);

namespace App\MarketData\Domain;

final readonly class SyncReport
{
    /** @param list<int> $missingOpenTimesMs */
    public function __construct(
        public int $fetched,
        public int $stored,
        public int $backfillRequests,
        public array $missingOpenTimesMs,
    ) {}

    public function isComplete(): bool
    {
        return $this->missingOpenTimesMs === [];
    }
}
