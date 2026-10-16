<?php

declare(strict_types=1);

namespace App\MarketData\Jobs;

use App\MarketData\Application\FundingHistorySynchronizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

final class SyncFundingHistoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public array $backoff = [10, 30, 90];

    public function __construct(public readonly string $symbol) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("funding-history:{$this->symbol}"))->expireAfter(90)];
    }

    public function handle(FundingHistorySynchronizer $synchronizer): void
    {
        $synchronizer->sync($this->symbol);
    }
}
