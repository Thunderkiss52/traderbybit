<?php

declare(strict_types=1);

namespace App\PatternDiscovery\Jobs;

use App\PatternDiscovery\Application\PatternDiscoveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class DiscoverPatternsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly string $runId)
    {
        $this->onQueue((string) config('patterns.queue', 'research'));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('research-heavy-job'))->expireAfter(3660)];
    }

    public function handle(PatternDiscoveryService $service): void
    {
        $service->run($this->runId);
    }
}
