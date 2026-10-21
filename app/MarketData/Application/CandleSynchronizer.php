<?php

declare(strict_types=1);

namespace App\MarketData\Application;

use App\MarketData\Contracts\BybitMarketDataClient;
use App\MarketData\Contracts\CandleStore;
use App\MarketData\Domain\CandleQuery;
use App\MarketData\Domain\SyncReport;
use App\QuoteIntegrity\Application\QimRestObserver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final readonly class CandleSynchronizer
{
    public function __construct(
        private BybitMarketDataClient $client,
        private CandleStore $store,
        private GapDetector $gapDetector,
        private int $backfillPasses,
        private int $lockSeconds,
        private ?QimRestObserver $qimObserver = null,
        private ?LoggerInterface $log = null,
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
        $requestSentAt = CarbonImmutable::now();
        $rawCandles = $this->client->candles($query);
        $responseReceivedAt = CarbonImmutable::now();

        $candles = array_values(array_filter(
            $rawCandles,
            static fn ($candle): bool => $candle->isClosed,
        ));

        if ($this->qimObserver !== null) {
            try {
                $this->qimObserver->observeCandles($candles, $requestSentAt, $responseReceivedAt);
            } catch (Throwable $e) {
                $this->log?->warning('QIM REST observation hook caught exception', [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $fetched = count($candles);
        $stored = $this->store->upsert($candles);
        $backfillRequests = 0;
        $closedBeforeMs = min($query->toMs, (int) floor(microtime(true) * 1000));
        $missing = $this->missing($query, $closedBeforeMs);

        for ($pass = 0; $pass < $this->backfillPasses && $missing !== []; $pass++) {
            foreach ($this->gapDetector->contiguousRanges($missing, $query->interval->milliseconds()) as [$from, $to]) {
                $backfillRequests++;
                $backfillReqSentAt = CarbonImmutable::now();
                $rawBackfill = $this->client->candles(new CandleQuery(
                    category: $query->category,
                    symbol: $query->symbol,
                    interval: $query->interval,
                    fromMs: $from,
                    toMs: $to,
                ));
                $backfillReqReceivedAt = CarbonImmutable::now();

                $backfill = array_values(array_filter(
                    $rawBackfill,
                    static fn ($candle): bool => $candle->isClosed,
                ));

                if ($this->qimObserver !== null) {
                    try {
                        $this->qimObserver->observeCandles($backfill, $backfillReqSentAt, $backfillReqReceivedAt);
                    } catch (Throwable $e) {
                        $this->log?->warning('QIM REST observation hook caught exception', [
                            'exception' => $e::class,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }

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
