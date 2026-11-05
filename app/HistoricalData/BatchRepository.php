<?php

declare(strict_types=1);

namespace App\HistoricalData;

use App\Strategy\Application\StrategyRegistry;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final readonly class BatchRepository
{
    public function __construct(private DownloadPlanner $planner, private SourcePolicy $policy) {}

    public function create(array $input, string $requestId): object
    {
        $plan = $this->planner->plan($input);
        $this->policy->assertUse($input['intended_use'], $plan['license']);
        $fingerprint = StrategyRegistry::hash($plan);
        $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'vector:vision:'.$requestId)->toString();

        return DB::transaction(function () use ($id, $fingerprint, $plan, $input): object {
            DB::table('historical_download_batches')->insertOrIgnore(['id' => $id, 'fingerprint' => $fingerprint, 'plan' => json_encode($plan, JSON_THROW_ON_ERROR), 'license' => json_encode($plan['license']), 'intended_use' => $input['intended_use'], 'status' => 'PLANNED', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            $batch = DB::table('historical_download_batches')->where('id', $id)->lockForUpdate()->first();
            abort_unless(hash_equals($batch->fingerprint, $fingerprint), 409, 'Planning idempotency key belongs to another scope.');
            if (! DB::table('historical_download_entries')->where('batch_id', $id)->exists()) {
                foreach ($plan['entries'] as $entry) {
                    $this->addEntry($id, $entry);
                }
                $this->audit($id, 'PLAN_CREATED', ['fingerprint' => $fingerprint, 'files' => count($plan['entries'])]);
            }

            return $batch;
        }, 3);
    }

    public function addEntry(string $batchId, array $entry, ?string $parent = null): void
    {
        $key = StrategyRegistry::hash([$batchId, $entry]);
        DB::table('historical_download_entries')->insertOrIgnore($entry + ['id' => Uuid::uuid5(Uuid::NAMESPACE_URL, $key)->toString(), 'batch_id' => $batchId, 'parent_id' => $parent, 'idempotency_key' => $key, 'status' => 'PLANNED', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
    }

    public function audit(string $batch, string $action, array $details): void
    {
        DB::table('historical_acquisition_events')->insert(['batch_id' => $batch, 'action' => $action, 'actor' => 'configured-research-owner', 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now('UTC')]);
    }
}
