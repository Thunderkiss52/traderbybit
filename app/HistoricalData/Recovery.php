<?php

declare(strict_types=1);

namespace App\HistoricalData;

use Illuminate\Support\Facades\DB;

final class Recovery
{
    public function dispatch(): array
    {
        $batch = DB::table('historical_download_batches')->where('status', 'ACTIVE')->whereNotNull('acknowledged_at')->orderBy('created_at')->first();
        if ($batch !== null) {
            AcquisitionJob::dispatch($batch->id)->afterCommit();
        }
        $run = DB::table('historical_research_runs')->whereIn('status', ['QUEUED', 'RUNNING'])->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<=', now('UTC')))->orderBy('created_at')->first();
        if ($run !== null) {
            ResearchReplayJob::dispatch($run->id)->afterCommit();
        }

        return ['batch' => $batch?->id, 'run' => $run?->id];
    }
}
