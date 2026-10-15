<?php

declare(strict_types=1);

namespace App\MarketData\Jobs;

use App\MarketData\Contracts\CandleStore;
use App\MarketData\Domain\Interval;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Psr\Log\LoggerInterface;

final class CheckMarketDataFreshnessJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function handle(CandleStore $store, LoggerInterface $log): void
    {
        $nowMs = (int) floor(microtime(true) * 1000);
        $multiplier = (int) config('market_data.sync.freshness_grace_multiplier');

        foreach ((array) config('market_data.symbols') as $symbol) {
            foreach ((array) config('market_data.intervals') as $value) {
                $interval = Interval::parse((string) $value);
                $latest = $store->latestClosedOpenTimeMs('bybit', 'linear', (string) $symbol, (string) $value);
                $age = $latest === null ? null : $nowMs - ($latest + $interval->milliseconds());
                if ($age === null || $age > $interval->milliseconds() * $multiplier) {
                    $log->error('Bybit candle data is stale.', [
                        'symbol' => $symbol,
                        'interval' => $value,
                        'age_ms' => $age,
                    ]);
                }
            }
        }
    }
}
