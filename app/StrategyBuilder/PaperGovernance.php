<?php

declare(strict_types=1);

namespace App\StrategyBuilder;

use App\Backtest\Application\DatasetInspector;
use App\HistoricalData\SourcePolicy;
use App\PaperTrading\Application\GlobalPaperCapitalGuard;
use App\PaperTrading\Application\PaperTopologySynchronizer;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\Strategy\Application\StrategyRegistry;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class PaperGovernance
{
    public function __construct(private ForwardEvaluator $forward, private DatasetInspector $datasets, private ExecutableStore $executables, private PaperTopologySynchronizer $topology, private GlobalPaperCapitalGuard $capital) {}

    public function certify(int $id, string $runId): array
    {
        $row = $this->definition($id);
        $d = json_decode($row->definition, true, flags: JSON_THROW_ON_ERROR);
        $reasons = EvaluationProfile::paperBlockers($d);
        if ($reasons !== []) {
            throw ValidationException::withMessages(['eligibility' => implode(', ', $reasons)]);
        }
        $run = DB::table('strategy_definition_runs')->where('id', $runId)->where('definition_id', $id)->first();
        abort_if($run === null, 404);
        $metadata = json_decode($run->metadata, true, flags: JSON_THROW_ON_ERROR);
        $result = json_decode($run->result, true, flags: JSON_THROW_ON_ERROR);
        if ($row->engine_checksum === null || $metadata['engine_sha256'] !== $row->engine_checksum || $metadata['definition_checksum'] !== $row->checksum || $metadata['funding_complete'] !== true) {
            throw ValidationException::withMessages(['evidence' => 'Matching immutable executable, definition and complete funding evidence are required.']);
        }
        $this->executables->resolve($row->engine_checksum);
        $this->datasets->verifyPin($metadata['dataset_pin']['id']);
        app(SourcePolicy::class)->assertMarketUse($metadata['scope']['exchange'], $metadata['scope']['symbol'], new DateTimeImmutable($metadata['scope']['from']), new DateTimeImmutable($metadata['scope']['to']), 'PAPER_PROMOTION');
        $signals = $result['signals'];
        if ($signals === []) {
            throw ValidationException::withMessages(['evidence' => 'No actionable signal is available for a real Go/PAPER parity probe; select another complete research window.']);
        }
        $samples = [];
        $indexes = array_unique([0, intdiv(count($signals), 2), count($signals) - 1]);
        foreach ($indexes as $index) {
            $signal = $signals[$index];
            $at = new DateTimeImmutable($signal['decision_time']);
            $forward = $this->forward->evaluate($d, $metadata['scope']['exchange'], $metadata['scope']['symbol'], $at, $row->engine_checksum);
            $actual = $forward['signals'][0] ?? null;
            if ($actual === null || $actual['side'] !== $signal['side'] || $actual['stop_loss'] !== $signal['stop_loss'] || $actual['take_profit'] !== $signal['take_profit']) {
                throw ValidationException::withMessages(['evidence' => 'Historical/forward Go strategy semantics diverged.']);
            }
            $samples[] = ['at' => $signal['decision_time'], 'side' => $signal['side'], 'stop_loss' => $signal['stop_loss'], 'take_profit' => $signal['take_profit']];
        }
        $this->datasets->verifyPin($metadata['dataset_pin']['id']);
        DB::transaction(function () use ($row, $metadata, $runId, $samples): void {
            DB::table('strategy_parity_certificates')->updateOrInsert(['definition_id' => $row->id, 'exchange' => $metadata['scope']['exchange'], 'symbol' => $metadata['scope']['symbol']], ['definition_checksum' => $row->checksum, 'engine_checksum' => $row->engine_checksum, 'run_id' => $runId, 'proof' => json_encode(['samples' => $samples, 'dataset_pin' => $metadata['dataset_pin']], JSON_THROW_ON_ERROR), 'created_at' => now('UTC')]);
            $this->audit($row->id, 'GO_PAPER_PARITY_VERIFIED', ['run_id' => $runId, 'samples' => count($samples)]);
        }, 3);

        return $this->eligibility($row->id);
    }

    public function eligibility(int $id): array
    {
        $row = $this->definition($id);
        $d = json_decode($row->definition, true, flags: JSON_THROW_ON_ERROR);
        $reasons = EvaluationProfile::paperBlockers($d);
        foreach ($d['exchanges'] as $exchange) {
            if (! in_array($exchange, ['bybit', 'binance'], true)) {
                $reasons[] = 'PAPER_EXECUTION_ADAPTER_UNVERIFIED:'.$exchange;
            }
            if ($exchange === 'binance' && config('exchanges.binance_paper_provisioning_approved') !== true) {
                $reasons[] = 'BINANCE_PROVISIONING_NOT_APPROVED';
            }
            foreach ($d['symbols'] as $symbol) {
                $proof = DB::table('strategy_parity_certificates')->where(['definition_id' => $id, 'exchange' => $exchange, 'symbol' => $symbol, 'definition_checksum' => $row->checksum])->first();
                if ($proof === null || $proof->engine_checksum !== $row->engine_checksum) {
                    $reasons[] = 'PARITY_EVIDENCE_REQUIRED:'.$exchange.':'.$symbol;

                    continue;
                }
                try {
                    $this->datasets->verifyPin(json_decode($proof->proof, true)['dataset_pin']['id']);
                } catch (\Throwable) {
                    $reasons[] = 'PAPER_DATASET_EVIDENCE_STALE:'.$exchange.':'.$symbol;
                }
            }
        }
        try {
            $this->executables->resolve($row->engine_checksum ?? '');
        } catch (\Throwable) {
            $reasons[] = 'PINNED_EXECUTABLE_UNAVAILABLE';
        }
        if (in_array($row->status, ['ARCHIVED', 'REJECTED'], true)) {
            $reasons[] = 'STRATEGY_LIFECYCLE_BLOCKED';
        }

        return ['paper_eligible' => $reasons === [], 'technical_eligible' => $reasons === [], 'owner_authorized' => DB::table('strategy_definition_approvals')->where('definition_id', $id)->exists(), 'reasons' => array_values(array_unique($reasons)), 'accounts' => PaperAccountRecord::query()->where('is_primary', true)->where('primary_strategy_id', $row->strategy_id)->get()];
    }

    public function approve(int $id, string $checksum, string $reference): array
    {
        $eligible = $this->eligibility($id);
        if (! $eligible['paper_eligible']) {
            throw ValidationException::withMessages(['eligibility' => implode(', ', $eligible['reasons'])]);
        }

        return DB::transaction(function () use ($id, $checksum, $reference): array {
            DB::select('SELECT pg_advisory_xact_lock(20261105, 10001)');
            $this->capital->lockEntryDecision();
            $row = DB::table('strategy_definitions')->where('id', $id)->lockForUpdate()->first();
            abort_unless(hash_equals($row->checksum, $checksum), 409, 'Strategy changed since owner review.');
            $d = json_decode($row->definition, true);
            $registration = DB::table('strategy_primary_registrations')->where('strategy_id', $row->strategy_id)->first();
            if ($registration !== null) {
                $scopes = json_decode($registration->exchanges, true);
                sort($scopes);
                $requested = $d['exchanges'];
                sort($requested);
                abort_unless($scopes === $requested, 409, 'A version handover must preserve exchange scopes; create a separate strategy for a different topology.');
            }
            $existing = DB::table('strategy_definition_approvals')->where('definition_id', $id)->exists();
            if ($existing) {
                return $this->eligibility($id);
            }
            $before = DB::table('paper_accounts')->where('is_primary', true)->orderBy('id')->get();
            $dry = $this->topology->sync(false);
            abort_if($dry['missing_pairs'] !== [], 409, 'Existing PRIMARY topology must be complete before adding a strategy.');
            $evidence = $this->eligibility($id);
            abort_unless($evidence['paper_eligible'], 409, 'Eligibility evidence became stale.');
            DB::table('strategy_definition_approvals')->insert(['definition_id' => $id, 'reference' => $reference, 'actor' => 'configured-research-owner', 'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR), 'created_at' => now('UTC')]);
            DB::table('strategy_primary_registrations')->insertOrIgnore(['strategy_id' => $row->strategy_id, 'definition_id' => $id, 'exchanges' => json_encode($d['exchanges']), 'enabled' => false, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            DB::table('strategy_definitions')->where('id', $id)->update(['status' => 'PAPER_ELIGIBLE']);
            $topology = $this->topology->sync(true);
            $after = DB::table('paper_accounts')->whereIn('id', $before->pluck('id'))->orderBy('id')->get();
            if ($before->toJson() !== $after->toJson()) {
                throw new \RuntimeException('Existing account rows would change; approval rolled back.');
            }
            $this->audit($id, 'OWNER_PRIMARY_APPROVED', ['reference' => $reference, 'topology' => $topology, 'new_accounts' => 'BLOCKED_UNFUNDED', 'activation' => 'SEPARATE_OWNER_ACTION']);

            return $this->eligibility($id) + ['topology' => $topology];
        }, 3);
    }

    public function activate(int $id, string $checksum, string $reference, bool $handover): array
    {
        return DB::transaction(function () use ($id, $checksum, $reference, $handover): array {
            DB::select('SELECT pg_advisory_xact_lock(20261105, 10001)');
            $this->capital->lockEntryDecision();
            $row = DB::table('strategy_definitions')->where('id', $id)->lockForUpdate()->first();
            abort_if($row === null, 404);
            abort_unless(hash_equals($row->checksum, $checksum) && DB::table('strategy_definition_approvals')->where('definition_id', $id)->exists(), 409, 'Explicit owner PRIMARY approval of this immutable version is required.');
            $evidence = $this->eligibility($id);
            abort_unless($evidence['paper_eligible'], 409, 'PAPER evidence is stale or incomplete.');
            $registration = DB::table('strategy_primary_registrations')->where('strategy_id', $row->strategy_id)->lockForUpdate()->first();
            abort_if($registration === null, 409, 'PRIMARY registration is missing.');
            $accounts = PaperAccountRecord::query()->where('is_primary', true)->where('primary_strategy_id', $row->strategy_id)->lockForUpdate()->get();
            if ($registration->definition_id !== $id) {
                abort_unless($handover, 409, 'Account versions remain pinned; confirm an explicit flat-account version handover.');
                abort_if(DB::table('virtual_positions')->whereIn('paper_account_id', $accounts->pluck('id'))->whereIn('status', ['OPEN', 'PARTIALLY_CLOSED'])->exists() || DB::table('paper_signal_states')->whereIn('paper_account_id', $accounts->pluck('id'))->whereIn('status', ['PENDING_ENTRY', 'ACTIVE'])->exists(), 409, 'Version handover requires flat accounts without pending intents.');
                $d = json_decode($row->definition, true);
                $parameters = ['version' => (string) $row->version, '_dsl_definition' => $d, '_dsl_engine_checksum' => $row->engine_checksum];
                foreach ($accounts as $account) {
                    $account->update(['strategy_version' => (string) $row->version, 'strategy_parameters' => $parameters, 'configuration_hash' => StrategyRegistry::hash($parameters)]);
                }
            }
            if ($registration->enabled && $registration->definition_id === $id) {
                return $this->eligibility($id);
            }
            DB::table('strategy_primary_registrations')->where('strategy_id', $row->strategy_id)->update(['definition_id' => $id, 'enabled' => true, 'active_from' => now('UTC'), 'updated_at' => now('UTC')]);
            DB::table('strategy_definitions')->where('strategy_id', $row->strategy_id)->where('status', 'ACTIVE_PAPER')->update(['status' => 'PAPER_ELIGIBLE']);
            DB::table('strategy_definitions')->where('id', $id)->update(['status' => 'ACTIVE_PAPER']);
            $this->audit($id, 'OWNER_PAPER_VERSION_ACTIVATED', ['reference' => $reference, 'explicit_version_handover' => $handover, 'account_statuses_unchanged' => true, 'funding' => 'SEPARATE_AUTHORIZATION_REQUIRED']);

            return $this->eligibility($id);
        }, 3);
    }

    private function definition(int $id): object
    {
        $row = DB::table('strategy_definitions')->find($id);
        abort_if($row === null, 404);

        return $row;
    }

    private function audit(int $id, string $action, array $details): void
    {
        DB::table('strategy_workflow_events')->insert(['definition_id' => $id, 'action' => $action, 'actor' => 'configured-research-owner', 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now('UTC')]);
    }
}
