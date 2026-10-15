<?php

declare(strict_types=1);

namespace App\MarketData\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class RecoverCandleGapsJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    public function middleware(): array
    {
        return [(new WithoutOverlapping('market-gap-recovery'))->expireAfter(960)->dontRelease()];
    }

    public function handle(): void
    {
        $toMs = (int) floor(microtime(true) * 1000);
        $fromMs = $toMs - (6 * 60 * 60 * 1000);

        foreach ((array) config('market_data.symbols') as $symbol) {
            foreach ((array) config('market_data.intervals') as $interval) {
                SyncCandlesJob::dispatch((string) $symbol, (string) $interval, $fromMs, $toMs);
            }
        }
    }
}
