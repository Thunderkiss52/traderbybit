<?php

declare(strict_types=1);

namespace App\MarketData\Contracts;

use App\MarketData\Domain\Candle;
use App\MarketData\Domain\CandleQuery;

interface CandleStore
{
    /** @param list<Candle> $candles */
    public function upsert(array $candles): int;

    /** @return list<int> */
    public function closedOpenTimes(CandleQuery $query): array;

    public function latestClosedOpenTimeMs(string $exchange, string $category, string $symbol, string $interval): ?int;
}
