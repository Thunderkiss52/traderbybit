<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\Research\Infrastructure\Persistence\ResearchJobRecord;
use App\Research\Infrastructure\Persistence\SignalOutcomeEvaluationRecord;
use App\Research\Infrastructure\Persistence\SignalQualityProfileRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Ramsey\Uuid\Uuid;

/** Durable research orchestration; this service has no PAPER entry/promotion authority. */
final readonly class SignalIntelligenceService
{
    public function __construct(private ResearchJobService $jobs, private SignalIntelligencePacketFactory $packets) {}

    public function scheduleSignal(SignalRecord $signal, ?array $horizons = null, ?CarbonImmutable $asOf = null): ResearchJobRecord
    {
        $asOf = ($asOf ?? CarbonImmutable::now('UTC'))->utc()->startOfSecond();
        $packet = $this->packets->evaluation($signal, $horizons ?? config('research.signal_intelligence.horizons_minutes', [15, 60]), $asOf);

        return $this->submit($packet, $packet['evaluation']['provenance'], $asOf);
    }

    /** Cohorts retain exact exchange, version, source timeframe, horizon and cost identity. */
    public function scheduleCohort(SignalOutcomeEvaluationRecord $member, ?CarbonImmutable $asOf = null): ResearchJobRecord
    {
        $asOf = ($asOf ?? CarbonImmutable::now('UTC'))->utc()->startOfSecond();
        $scope = $member->result['scope'];
        $query = SignalOutcomeEvaluationRecord::query()->where('status', 'MATURED')->where('data_source', 'HYPOTHETICAL')
            ->where('policy_version', $scope['label_policy_version'])
            ->where('evaluated_as_of', '<=', $asOf)->where('created_at', '<=', $asOf);
        foreach (['exchange', 'category', 'symbol', 'strategy_id', 'strategy_version', 'source_timeframe_minutes', 'horizon_minutes',
            'direction', 'regime', 'configuration_hash', 'code_revision', 'execution_hash', 'horizon_anchor'] as $key) {
            $query->where($key, $scope[$key]);
        }
        // One immutable evaluation per signal; late imports cannot duplicate evidence.
        $selected = $query->selectRaw('DISTINCT ON (signal_id) *')->orderBy('signal_id')->orderByDesc('evaluated_as_of')->orderByDesc('id');
        $rows = DB::query()->fromSub($selected, 'e')->orderByDesc('available_at')->orderBy('signal_id')->limit(4096)->get();
        $observations = $rows->map(fn ($row): array => [
            'id' => $row->signal_id, 'input_hash' => $row->input_hash, 'scope' => $scope, 'status' => $row->status,
            // Classifier intervals start at the declared execution anchor, which
            // may follow original signal availability by part of a minute.
            'available_ms' => CarbonImmutable::parse($row->entry_at)->getTimestampMs(),
            'end_ms' => CarbonImmutable::parse($row->horizon_end_at)->getTimestampMs(), 'net_return' => $row->net_return,
        ])->sortBy('available_ms')->values()->all();
        $packet = ['operation' => 'classify', 'cohort' => ['scope' => $scope, 'as_of_ms' => $asOf->getTimestampMs(),
            'policy' => config('research.signal_intelligence.quality_policy'), 'observations' => $observations]];
        $datasetHash = SignalIntelligenceResultVerifier::cohortDatasetHash($observations);
        $provenance = ['dataset_id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'vector:cohort:'.$datasetHash)->toString(),
            'dataset_hash' => $datasetHash, 'configuration_hash' => $scope['configuration_hash'], 'code_revision' => $scope['code_revision']];
        $packet['cohort']['provenance'] = $provenance;

        return $this->submit($packet, $provenance, $asOf);
    }

    private function submit(array $packet, array $provenance, CarbonImmutable $asOf): ResearchJobRecord
    {
        $scope = $packet['evaluation']['signal'] ?? $packet['cohort']['scope'];
        $identity = $packet;
        // Time alone is not new evidence; maturity and dataset changes are.
        if (isset($identity['evaluation'])) {
            $evaluation = &$identity['evaluation'];
            $available = (int) $evaluation['signal']['available_ms'];
            $entry = (int) (ceil($available / 60000) * 60000);
            $evaluation['mature_horizons'] = array_values(array_filter($evaluation['horizons_minutes'], fn (int $h): bool => $entry + $h * 60000 <= $evaluation['as_of_ms']));
            unset($evaluation['as_of_ms']);
            unset($evaluation);
        } else {
            unset($identity['cohort']['as_of_ms']);
        }
        $key = self::digest($identity);

        return DB::transaction(function () use ($packet, $scope, $provenance, $asOf, $key): ResearchJobRecord {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))', ['signal-intelligence:'.$key]);
            $existing = ResearchJobRecord::query()->where('kind', 'SIGNAL_MARKOUT')->where('idempotency_key', $key)
                ->whereIn('status', ['QUEUED', 'CLAIMED', 'RUNNING', 'SUCCEEDED'])->latest('id')->first();
            if ($existing !== null) {
                return $existing;
            }

            return $this->jobs->submitJob(['kind' => 'SIGNAL_MARKOUT', 'idempotency_key' => $key,
                'strategy_id' => $scope['strategy_id'], 'strategy_version' => $scope['strategy_version'],
                'configuration_hash' => $provenance['configuration_hash'], 'code_revision' => $provenance['code_revision'],
                'dataset_id' => $provenance['dataset_id'], 'dataset_sha256' => $provenance['dataset_hash'],
                'as_of_utc' => $asOf, 'research_mode' => 'HYPOTHETICAL', 'created_by' => 'signal-intelligence',
                'limits' => ['max_rows' => isset($packet['cohort']) ? 4096 : 512, 'max_duration_s' => 30, 'max_memory_mib' => 256],
                'payload' => ['exchange' => $scope['exchange'], 'signal_intelligence' => $packet]]);
        }, 3);
    }

    /** Imports verified worker bytes atomically and idempotently, without financial writes. */
    public function importResult(ResearchJobRecord $record): array
    {
        return DB::transaction(function () use ($record): array {
            $job = ResearchJobRecord::query()->where('job_id', $record->job_id)->lockForUpdate()->firstOrFail();
            if ($job->kind !== 'SIGNAL_MARKOUT' || $job->status !== 'SUCCEEDED' || $job->research_mode !== 'HYPOTHETICAL') {
                throw new LogicException('Only completed hypothetical signal intelligence jobs can be imported.');
            }
            $metrics = $job->result_payload;
            SignalIntelligenceResultVerifier::verifyManifest($metrics, $job->result_manifest_sha256);
            $raw = $metrics['signal_intelligence_json'] ?? null;
            if (! is_string($raw) || strlen($raw) > 4 * 1024 * 1024 || ($metrics['research_only'] ?? null) !== true
                || ! hash_equals(hash('sha256', $raw), (string) ($metrics['signal_intelligence_sha256'] ?? ''))) {
                throw new LogicException('Signal intelligence result checksum or source is invalid.');
            }
            $report = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (($report['schema_version'] ?? null) !== 1) {
                throw new LogicException('Unsupported signal intelligence result schema.');
            }
            $packet = $job->payload['signal_intelligence'];
            $input = $packet['evaluation'] ?? $packet['cohort'];
            $scope = $input['signal'] ?? $input['scope'];
            if ($job->as_of_utc?->getTimestampMs() !== $input['as_of_ms'] || $job->strategy_id !== $scope['strategy_id']
                || $job->strategy_version !== $scope['strategy_version'] || ($job->payload['exchange'] ?? null) !== $scope['exchange']) {
                throw new LogicException('Signal intelligence submitted job scope or cutoff mismatch.');
            }
            $result = $report[$packet['operation'] === 'evaluate' ? 'evaluation' : 'cohort'] ?? [];
            if (($result['research_only'] ?? null) !== true || ($result['entry_authority'] ?? null) !== false) {
                throw new LogicException('Signal intelligence cannot grant entry authority.');
            }

            $records = isset($packet['evaluation']) ? $this->importOutcomes($job, $packet['evaluation'], $result) : $this->importProfile($job, $packet['cohort'], $result);
            $stats = $job->runtime_stats ?? [];
            if (! isset($stats['signal_intelligence_import'])) {
                $stats['signal_intelligence_import'] = ['at' => CarbonImmutable::now('UTC')->toIso8601String(),
                    'record_ids' => array_map(fn ($row): string => $row->id, $records),
                    'result_sha256' => $metrics['signal_intelligence_sha256']];
                $job->update(['runtime_stats' => $stats]);
            }

            return $records;
        }, 3);
    }

    private function importOutcomes(ResearchJobRecord $job, array $input, array $report): array
    {
        if (($report['policy_version'] ?? null) !== 'HYPOTHETICAL_HORIZON_MARKOUT_V1'
            || ($report['provenance'] ?? null) != $input['provenance']
            || $job->dataset_sha256 !== $input['provenance']['dataset_hash']
            || $job->dataset_id !== $input['provenance']['dataset_id']
            || count($report['outcomes'] ?? []) !== count($input['horizons_minutes'])) {
            throw new LogicException('Signal outcome provenance is inconsistent.');
        }
        $signal = $input['signal'];
        $expectedInputHash = SignalIntelligenceResultVerifier::evaluationInputHash($input);
        $expectedExecutionHash = SignalIntelligenceResultVerifier::executionHash($input);
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))', ['signal-outcome:'.$expectedInputHash]);
        $seen = [];
        $records = [];
        foreach ($report['outcomes'] as $outcome) {
            $scope = $outcome['scope'];
            $horizon = $scope['horizon_minutes'];
            foreach (['exchange', 'category', 'symbol', 'strategy_id', 'strategy_version', 'source_timeframe_minutes', 'direction', 'regime'] as $field) {
                if (($scope[$field] ?? null) !== $signal[$field]) {
                    throw new LogicException('Signal outcome scope mismatch.');
                }
            }
            $entry = intdiv($signal['available_ms'] + 59999, 60000) * 60000;
            if (! in_array($horizon, $input['horizons_minutes'], true) || isset($seen[$horizon])
                || $outcome['signal_id'] !== $signal['id'] || $outcome['source'] !== 'HYPOTHETICAL'
                || $scope['data_source'] !== 'HYPOTHETICAL' || $scope['label_policy_version'] !== $report['policy_version']
                || $scope['horizon_anchor'] !== $input['horizon_anchor'] || $outcome['horizon_anchor'] !== $input['horizon_anchor']
                || $outcome['entry_ms'] !== $entry || $outcome['end_ms'] !== $entry + $horizon * 60000
                || ($outcome['status'] === 'MATURED' && $outcome['end_ms'] > $input['as_of_ms'])
                || $scope['configuration_hash'] !== $job->configuration_hash || $scope['code_revision'] !== $job->code_revision
                || ! hash_equals($expectedExecutionHash, $scope['execution_hash']) || ! hash_equals($expectedInputHash, $outcome['input_hash'])) {
                throw new LogicException('Signal outcome identity, timing or evidence source mismatch.');
            }
            $seen[$horizon] = true;
            $key = self::digest([$signal['id'], $horizon, $report['policy_version'], $outcome['input_hash']]);
            $attributes = ['evaluation_key' => $key, 'signal_id' => $signal['id'], 'research_job_id' => $job->job_id,
                'exchange' => $scope['exchange'], 'category' => $scope['category'], 'symbol' => $scope['symbol'],
                'strategy_id' => $scope['strategy_id'], 'strategy_version' => $scope['strategy_version'],
                'source_timeframe_minutes' => $scope['source_timeframe_minutes'], 'horizon_minutes' => $horizon,
                'direction' => $scope['direction'], 'regime' => $scope['regime'], 'data_source' => 'HYPOTHETICAL',
                'policy_version' => $report['policy_version'], 'configuration_hash' => $scope['configuration_hash'],
                'dataset_hash' => $job->dataset_sha256, 'code_revision' => $job->code_revision,
                'input_hash' => $outcome['input_hash'], 'execution_hash' => $scope['execution_hash'],
                'status' => $outcome['status'], 'reason' => $outcome['reason'], 'horizon_anchor' => $outcome['horizon_anchor'],
                'decision_at' => self::at($signal['decision_ms']), 'available_at' => self::at($signal['available_ms']),
                'entry_at' => self::at($entry), 'horizon_end_at' => self::at($outcome['end_ms']),
                'evaluated_as_of' => self::at($input['as_of_ms']), 'configured_notional' => $input['notional'],
                'result' => $outcome, 'created_at' => CarbonImmutable::now('UTC')];
            foreach (['gross_pnl', 'fees', 'funding', 'net_pnl', 'net_return'] as $field) {
                $attributes[$field] = $outcome[$field] ?? null;
            }
            $existing = SignalOutcomeEvaluationRecord::query()->where('evaluation_key', $key)->first();
            if ($existing !== null && $existing->result != $outcome) {
                throw new LogicException('Divergent immutable signal outcome retry.');
            }
            $records[] = $existing ?? SignalOutcomeEvaluationRecord::query()->create(['id' => Str::uuid()->toString()] + $attributes);
        }

        return $records;
    }

    private function importProfile(ResearchJobRecord $job, array $input, array $report): array
    {
        if (($report['scope'] ?? null) != $input['scope'] || $report['scope']['data_source'] !== 'HYPOTHETICAL'
            || $report['scope']['code_revision'] !== $job->code_revision || $report['scope']['configuration_hash'] !== $job->configuration_hash
            || ($input['provenance']['dataset_id'] ?? null) !== $job->dataset_id
            || ($input['provenance']['dataset_hash'] ?? null) !== $job->dataset_sha256
            || ! hash_equals(SignalIntelligenceResultVerifier::cohortDatasetHash($input['observations']), $job->dataset_sha256)
            || $report['policy_version'] !== $input['policy']['version']
            || ! hash_equals(SignalIntelligenceResultVerifier::policyHash($input['policy']), $report['policy_hash'])
            || ! hash_equals(SignalIntelligenceResultVerifier::cohortInputHash($input), $report['input_hash'])) {
            throw new LogicException('Signal quality profile scope or policy mismatch.');
        }
        $key = self::digest([$report['input_hash'], $report['policy_hash']]);
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))', ['signal-profile:'.$key]);
        $existing = SignalQualityProfileRecord::query()->where('profile_key', $key)->first();
        if ($existing !== null && $existing->result != $report) {
            throw new LogicException('Divergent immutable signal quality retry.');
        }

        return [$existing ?? SignalQualityProfileRecord::query()->create(['id' => Str::uuid()->toString(), 'profile_key' => $key,
            'research_job_id' => $job->job_id, 'scope_hash' => self::digest($report['scope']), 'scope' => $report['scope'],
            'policy_version' => $report['policy_version'], 'policy_hash' => $report['policy_hash'], 'classification' => $report['classification'],
            'input_hash' => $report['input_hash'], 'result' => $report, 'as_of' => self::at($input['as_of_ms']), 'created_at' => CarbonImmutable::now('UTC')])];
    }

    private static function at(int $milliseconds): CarbonImmutable
    {
        if ($milliseconds <= 0 || $milliseconds > 253402300799000) {
            throw new InvalidArgumentException('Signal evidence timestamp is outside the supported range.');
        }

        return CarbonImmutable::createFromTimestampMs($milliseconds, 'UTC');
    }

    private static function digest(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
