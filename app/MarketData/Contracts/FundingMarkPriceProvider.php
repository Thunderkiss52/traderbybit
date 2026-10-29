<?php

declare(strict_types=1);

namespace App\MarketData\Contracts;

interface FundingMarkPriceProvider
{
    /** Historical mark at the UTC boundary; null means unavailable, never a trade-price fallback. */
    public function fundingMarkPrice(string $symbol, int $atMilliseconds): ?string;
}
