<?php

declare(strict_types=1);

namespace App\Strategy\Jobs;

use App\Strategy\Application\StrategyResearchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class ProcessResearchPaperJob implements ShouldQueue
{
    use Dispatchable,Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public function middleware(): array
    {
        return [(new WithoutOverlapping('research-forward-paper'))->expireAfter(660)];
    }

    public function handle(StrategyResearchService $service): void
    {
        $service->updateForwardPortfolios();
    }
}
