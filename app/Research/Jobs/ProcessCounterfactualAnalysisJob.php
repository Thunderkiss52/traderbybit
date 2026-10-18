<?php

declare(strict_types=1);

namespace App\Research\Jobs;

use App\Research\Application\CounterfactualAnalyzerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class ProcessCounterfactualAnalysisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(
        public readonly int $limit = 100,
    ) {
        $this->onQueue((string) config('research.queue.name', 'research'));
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('process-counterfactual-analysis'))
                ->expireAfter(720)
                ->dontRelease(),
        ];
    }

    public function handle(CounterfactualAnalyzerService $analyzer): void
    {
        $result = $analyzer->analyzeAndResolve($this->limit);

        Log::info('Counterfactual SKIP analysis completed.', [
            'analyzed_count' => $result['analyzed_count'],
            'resolved_count' => $result['resolved_count'],
            'limit' => $this->limit,
        ]);
    }
}
