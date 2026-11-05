<?php

declare(strict_types=1);

namespace App\HistoricalData;

use Illuminate\Support\Facades\DB;

final readonly class BatchControl
{
    public function __construct(private BatchRepository $batches, private SourcePolicy $policy, private StagingStorage $storage) {}

    public function change(string $id, string $action, ?string $reference = null, ?string $termsVersion = null): object
    {
        return DB::transaction(function () use ($id, $action, $reference, $termsVersion): object {
            DB::select('SELECT pg_advisory_xact_lock(20261105,20001)');
            $batch = DB::table('historical_download_batches')->where('id', $id)->lockForUpdate()->first();
            abort_if($batch === null, 404);
            if (in_array($action, ['start', 'resume'], true)) {
                abort_unless(in_array($batch->status, ['PLANNED', 'PAUSED', 'FAILED', 'ACTIVE'], true), 409, 'Batch cannot be started from this state.');
                $license = json_decode($batch->license, true);
                $this->policy->assertUse($batch->intended_use, $license);
                if ($batch->acknowledged_at === null) {
                    abort_unless($termsVersion === $license['version'] && is_string($reference) && strlen($reference) >= 8, 422, 'Explicit acknowledgement of the recorded source terms and an owner reference are required.');
                }
                abort_if(DB::table('historical_download_batches')->where('id', '<>', $id)->where('status', 'ACTIVE')->exists(), 409, 'Only one acquisition batch may be active.');
                if ($batch->status === 'ACTIVE') {
                    return $batch;
                }
                $plan = json_decode($batch->plan, true);
                $this->storage->admit((int) $plan['estimate']['estimated_database_bytes']);
                DB::table('historical_download_batches')->where('id', $id)->update(['status' => 'ACTIVE', 'acknowledgement' => $batch->acknowledgement ?? $reference, 'acknowledged_at' => $batch->acknowledged_at ?? now('UTC'), 'updated_at' => now('UTC')]);
            } elseif (in_array($action, ['pause', 'cancel'], true)) {
                $state = $action === 'pause' ? 'PAUSED' : 'CANCELLED';
                if ($batch->status === $state) {
                    return $batch;
                }abort_unless(in_array($batch->status, ['PLANNED', 'ACTIVE', 'PAUSED', 'FAILED'], true), 409, 'Terminal batch cannot be changed.');
                DB::table('historical_download_batches')->where('id', $id)->update(['status' => $state, 'updated_at' => now('UTC')]);
            } elseif ($action === 'retry') {
                abort_unless(in_array($batch->status, ['ACTIVE', 'FAILED', 'PAUSED'], true), 409, 'Batch retry is unavailable.');
                $n = DB::table('historical_download_entries')->where('batch_id', $id)->whereIn('status', ['FAILED', 'QUARANTINED'])->where('attempts', '<', (int) config('historical_data.max_attempts'))->where('error_code', '<>', 'SOURCE_ACCESS_RESTRICTED')->update(['status' => 'PLANNED', 'error_code' => null, 'available_at' => now('UTC'), 'updated_at' => now('UTC')]);
                abort_if($n === 0, 409, 'No retryable files remain within the strict attempt budget. Investigate failures before making a new plan.');
                DB::table('historical_download_batches')->where('id', $id)->update(['status' => 'PAUSED', 'quality' => null, 'updated_at' => now('UTC')]);
            } else {
                abort(422, 'Unsupported batch action.');
            }
            $this->batches->audit($id, strtoupper($action), ['reference' => $reference, 'license_version' => $termsVersion]);

            return DB::table('historical_download_batches')->find($id);
        }, 3);
    }
}
