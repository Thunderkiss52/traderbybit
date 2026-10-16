<?php

declare(strict_types=1);

namespace App\Strategy\Jobs;

use App\Strategy\Application\ResearchBootstrapService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class EnsureResearchCoverageJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function middleware(): array
    {
        return [(new WithoutOverlapping('research-bootstrap'))->expireAfter(180)->dontRelease()];
    }

    public function handle(ResearchBootstrapService $bootstrap): void
    {
        $bootstrap->ensure();
    }
}
