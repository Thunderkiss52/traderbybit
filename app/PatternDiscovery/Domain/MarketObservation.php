<?php

declare(strict_types=1);

namespace App\PatternDiscovery\Domain;

final readonly class MarketObservation
{
    /** @param list<string> $features */
    public function __construct(
        public string $signalId,
        public string $symbol,
        public string $direction,
        public int $decisionTimestamp,
        public int $outcomeTimestamp,
        public array $features,
        public bool $won,
        public float $netR,
        public bool $ambiguous,
        public string $regime,
    ) {}
}
