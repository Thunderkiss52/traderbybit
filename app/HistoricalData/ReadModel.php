<?php

declare(strict_types=1);

namespace App\HistoricalData;

use Illuminate\Support\Facades\DB;

final class ReadModel
{
    public function batch(string $id): array
    {
        $row = DB::table('historical_download_batches')->find($id);
        abort_if($row === null, 404);
        $plan = json_decode($row->plan, true);
        unset($plan['entries']);
        $counts = DB::table('historical_download_entries')->where('batch_id', $id)->selectRaw('status, count(*) as files, sum(imported_rows) as imported_rows, sum(duplicate_rows) as duplicate_rows, sum(conflict_rows) as conflict_rows')->groupBy('status')->get();
        $lease = DB::table('historical_download_entries')->where('batch_id', $id)->where('lease_until', '>', now('UTC'))->first(['filename', 'lease_until']);

        return ['id' => $id, 'status' => $row->status, 'plan' => $plan, 'license' => json_decode($row->license, true), 'acknowledged_at' => $row->acknowledged_at, 'quality' => $row->quality === null ? null : json_decode($row->quality, true), 'counts' => $counts, 'worker' => $lease, 'updated_at' => $row->updated_at];
    }

    public function run(string $id): array
    {
        $row = DB::table('historical_research_runs')->find($id);
        abort_if($row === null, 404);

        return ['id' => $id, 'batch_id' => $row->batch_id, 'status' => $row->status, 'strategy_id' => $row->strategy_id, 'strategy_version' => $row->strategy_version, 'dataset_pin' => $row->dataset_pin, 'configuration_hash' => $row->configuration_hash, 'engine_checksum' => $row->engine_checksum, 'next_decision_at' => $row->next_decision_at, 'lease_until' => $row->lease_until, 'error_code' => $row->error_code, 'skips' => json_decode($row->skip_counts ?? '{}', true), 'result' => $row->status === 'COMPLETED' ? json_decode($row->result, true) : null, 'created_at' => $row->created_at];
    }
}
