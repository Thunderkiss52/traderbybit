<?php

declare(strict_types=1);

namespace App\StrategyBuilder;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

final class OptimizationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public bool $failOnTimeout = true;

    public int $timeout = 1800;

    public array $backoff = [30, 120];

    public function __construct(public readonly string $experimentId)
    {
        $this->onQueue((string) config('research.queue.name', 'research'));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('dsl:optimize:'.$this->experimentId))->expireAfter(1900)->releaseAfter(1900)];
    }

    public function failed(?\Throwable $exception): void
    {
        DB::transaction(function (): void {
            $row = DB::table('strategy_optimization_experiments')->where('id', $this->experimentId)->lockForUpdate()->first();
            if ($row === null || ! in_array($row->status, ['QUEUED', 'RUNNING', 'CANCEL_REQUESTED', 'FAILED'], true)) {
                return;
            }
            DB::table('strategy_optimization_experiments')->where('id', $row->id)->update(['status' => $row->status === 'CANCEL_REQUESTED' ? 'CANCELLED' : 'FAILED', 'lease_until' => null, 'error' => 'QUEUE_EXECUTION_EXHAUSTED', 'updated_at' => now('UTC')]);
            DB::table('strategy_workflow_events')->insert(['definition_id' => $row->definition_id, 'action' => 'OPTIMIZATION_QUEUE_FAILED', 'actor' => 'research-worker', 'details' => json_encode(['experiment_id' => $row->id]), 'created_at' => now('UTC')]);
        }, 3);
    }

    public function handle(OptimizationService $research): void
    {
        $research->run($this->experimentId);
    }
}
