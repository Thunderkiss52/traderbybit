<?php

declare(strict_types=1);

namespace App\HistoricalData;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

final class ResearchReplayJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;

    public int $tries = 1;

    public int $timeout = 1600;

    public int $uniqueFor = 1700;

    public array $backoff = [30];

    public function __construct(public readonly string $runId)
    {
        if (config('historical_data.queue_connection') === 'sync') {
            throw new \RuntimeException('Historical research requires an asynchronous queue.');
        }$this->onConnection((string) config('historical_data.queue_connection'));
        $this->onQueue((string) config('historical_data.queue'));
    }

    public function uniqueId(): string
    {
        return 'history-replay:'.$this->runId;
    }

    public function handle(ResearchWorker $worker): void
    {
        $worker->once($this->runId);
        if (DB::table('historical_research_runs')->where('id', $this->runId)->value('status') === 'QUEUED') {
            self::dispatch($this->runId)->delay(now()->addSecond())->afterCommit();
        }
    }
}
