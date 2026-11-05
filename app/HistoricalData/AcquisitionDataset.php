<?php

declare(strict_types=1);

namespace App\HistoricalData;

use App\Backtest\Application\DatasetInspector;
use App\Strategy\Application\StrategyRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class AcquisitionDataset
{
    public function __construct(private DatasetInspector $canonical, private CoverageValidator $coverage, private SourcePolicy $policy) {}

    public function pin(string $batchId, array $scope, int $warmup): object
    {
        $run = function () use ($batchId, $scope, $warmup): object {
            $batch = DB::table('historical_download_batches')->find($batchId);
            abort_if($batch === null, 404);
            $this->policy->assertUse($scope['intended_use'], json_decode($batch->license, true));
            abort_unless(in_array($batch->status, ['VALIDATED', 'ACCEPTED_WITH_LIMITATIONS'], true), 409, 'Validate historical acquisition coverage first.');
            $quality = json_decode($batch->quality, true);
            abort_unless($quality['research_ready'] ?? false, 422, 'Historical dataset has unresolved quality or coverage failures.');
            $plan = json_decode($batch->plan, true);
            $from = CarbonImmutable::parse($scope['from'])->utc();
            $to = CarbonImmutable::parse($scope['to'])->utc();
            abort_unless($scope['exchange'] === 'binance' && array_diff($scope['symbols'], $plan['input']['symbols']) === [] && $from >= CarbonImmutable::parse($plan['from']) && $to <= CarbonImmutable::parse($plan['to_exclusive']) && $from < $to, 422, 'Research scope is outside imported dataset coverage.');
            $pin = $this->canonical->pin($scope['symbols'], $from, $to, $warmup, 'binance');
            foreach ($pin['warnings'] as $warning) {
                abort_if(str_starts_with($warning, 'insufficient_warmup:'), 422, 'Complete research warmup is required.');
            }
            $provenance = $this->sources($batchId);
            $supplemental = $this->supplemental($batchId, $scope['symbols'], $from, $to);
            $id = StrategyRegistry::hash([$pin['id'], $scope, $supplemental, $provenance, $batch->license]);
            DB::table('historical_acquisition_pins')->insertOrIgnore(['id' => $id, 'batch_id' => $batchId, 'canonical_pin_id' => $pin['id'], 'scope' => json_encode($scope, JSON_THROW_ON_ERROR), 'provenance' => json_encode($provenance, JSON_THROW_ON_ERROR), 'supplemental_sha256' => $supplemental, 'license' => $batch->license, 'created_at' => now('UTC')]);

            return DB::table('historical_acquisition_pins')->find($id);
        };
        if (DB::transactionLevel() > 0) {
            return $run();
        }

        return DB::transaction(function () use ($run) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

            return $run();
        }, 3);
    }

    public function verify(string $id): object
    {
        $pin = DB::table('historical_acquisition_pins')->find($id);
        abort_if($pin === null, 404);
        $scope = json_decode($pin->scope, true);
        $this->policy->assertUse($scope['intended_use'], json_decode($pin->license, true));
        $this->canonical->verifyPin($pin->canonical_pin_id);
        $hash = $this->supplemental($pin->batch_id, $scope['symbols'], CarbonImmutable::parse($scope['from']), CarbonImmutable::parse($scope['to']));
        if (! hash_equals($pin->supplemental_sha256, $hash) || StrategyRegistry::hash($this->sources($pin->batch_id)) !== StrategyRegistry::hash(json_decode($pin->provenance, true))) {
            throw new AcquisitionFailure('IMMUTABLE_ACQUISITION_PIN_CHANGED');
        }
        foreach ($scope['symbols'] as $symbol) {
            abort_if(DB::table('historical_data_conflicts')->where('symbol', $symbol)->where('at', '>=', $scope['from'])->where('at', '<', $scope['to'])->where('status', 'UNRESOLVED')->exists(), 422, 'Historical source conflicts block research.');
        }

        return $pin;
    }

    private function sources(string $batch): array
    {
        return DB::table('historical_download_entries')->where('batch_id', $batch)->whereNotNull('sha256')->orderBy('id')->get(['id', 'family', 'symbol', 'interval', 'url', 'sha256', 'status', 'checkpoint', 'conflict_rows'])->map(fn ($r) => (array) $r)->all();
    }

    private function supplemental(string $batch, array $symbols, CarbonImmutable $from, CarbonImmutable $to): string
    {
        $hash = hash_init('sha256');
        foreach (['historical_funding_observations' => 'funding_at', 'historical_mark_candles' => 'open_time'] as $table => $time) {
            foreach (DB::table($table)->where('batch_id', $batch)->whereIn('symbol', $symbols)->where($time, '>=', $from)->where($time, '<', $to)->orderBy('symbol')->orderBy($time)->orderBy('source_revision')->cursor() as $row) {
                hash_update($hash, StrategyRegistry::hash((array) $row)."\n");
            }
        }

        return hash_final($hash);
    }
}
