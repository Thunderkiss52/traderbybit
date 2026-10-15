<?php

declare(strict_types=1);

namespace App\Strategy\Jobs;

use App\Strategy\Application\MarketContextCollector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

final class CaptureMarketContextJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public array $backoff = [5, 20, 60];

    public function __construct(public readonly string $symbol) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("market-context:{$this->symbol}"))->expireAfter(60)];
    }

    public function handle(MarketContextCollector $collector): void
    {
        $collector->collect($this->symbol);
    }
}
