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

final class AcquisitionJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public array $backoff = [30];

    public int $uniqueFor = 660;

    public function __construct(public readonly string $batchId)
    {
        if (config('historical_data.queue_connection') === 'sync') {
            throw new \RuntimeException('Historical acquisition requires an asynchronous queue.');
        }
        $this->onConnection((string) config('historical_data.queue_connection'));
        $this->onQueue((string) config('historical_data.queue'));
    }

    public function uniqueId(): string
    {
        return 'vision:'.$this->batchId;
    }

    public function handle(AcquisitionWorker $worker): void
    {
        $worker->once($this->batchId);
        $worker->finish($this->batchId);
        if (DB::table('historical_download_batches')->where('id', $this->batchId)->value('status') === 'ACTIVE') {
            self::dispatch($this->batchId)->delay(now()->addSeconds(5))->afterCommit();
        }
    }
}
