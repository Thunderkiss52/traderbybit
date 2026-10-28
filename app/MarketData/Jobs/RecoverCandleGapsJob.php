<?php

declare(strict_types=1);

namespace App\MarketData\Jobs;

use App\Exchanges\Binance\BinanceCandleIngestion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class RecoverCandleGapsJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    public array $backoff = [60, 300];

    public function __construct(
        public readonly ?string $exchange = null,
        public readonly ?string $symbol = null,
    ) {}

    public function middleware(): array
    {
        $lockKey = 'market-gap-recovery'
            .($this->exchange !== null ? ":{$this->exchange}" : '')
            .($this->symbol !== null ? ":{$this->symbol}" : '');

        return [(new WithoutOverlapping($lockKey))->expireAfter(960)->dontRelease()];
    }

    public function handle(): void
    {
        $targetExchange = $this->exchange !== null ? strtolower($this->exchange) : 'bybit';
        $symbols = $this->symbol !== null ? [$this->symbol] : (array) config('market_data.symbols', ['BTCUSDT', 'ETHUSDT']);

        if ($targetExchange === 'binance') {
            $ingestion = app(BinanceCandleIngestion::class);
            $failures = [];
            foreach ($symbols as $symbol) {
                foreach (['5m', '15m'] as $interval) {
                    try {
                        $ingestion->ingest((string) $symbol, $interval, 100);
                    } catch (Throwable $e) {
                        $failures[] = $symbol.':'.$interval;
                        Log::warning('Binance candle gap recovery failed', [
                            'exchange' => 'binance', 'symbol' => $symbol,
                            'interval' => $interval, 'exception' => $e::class,
                        ]);
                    }
                }
            }
            if ($failures !== []) {
                // Successful scopes stay committed; retry verifies their immutable rows.
                throw new RuntimeException('Binance candle gap recovery incomplete: '.implode(', ', $failures));
            }

            return;
        }

        // Bybit candle gap recovery
        $toMs = (int) floor(microtime(true) * 1000);
        $fromMs = $toMs - (6 * 60 * 60 * 1000);
        $intervals = (array) config('market_data.intervals', ['5', '15', '60']);

        foreach ($symbols as $symbol) {
            foreach ($intervals as $interval) {
                SyncCandlesJob::dispatch((string) $symbol, (string) $interval, $fromMs, $toMs);
            }
        }
    }
}
