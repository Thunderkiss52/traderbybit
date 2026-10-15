<?php

declare(strict_types=1);

namespace App\Strategy\Jobs;

use App\Strategy\Application\ResearchExperimentRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class RunResearchExperimentJob implements ShouldQueue
{
    use Dispatchable,Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly string $experimentId)
    {
        $this->onQueue((string) config('research.queue.name', 'research'));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('research-heavy-job'))->expireAfter((int) config('research.queue.timeout_seconds', 3600) + 60)];
    }

    public function handle(ResearchExperimentRunner $runner): void
    {
        $limit = (int) config('research.queue.memory_mb', 512) * 1024 * 1024;
        if (memory_get_usage(true) > $limit) {
            throw new \RuntimeException('Research worker memory budget unavailable.');
        } $runner->run($this->experimentId);
    }
}
