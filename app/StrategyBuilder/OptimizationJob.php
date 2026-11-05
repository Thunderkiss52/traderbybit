<?php

declare(strict_types=1);

namespace App\StrategyBuilder;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

final class OptimizationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 1800;

    public array $backoff = [30, 120];

    public function __construct(public readonly string $experimentId)
    {
        $this->onQueue((string) config('research.queue.name', 'research'));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('dsl:optimize:'.$this->experimentId))->expireAfter(1900)->releaseAfter(30)];
    }

    public function handle(OptimizationService $research): void
    {
        $research->run($this->experimentId);
    }
}
