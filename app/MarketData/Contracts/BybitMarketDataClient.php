<?php

declare(strict_types=1);

namespace App\MarketData\Contracts;

use App\MarketData\Domain\Candle;
use App\MarketData\Domain\CandleQuery;

interface BybitMarketDataClient
{
    /** @return list<Candle> */
    public function candles(CandleQuery $query): array;

    /** @return array<string, mixed> */
    public function ticker(string $category, string $symbol): array;

    /** @return array<string, mixed> */
    public function orderBook(string $category, string $symbol, int $limit = 50): array;

    /** @return list<array<string, mixed>> */
    public function fundingRates(string $category, string $symbol, ?int $fromMs = null, ?int $toMs = null): array;

    /** @return array<string, mixed> */
    public function openInterest(string $category, string $symbol, string $intervalTime, ?string $cursor = null): array;
}
