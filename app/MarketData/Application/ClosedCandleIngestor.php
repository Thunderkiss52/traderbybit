<?php

declare(strict_types=1);

namespace App\MarketData\Application;

use App\MarketData\Contracts\CandleStore;
use App\MarketData\Infrastructure\Bybit\BybitWebSocketMessageParser;
use Illuminate\Support\Facades\Cache;

final readonly class ClosedCandleIngestor
{
    public function __construct(
        private BybitWebSocketMessageParser $parser,
        private CandleStore $store,
    ) {}

    public function ingest(string $message): bool
    {
        $candle = $this->parser->parseClosedCandle($message);
        if ($candle === null) {
            return false;
        }

        $lockName = implode(':', [
            'market-candle',
            $candle->category,
            $candle->symbol,
            $candle->interval->value,
            $candle->openTimeMs(),
        ]);
        $lock = Cache::lock($lockName, 30);

        if (! $lock->get()) {
            return false;
        }

        try {
            $this->store->upsert([$candle]);
        } finally {
            $lock->release();
        }

        return true;
    }
}
