<?php

declare(strict_types=1);

namespace App\Exchanges\Contracts;

interface ExchangeAdapter
{
    public function id(): string;

    public function name(): string;

    /** @return list<array<string, mixed>> Normalized UTC candle rows, decimal strings. */
    public function fetchCandles(string $symbol, string $interval, int $limit, ?int $startMs = null, ?int $endMs = null): array;

    /** @return list<array<string, mixed>> */
    public function fetchFundingHistory(string $symbol, ?int $startMs = null, ?int $endMs = null): array;

    /** @return list<array<string, mixed>> */
    public function fetchOpenInterest(string $symbol, string $interval, ?int $startMs = null, ?int $endMs = null): array;

    /** @return list<array<string, mixed>> */
    public function fetchSymbols(): array;
}
