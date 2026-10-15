<?php

declare(strict_types=1);

namespace App\MarketData\Jobs;

use App\MarketData\Application\CandleSynchronizer;
use App\MarketData\Domain\CandleQuery;
use App\MarketData\Domain\Interval;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class SyncCandlesJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(
        public readonly string $symbol,
        public readonly string $interval,
        public readonly int $fromMs,
        public readonly int $toMs,
    ) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("market-sync:linear:{$this->symbol}:{$this->interval}"))
            ->expireAfter($this->timeout + 60)
            ->dontRelease()];
    }

    public function handle(CandleSynchronizer $synchronizer): void
    {
        $synchronizer->sync(new CandleQuery(
            'linear',
            $this->symbol,
            Interval::parse($this->interval),
            $this->fromMs,
            $this->toMs,
        ));
    }
}
