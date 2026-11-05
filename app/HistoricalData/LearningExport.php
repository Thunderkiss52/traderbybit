<?php

declare(strict_types=1);

namespace App\HistoricalData;

use App\Backtest\Application\ChronologicalSplitter;
use App\Strategy\Application\StrategyRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class LearningExport
{
    public function __construct(private AcquisitionDataset $datasets, private SourcePolicy $policy, private BatchRepository $batches, private ChronologicalSplitter $splitter) {}

    public function partition(string $id, string $role, int $limit = 1000, int $after = 0): array
    {
        abort_unless(in_array($role, ['train', 'validation', 'holdout'], true) && $limit >= 1 && $limit <= 10000 && $after >= 0, 422, 'Invalid bounded chronological export.');
        $run = DB::table('historical_research_runs')->find($id);
        abort_if($run === null, 404);
        abort_unless($run->status === 'COMPLETED', 409, 'Only verified completed research trades can be exported.');
        $pin = $this->datasets->verify($run->dataset_pin);
        $this->policy->assertUse('NONCOMMERCIAL_LEARNING', json_decode($pin->license, true), true);
        $config = json_decode($run->config, true);
        $from = CarbonImmutable::parse($config['scope']['from']);
        $to = CarbonImmutable::parse($config['scope']['to']);
        $span = $to->timestamp - $from->timestamp;
        $a = $from->addSeconds((int) floor($span * 0.6));
        $b = $from->addSeconds((int) floor($span * 0.8));
        $embargo = $config['holding_bars'] * 300;
        [$start,$end] = match ($role) {
            'train' => [$from, $a],'validation' => [$a->addSeconds($embargo), $b],'holdout' => [$b->addSeconds($embargo), $to]
        };
        $rows = DB::table('historical_research_trades')->where('run_id', $id)->where('id', '>', $after)->where('entry_at', '>=', $start)->where('exit_at', '<', $end)->orderBy('id')->limit($limit)->get()->map(fn ($r) => ['cursor' => $r->id, 'strategy_id' => $run->strategy_id, 'strategy_version' => $run->strategy_version, 'dataset_pin' => $run->dataset_pin, 'configuration_hash' => $run->configuration_hash, 'entry_at' => $r->entry_at, 'exit_at' => $r->exit_at, 'features' => json_decode($r->features, true), 'regime' => $r->regime, 'label' => json_decode($r->trade, true)])->all();
        $this->batches->audit($run->batch_id, 'NONCOMMERCIAL_LEARNING_EXPORTED', ['run_id' => $id, 'partition' => $role, 'limit' => $limit, 'after' => $after, 'rows' => count($rows), 'embargo_seconds' => $embargo]);

        return ['run_id' => $id, 'partition' => $role, 'from' => $start->toISOString(), 'to_exclusive' => $end->toISOString(), 'embargo_seconds' => $embargo, 'attribution' => json_decode($pin->license, true), 'rows' => $rows, 'next_cursor' => $rows === [] ? null : end($rows)['cursor'], 'chronological_only' => true, 'automatic_training_or_promotion' => false];
    }

    public function walkForward(string $batch, array $input, array $options, ResearchService $research): array
    {
        $train = (int) ($options['train_hours'] ?? 720);
        $validation = (int) ($options['validation_hours'] ?? 168);
        $test = (int) ($options['test_hours'] ?? 168);
        $step = (int) ($options['step_hours'] ?? 168);
        $maximum = (int) ($options['max_windows'] ?? 2);
        abort_unless(min($train, $validation, $test, $step) > 0 && max($train, $validation, $test, $step) <= 8760 && $step >= $test && $maximum >= 1 && $maximum <= 3, 422, 'Invalid bounded walk-forward windows.');
        $gap = 24 * 3600;
        if (isset($input['definition_id'])) {
            $definition = DB::table('strategy_definitions')->find($input['definition_id']);
            abort_if($definition === null, 404);
            $d = json_decode($definition->definition, true);
            $gap = max($gap, (int) $d['timeout_bars'] * (int) $d['timeframe'] * 60);
        } else {
            $parameters = app(StrategyRegistry::class)->get($input['strategy_id'])->parameters();
            $gap = max($gap, (int) ($parameters['maximum_holding_minutes'] ?? 240) * 60);
        }
        $from = CarbonImmutable::parse($input['from']);
        $to = CarbonImmutable::parse($input['to']);
        $segments = $this->splitter->walkForward($from->toDateTimeImmutable(), $to->subSeconds(2 * $gap)->toDateTimeImmutable(), ['train_minutes' => $train * 60, 'validation_minutes' => $validation * 60, 'test_minutes' => $test * 60, 'step_minutes' => $step * 60]);
        abort_if($segments === [], 422, 'Insufficient history for chronological splits and conservative one-day embargo.');
        $windows = [];
        foreach (array_slice($segments, 0, $maximum) as $index => $window) {
            $row = [];
            foreach ($window as $i => $segment) {
                $local = $input;
                $local['from'] = $segment->from->modify('+'.($i * $gap).' seconds')->format(DATE_ATOM);
                $local['to'] = $segment->to->modify('+'.($i * $gap).' seconds')->format(DATE_ATOM);
                $run = $research->create($batch, $local);
                $row[$segment->kind] = ['run_id' => $run->id, 'from' => $local['from'], 'to' => $local['to'], 'independent_chronological_partition' => true];
            }$windows[] = $row;
        }
        $this->batches->audit($batch, 'WALK_FORWARD_REQUESTED', ['windows' => $windows, 'embargo_seconds' => $gap, 'selection_or_paper_promotion' => false]);

        return $windows;
    }
}
