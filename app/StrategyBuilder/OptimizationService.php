<?php

declare(strict_types=1);

namespace App\StrategyBuilder;

use App\Backtest\Application\ChronologicalSplitter;
use App\Backtest\Application\DatasetInspector;
use App\Research\Application\AutonomyPermissionService;
use App\Strategy\Application\StrategyRegistry;
use DateTimeImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class OptimizationService
{
    public function __construct(private HistoricalResearch $backtests, private DefinitionStore $definitions, private DefinitionValidator $validator, private ChronologicalSplitter $splitter, private AutonomyPermissionService $autonomy, private DatasetInspector $datasets, private ExecutableStore $executables, private ShadowService $shadows) {}

    public function create(int $definitionId, array $scope, array $options): object
    {
        abort_unless($this->autonomy->isAllowed('tune_parameters'), 409, 'Autonomous parameter tuning is disabled or killed.');
        $base = DB::table('strategy_definitions')->find($definitionId);
        abort_if($base === null, 404);
        $definition = $this->validator->validate(json_decode($base->definition, true));
        if (empty($definition['parameters'])) {
            throw ValidationException::withMessages(['parameters' => 'Declare bounded optimization ranges in the immutable definition first.']);
        }
        $options = array_replace(['max_candidates' => 8, 'max_windows' => 2, 'train_hours' => 48, 'validation_hours' => 12, 'test_hours' => 12, 'step_hours' => 24, 'seed' => 0, 'start_shadow' => false], $options);
        if ($options['max_candidates'] < 1 || $options['max_candidates'] > 32 || $options['max_windows'] < 1 || $options['max_windows'] > 3 || $options['step_hours'] < $options['test_hours']) {
            throw ValidationException::withMessages(['options' => 'Invalid research resource bounds or overlapping test windows.']);
        }
        $this->windows($scope, $options, $definition);
        $this->variants($definition, (int) $options['max_candidates']);
        $baseline = $this->backtests->run($definitionId, $scope);
        $engine = hash_file('sha256', $this->executables->resolve($base->engine_checksum ?? null));
        $id = StrategyRegistry::hash([$base->checksum, $scope, $options, $baseline->id, $engine]);
        DB::transaction(function () use ($id, $definitionId, $base, $engine, $scope, $options, $baseline): void {
            DB::select('SELECT pg_advisory_xact_lock(20261105, 10003)');
            if (DB::table('strategy_optimization_experiments')->where('id', $id)->exists()) {
                return;
            }
            abort_unless($this->autonomy->isAllowed('tune_parameters'), 409, 'Autonomous tuning is disabled or killed.');
            abort_if(DB::table('strategy_optimization_experiments')->whereIn('status', ['QUEUED', 'RUNNING', 'CANCEL_REQUESTED'])->count() >= (int) config('strategy_builder.max_active_optimizations', 2), 409, 'Active optimization resource limit reached.');
            DB::table('strategy_optimization_experiments')->insert(['id' => $id, 'definition_id' => $definitionId, 'definition_checksum' => $base->checksum, 'engine_checksum' => $engine, 'scope' => json_encode($scope, JSON_THROW_ON_ERROR), 'options' => json_encode($options, JSON_THROW_ON_ERROR), 'baseline_run_id' => $baseline->id, 'status' => 'QUEUED', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            $this->audit($definitionId, 'OPTIMIZATION_QUEUED', ['experiment_id' => $id, 'baseline_run_id' => $baseline->id, 'options' => $options]);
        }, 3);

        return DB::table('strategy_optimization_experiments')->find($id);
    }

    public function run(string $id): void
    {
        $experiment = DB::transaction(function () use ($id): ?object {
            $row = DB::table('strategy_optimization_experiments')->where('id', $id)->lockForUpdate()->first();
            if ($row === null || in_array($row->status, ['COMPLETED', 'CANCELLED', 'REJECTED'], true)) {
                return null;
            }
            if ($row->status === 'RUNNING' && $row->lease_until !== null && new DateTimeImmutable($row->lease_until) > new DateTimeImmutable('now')) {
                return null;
            }
            if ($row->attempts >= 2) {
                DB::table('strategy_optimization_experiments')->where('id', $id)->update(['status' => 'FAILED', 'error' => 'EVALUATION_ATTEMPTS_EXHAUSTED', 'lease_until' => null, 'updated_at' => now('UTC')]);

                return null;
            }
            DB::table('strategy_optimization_experiments')->where('id', $id)->update(['status' => 'RUNNING', 'attempts' => $row->attempts + 1, 'lease_until' => now('UTC')->addSeconds(1900), 'updated_at' => now('UTC')]);

            return $row;
        }, 3);
        if ($experiment === null) {
            return;
        }
        try {
            $this->guard($id);
            $base = DB::table('strategy_definitions')->find($experiment->definition_id);
            if ($base->checksum !== $experiment->definition_checksum || ! hash_equals($experiment->engine_checksum, hash_file('sha256', $this->executables->resolve($base->engine_checksum ?? null)))) {
                throw new \RuntimeException('Immutable research identity changed.');
            }
            $definition = json_decode($base->definition, true, flags: JSON_THROW_ON_ERROR);
            $scope = json_decode($experiment->scope, true, flags: JSON_THROW_ON_ERROR);
            $options = json_decode($experiment->options, true, flags: JSON_THROW_ON_ERROR);
            $baseline = DB::table('strategy_definition_runs')->find($experiment->baseline_run_id);
            $pin = json_decode($baseline->metadata, true)['dataset_pin'];
            $this->datasets->verifyPin($pin['id']);
            $variants = $this->variants($definition, (int) $options['max_candidates']);
            $windows = $this->windows($scope, $options, $definition);
            $results = [];
            $candidateCards = [];
            foreach ($windows as $window => $segments) {
                $ranking = [];
                foreach ($variants as $variant) {
                    $this->guard($id);
                    $candidate = $definition;
                    foreach ($variant as $path => $value) {
                        Arr::set($candidate, $path, $value);
                        $candidate['parameters'][$path]['default'] = $value;
                    }
                    $candidate['id'] = 'user_opt_'.substr(StrategyRegistry::hash([$experiment->definition_checksum, $variant]), 0, 32);
                    $candidate['version'] = 1;
                    $record = $this->definitions->save($candidate, 'autonomous-research', $experiment->engine_checksum);
                    $card = ['window' => $window, 'parameters' => $variant, 'runs' => [], 'sample_sufficient' => true];
                    foreach (['train', 'validation'] as $kind) {
                        $this->guard($id);
                        $local = $scope;
                        [$local['from'], $local['to']] = $segments[$kind];
                        $local['seed'] = $options['seed'];
                        $run = $this->backtests->run($record->id, $local);
                        $report = json_decode($run->result, true)['report'];
                        $card['runs'][$kind] = ['id' => $run->id, 'metrics' => $report];
                        $card['sample_sufficient'] = $card['sample_sufficient'] && $report['total_trades'] >= 1;
                    }
                    $validation = $card['runs']['validation']['metrics'];
                    $card['score'] = (float) $validation['average_r'] - 0.5 * (float) $validation['max_drawdown_percent'];
                    $card['status'] = $card['sample_sufficient'] ? 'EVALUATED' : 'REJECTED_INSUFFICIENT_TRADES';
                    $candidateCards[$record->id][] = $card;
                    DB::table('strategy_optimization_candidates')->updateOrInsert(['experiment_id' => $id, 'definition_id' => $record->id], ['parameters' => json_encode($variant), 'scorecard' => json_encode($candidateCards[$record->id], JSON_THROW_ON_ERROR), 'status' => $card['sample_sufficient'] ? 'EXPERIMENTAL' : 'REJECTED']);
                    if ($card['sample_sufficient']) {
                        $ranking[] = ['definition_id' => $record->id, 'score' => $card['score'], 'checksum' => $record->checksum];
                    }
                }
                usort($ranking, fn ($a, $b) => ($b['score'] <=> $a['score']) ?: strcmp($a['checksum'], $b['checksum']));
                if ($ranking === []) {
                    $results[] = ['window' => $window, 'status' => 'REJECTED_INSUFFICIENT_TRADES'];

                    continue;
                }
                $selected = $ranking[0];
                $this->guard($id);
                $local = $scope;
                [$local['from'], $local['to']] = $segments['test'];
                $local['seed'] = $options['seed'];
                $test = $this->backtests->run($selected['definition_id'], $local);
                $results[] = ['window' => $window, 'status' => 'COMPLETED', 'selected_definition_id' => $selected['definition_id'], 'test_run_id' => $test->id, 'test' => json_decode($test->result, true), 'segments' => $segments];
                DB::table('strategy_optimization_candidates')->where(['experiment_id' => $id, 'definition_id' => $selected['definition_id']])->update(['status' => 'SELECTED']);
            }
            $this->guard($id);
            $this->datasets->verifyPin($pin['id']);
            if (DB::table('strategy_definitions')->find($experiment->definition_id)->checksum !== $experiment->definition_checksum) {
                throw new \RuntimeException('Original strategy changed.');
            }
            $completed = array_values(array_filter($results, fn ($r) => $r['status'] === 'COMPLETED'));
            $shadow = ['status' => 'NOT_REQUESTED'];
            if ($options['start_shadow'] && $completed !== []) {
                if ($this->autonomy->isAllowed('launch_shadow')) {
                    try {
                        $last = $completed[count($completed) - 1];
                        $this->shadows->set($last['selected_definition_id'], true);
                        $shadow = ['status' => 'STARTED', 'definition_id' => $last['selected_definition_id']];
                    } catch (\Throwable) {
                        $shadow = ['status' => 'UNSUPPORTED_PROFILE'];
                    }
                } else {
                    $shadow = ['status' => 'AUTONOMY_BLOCKED'];
                }
            }
            DB::table('strategy_optimization_experiments')->where('id', $id)->update(['status' => $completed === [] ? 'REJECTED' : 'COMPLETED', 'results' => json_encode(['windows' => $results, 'original_unchanged' => true, 'selection_policy' => 'TRAIN_VALIDATION_ONLY_TEST_UNTOUCHED', 'automatic_paper_promotion' => false, 'shadow' => $shadow], JSON_THROW_ON_ERROR), 'error' => null, 'lease_until' => null, 'updated_at' => now('UTC')]);
            $this->audit($experiment->definition_id, 'OPTIMIZATION_FINISHED', ['experiment_id' => $id, 'status' => $completed === [] ? 'REJECTED' : 'COMPLETED']);
        } catch (\Throwable $e) {
            $cancelled = ! $this->autonomy->isAllowed('tune_parameters') || DB::table('strategy_optimization_experiments')->where('id', $id)->value('status') === 'CANCEL_REQUESTED';
            DB::table('strategy_optimization_experiments')->where('id', $id)->update(['status' => $cancelled ? 'CANCELLED' : 'FAILED', 'error' => $cancelled ? 'OWNER_OR_KILL_SWITCH_STOP' : 'DATASET_OR_EVALUATION_FAILED', 'lease_until' => null, 'updated_at' => now('UTC')]);
            if (! $cancelled) {
                throw $e;
            }
        }
    }

    private function audit(int $definitionId, string $action, array $details): void
    {
        DB::table('strategy_workflow_events')->insert(['definition_id' => $definitionId, 'action' => $action, 'actor' => 'autonomous-research', 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now('UTC')]);
    }

    private function guard(string $id): void
    {
        if (! $this->autonomy->isAllowed('tune_parameters') || DB::table('strategy_optimization_experiments')->where('id', $id)->value('status') !== 'RUNNING') {
            throw new \RuntimeException('Research stopped by owner or kill switch.');
        }
    }

    private function variants(array $definition, int $limit): array
    {
        $variants = [[]];
        $ranges = $definition['parameters'];
        ksort($ranges);
        foreach ($ranges as $path => $range) {
            $values = [];
            $steps = (int) floor(($range['max'] - $range['min']) / $range['step'] + 1e-9);
            if ($steps > 31) {
                throw ValidationException::withMessages(['parameters' => 'Parameter grid exceeds the bounded search budget.']);
            }
            for ($n = 0; $n <= $steps; $n++) {
                $value = $range['min'] + $n * $range['step'];
                $values[] = preg_match('/^(?:timeout_bars|cooldown_bars|indicators\.[a-z][a-z0-9_]*\.(?:period|slow_period|signal_period))$/D', $path) ? (int) $value : $value;
            }
            $next = [];
            foreach ($variants as $variant) {
                foreach ($values as $value) {
                    $next[] = $variant + [$path => $value];
                }
            }
            if (count($next) > $limit) {
                throw ValidationException::withMessages(['parameters' => 'Grid exceeds max_candidates; narrow the declared ranges.']);
            }
            $variants = $next;
        }

        return $variants;
    }

    private function windows(array $scope, array $options, array $definition): array
    {
        $from = new DateTimeImmutable($scope['from']);
        $to = new DateTimeImmutable($scope['to']);
        if ($from->getTimestamp() % 3600 !== 0 || $to->getTimestamp() % 3600 !== 0) {
            throw ValidationException::withMessages(['scope' => 'Optimization boundaries must align to UTC hours.']);
        }
        $holding = max($definition['timeout_bars'], $definition['parameters']['timeout_bars']['max'] ?? 0);
        $gap = (int) ceil($holding * (int) $definition['timeframe'] / 60) * 3600;
        $raw = $this->splitter->walkForward($from, $to->modify('-'.(2 * $gap).' seconds'), ['train_minutes' => $options['train_hours'] * 60, 'validation_minutes' => $options['validation_hours'] * 60, 'test_minutes' => $options['test_hours'] * 60, 'step_minutes' => $options['step_hours'] * 60]);
        if ($raw === []) {
            throw ValidationException::withMessages(['scope' => 'Insufficient history for chronological windows and holding-horizon embargo.']);
        }
        $out = [];
        foreach (array_slice($raw, 0, $options['max_windows']) as $segments) {
            $row = [];
            foreach ($segments as $i => $segment) {
                $offset = $i * $gap;
                $row[$segment->kind] = [$segment->from->modify("+{$offset} seconds")->format(DATE_ATOM), $segment->to->modify("+{$offset} seconds")->format(DATE_ATOM)];
            } $out[] = $row;
        }

        return $out;
    }
}
