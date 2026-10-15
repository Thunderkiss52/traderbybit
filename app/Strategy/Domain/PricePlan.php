<?php

declare(strict_types=1);

namespace App\Strategy\Domain;

final readonly class PricePlan
{
    public function __construct(
        public string $entryLow,
        public string $entryHigh,
        public string $stopLoss,
        public string $takeProfit1,
        public string $takeProfit2,
        public string $riskReward1,
        public string $riskReward2,
    ) {}
}
