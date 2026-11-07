<?php

declare(strict_types=1);

namespace App\Research\Jobs;

use App\Research\Application\PortfolioResearchLifecycleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class ProcessPortfolioResearchLifecycleJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public array $backoff = [30, 120];

    public function __construct()
    {
        $this->onQueue((string) config('research.queue.name', 'research'));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('portfolio-research-lifecycle'))->expireAfter(180)->dontRelease()];
    }

    public function handle(PortfolioResearchLifecycleService $lifecycle): void
    {
        $lifecycle->process();
    }
}
