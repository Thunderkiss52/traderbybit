<?php

declare(strict_types=1);

namespace App\MarketData\Application;

use App\MarketData\Contracts\BybitMarketDataClient;
use App\MarketData\Contracts\CandleStore;
use App\MarketData\Domain\CandleQuery;
use App\MarketData\Domain\SyncReport;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final readonly class CandleSynchronizer
{
    public function __construct(
        private BybitMarketDataClient $client,
        private CandleStore $store,
        private GapDetector $gapDetector,
        private int $backfillPasses,
        private int $lockSeconds,
    ) {}

    public function sync(CandleQuery $query): SyncReport
    {
        $lock = Cache::lock($this->lockName($query), $this->lockSeconds);

        try {
            return $lock->block(1, fn (): SyncReport => $this->syncUnlocked($query));
        } catch (LockTimeoutException) {
            throw new RuntimeException('A conflicting candle synchronization is already running.');
        }
    }

    private function syncUnlocked(CandleQuery $query): SyncReport
    {
        $candles = array_values(array_filter(
            $this->client->candles($query),
            static fn ($candle): bool => $candle->isClosed,
        ));
        $fetched = count($candles);
        $stored = $this->store->upsert($candles);
        $backfillRequests = 0;
        $closedBeforeMs = min($query->toMs, (int) floor(microtime(true) * 1000));
        $missing = $this->missing($query, $closedBeforeMs);

        for ($pass = 0; $pass < $this->backfillPasses && $missing !== []; $pass++) {
            foreach ($this->gapDetector->contiguousRanges($missing, $query->interval->milliseconds()) as [$from, $to]) {
                $backfillRequests++;
                $backfill = array_values(array_filter(
                    $this->client->candles(new CandleQuery(
                        category: $query->category,
                        symbol: $query->symbol,
                        interval: $query->interval,
                        fromMs: $from,
                        toMs: $to,
                    )),
                    static fn ($candle): bool => $candle->isClosed,
                ));
                $fetched += count($backfill);
                $stored += $this->store->upsert($backfill);
            }
            $missing = $this->missing($query, $closedBeforeMs);
        }

        return new SyncReport($fetched, $stored, $backfillRequests, $missing);
    }

    /** @return list<int> */
    private function missing(CandleQuery $query, int $closedBeforeMs): array
    {
        return $this->gapDetector->missing(
            $query,
            $this->store->closedOpenTimes($query),
            $closedBeforeMs,
        );
    }

    private function lockName(CandleQuery $query): string
    {
        return implode(':', ['market-sync', $query->category, $query->symbol, $query->interval->value]);
    }
}
