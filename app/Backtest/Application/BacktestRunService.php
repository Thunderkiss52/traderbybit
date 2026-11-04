<?php

declare(strict_types=1);

namespace App\Backtest\Application;

use App\Backtest\Domain\BacktestCancelled;
use App\Backtest\Domain\Segment;
use App\Backtest\Domain\SimulationResult;
use App\Backtest\Infrastructure\Persistence\BacktestEquitySnapshotRecord;
use App\Backtest\Infrastructure\Persistence\BacktestOptimizationRecord;
use App\Backtest\Infrastructure\Persistence\BacktestReportRecord;
use App\Backtest\Infrastructure\Persistence\BacktestRunRecord;
use App\Backtest\Infrastructure\Persistence\BacktestSegmentRecord;
use App\Backtest\Infrastructure\Persistence\BacktestTradeRecord;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\Strategy\Application\StrategyInputFactory;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Throwable;

class BacktestRunService
{
    public function __construct(
        private DatasetInspector $datasets,
        private ChronologicalSplitter $splitter,
        private BacktestSimulationEngine $engine,
        private PerformanceMetricCalculator $metrics,
        private GridOptimizer $optimizer,
        private array $config,
    ) {}

    public function run(array $symbols, DateTimeImmutable $from, DateTimeImmutable $to, bool $walkForward = false, ?array $strategyOverride = null, string $exchange = 'bybit'): BacktestRunRecord
    {
        sort($symbols);
        $strategy = $strategyOverride ?? (array) config('strategy');
        // The run argument owns exchange selection; overrides cannot redirect data reads.
        unset($strategy['_exchange']);
        if ($exchange !== 'bybit') {
            $strategy['_exchange'] = $exchange;
        }
        $datasetSymbols = $symbols;
        if (($strategy['_research_strategy_id'] ?? null) === 'btc_eth_lead_lag') {
            foreach ($symbols as $symbol) {
                $peer = StrategyInputFactory::peerSymbol($symbol);
                if ($peer !== null) {
                    $datasetSymbols[] = $peer;
                }
            }
            $datasetSymbols = array_values(array_unique($datasetSymbols));
            sort($datasetSymbols);
        }
        $dataset = $this->datasets->inspect($datasetSymbols, $from, $to, (int) $this->config['warmup_bars'], $exchange);
        $paper = (array) config('paper');
        if ($exchange === 'binance') {
            foreach (['paper_taker_rate', 'paper_slippage_rate'] as $key) {
                $value = config('exchanges.binance.'.$key);
                if (! is_string($value) || ! preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value)
                    || BigDecimal::of($value)->isGreaterThanOrEqualTo(1)) {
                    throw new \RuntimeException('Binance historical execution cost unavailable: '.$key);
                }
            }
            $paper['fees']['taker_rate'] = config('exchanges.binance.paper_taker_rate');
            $paper['slippage_rate'] = config('exchanges.binance.paper_slippage_rate');
        }
        $type = $walkForward ? 'walk_forward' : 'split';
        $fingerprint = $this->fingerprint([$type, $symbols, $from->format(DATE_ATOM), $to->format(DATE_ATOM), $dataset['checksum'], $strategy, $paper, $this->config]);
        $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'traderbybit:backtest:'.$fingerprint)->toString();
        $existing = BacktestRunRecord::query()->find($id);
        if ($existing?->status === 'COMPLETED') {
            return $existing;
        }
        $lock = Cache::lock('backtest:'.$fingerprint, (int) $this->config['queue']['lock_seconds']);
        if (! $lock->get()) {
            throw new \RuntimeException('An identical backtest is already running.');
        }

        try {
            $run = BacktestRunRecord::query()->updateOrCreate(['id' => $id], [
                'fingerprint' => $fingerprint, 'type' => $type, 'status' => 'RUNNING', 'from_at' => $from, 'to_at' => $to, 'symbols' => $symbols,
                'strategy_config' => $strategy, 'risk_config' => $paper, 'execution_config' => $paper,
                'strategy_version' => $strategy['version'], 'risk_version' => $paper['risk_version'], 'execution_version' => $paper['execution_version'],
                'backtest_version' => $this->config['version'], 'schema_version' => $this->config['schema_version'], 'dataset_checksum' => $dataset['checksum'],
                'seed' => null, 'progress' => 0, 'warnings' => $dataset['warnings'], 'errors' => [], 'started_at' => now('UTC'), 'completed_at' => null,
            ]);
            if ($existing !== null) {
                $this->clearIncomplete($run->id);
            }
            $this->guardCancellation($run);
            $payload = $walkForward ? $this->executeWalkForward($run, $symbols, $from, $to, $strategy, $paper) : $this->executeSplit($run, $symbols, $from, $to, $strategy, $paper);
            // Market observations are mutable in this schema. A changed source
            // cannot be published under the checksum captured before simulation.
            $finalDataset = $this->datasets->inspect($datasetSymbols, $from, $to, (int) $this->config['warmup_bars'], $exchange);
            if ($finalDataset['checksum'] !== $dataset['checksum']) {
                throw new \RuntimeException('Historical inputs changed during backtest.');
            }
            $run->update(['status' => 'COMPLETED', 'progress' => 100, 'metrics' => $payload['metrics'], 'warnings' => array_values(array_unique(array_merge($dataset['warnings'], $payload['warnings']))), 'completed_at' => now('UTC')]);
            BacktestReportRecord::query()->updateOrCreate(['backtest_run_id' => $run->id, 'kind' => 'summary'], ['payload' => $payload]);
        } catch (BacktestCancelled) {
            $run->update(['status' => 'CANCELLED', 'completed_at' => now('UTC')]);
            $result = $run->fresh();
            $lock->release();

            return $result;
        } catch (Throwable $e) {
            if (isset($run)) {
                $run->update(['status' => 'FAILED', 'errors' => [['class' => $e::class, 'message' => $e->getMessage()]]]);
            }
            $lock->release();
            throw $e;
        }
        $result = $run->fresh();
        $lock->release();

        return $result;
    }

    private function executeSplit(BacktestRunRecord $run, array $symbols, DateTimeImmutable $from, DateTimeImmutable $to, array $strategy, array $paper): array
    {
        [$train, $validation, $test] = $this->splitter->split($from, $to, (array) $this->config['split']);
        $optimized = $this->optimizer->optimize($symbols, $train, $validation, $strategy, $paper, (string) $this->config['starting_balance'], $strategy['_research_optimization'] ?? null);
        $this->guardCancellation($run);
        $this->saveOptimizations($run->id, 0, $optimized);
        $selected = $optimized['selected']['strategy'];
        $warnings = [];
        $allTrades = [];
        $allEquity = [];
        foreach ([$train, $validation, $test] as $index => $segment) {
            $this->guardCancellation($run);
            $result = $this->engine->simulate($symbols, $segment, $selected, $paper, (string) $this->config['starting_balance'], $strategy['_exchange'] ?? 'bybit');
            $metrics = $this->metrics->calculate($result->trades, $result->equity, (array) $this->config['returns']);
            $this->saveSegment($run->id, $segment, $selected, $result, $metrics);
            $warnings = array_merge($warnings, $result->warnings);
            $allTrades = array_merge($allTrades, $result->trades);
            $allEquity = array_merge($allEquity, $result->equity);
            $run->update(['progress' => 25 + (($index + 1) * 25)]);
        }
        $testMetrics = $this->metrics->calculate($this->segmentTrades($run->id, 'test'), $this->segmentEquity($run->id, 'test'), (array) $this->config['returns']);
        $stress = $this->stress($symbols, $test, $selected, $paper);
        BacktestReportRecord::query()->updateOrCreate(['backtest_run_id' => $run->id, 'kind' => 'stress'], ['payload' => $stress]);

        return ['metrics' => ['test' => $testMetrics, 'all_segments' => $this->metrics->calculate($allTrades, $allEquity, (array) $this->config['returns'])], 'selected_parameters' => $optimized['selected']['parameters'], 'stress' => $stress, 'warnings' => $warnings, 'test_is_out_of_sample' => true];
    }

    private function executeWalkForward(BacktestRunRecord $run, array $symbols, DateTimeImmutable $from, DateTimeImmutable $to, array $strategy, array $paper): array
    {
        $windows = $this->splitter->walkForward($from, $to, (array) $this->config['walk_forward']);
        if ($windows === []) {
            throw new \RuntimeException('Date range is too short for one walk-forward window.');
        }
        $oosTrades = [];
        $oosEquity = [];
        $warnings = [];
        $oosBalance = (string) $this->config['starting_balance'];
        foreach ($windows as $windowIndex => [$train, $validation, $test]) {
            $this->guardCancellation($run);
            $optimized = $this->optimizer->optimize($symbols, $train, $validation, $strategy, $paper, (string) $this->config['starting_balance'], $strategy['_research_optimization'] ?? null);
            $this->saveOptimizations($run->id, $windowIndex, $optimized);
            $selected = $optimized['selected']['strategy'];
            foreach ([$train, $validation] as $segment) {
                $empty = new SimulationResult([], [], [], ['optimization_only_not_persisted_as_trades']);
                $this->saveSegment($run->id, $segment, $selected, $empty, $optimized['selected'][$segment->kind.'_metrics']);
            }
            $result = $this->engine->simulate($symbols, $test, $selected, $paper, $oosBalance, $strategy['_exchange'] ?? 'bybit');
            $metric = $this->metrics->calculate($result->trades, $result->equity, (array) $this->config['returns']);
            $this->saveSegment($run->id, $test, $selected, $result, $metric);
            foreach ($result->trades as $trade) {
                $oosTrades[hash('sha256', $trade['symbol'].'|'.$trade['signal_at']->format('U.u'))] = $trade;
            }
            foreach ($result->equity as $snapshot) {
                $oosEquity[$snapshot['recorded_at']->format('U')] = $snapshot;
            }
            if ($result->equity !== []) {
                $oosBalance = (string) $result->equity[array_key_last($result->equity)]['equity'];
            }
            $warnings = array_merge($warnings, $result->warnings);
            $run->update(['progress' => (int) floor((($windowIndex + 1) / count($windows)) * 100)]);
        }
        ksort($oosEquity, SORT_NUMERIC);

        return ['metrics' => ['out_of_sample' => $this->metrics->calculate(array_values($oosTrades), array_values($oosEquity), (array) $this->config['returns'])], 'windows' => count($windows), 'warnings' => $warnings, 'out_of_sample_only' => true];
    }

    private function saveSegment(string $runId, Segment $segment, array $parameters, SimulationResult $result, array $metrics): void
    {
        DB::transaction(function () use ($runId, $segment, $parameters, $result, $metrics): void {
            $id = Uuid::uuid5(Uuid::NAMESPACE_URL, "{$runId}|{$segment->windowIndex}|{$segment->kind}")->toString();
            BacktestSegmentRecord::query()->updateOrCreate(['id' => $id], ['backtest_run_id' => $runId, 'window_index' => $segment->windowIndex, 'kind' => $segment->kind, 'from_at' => $segment->from, 'to_at' => $segment->to, 'parameters' => $parameters, 'metrics' => $metrics, 'warnings' => $result->warnings, 'decisions' => $result->decisions, 'status' => 'COMPLETED']);
            foreach ($result->trades as $trade) {
                BacktestTradeRecord::query()->updateOrCreate(['backtest_run_id' => $runId, 'identity_key' => $trade['identity_key']], ['id' => Uuid::uuid5(Uuid::NAMESPACE_URL, $runId.'|'.$trade['identity_key'])->toString(), 'backtest_segment_id' => $id] + $trade);
            }
            foreach ($result->equity as $snapshot) {
                BacktestEquitySnapshotRecord::query()->updateOrCreate(['backtest_segment_id' => $id, 'recorded_at' => $snapshot['recorded_at']], ['backtest_run_id' => $runId] + $snapshot);
            }
        });
    }

    private function saveOptimizations(string $runId, int $window, array $optimized): void
    {
        foreach ($optimized['results'] as $row) {
            BacktestOptimizationRecord::query()->updateOrCreate(['backtest_run_id' => $runId, 'window_index' => $window, 'combination_index' => $row['combination_index']], [
                'parameters' => $row['parameters'], 'train_metrics' => $row['train_metrics'], 'validation_metrics' => $row['validation_metrics'], 'objective_score' => $row['objective_score'], 'selected' => $row['combination_index'] === $optimized['selected']['combination_index'],
            ]);
        }
    }

    private function stress(array $symbols, Segment $test, array $strategy, array $paper): array
    {
        $results = [];
        foreach (['fee' => $this->config['stress']['fee_multipliers'], 'slippage' => $this->config['stress']['slippage_multipliers']] as $kind => $multipliers) {
            foreach ($multipliers as $multiplier) {
                $variant = $paper;
                $path = $kind === 'fee' ? ['fees', 'taker_rate'] : ['slippage_rate'];
                if ($kind === 'fee') {
                    $variant['fees']['taker_rate'] = (string) BigDecimal::of($paper['fees']['taker_rate'])->multipliedBy($multiplier);
                } else {
                    $variant['slippage_rate'] = (string) BigDecimal::of($paper['slippage_rate'])->multipliedBy($multiplier);
                }
                $simulation = $this->engine->simulate($symbols, $test, $strategy, $variant, (string) $this->config['starting_balance'], $strategy['_exchange'] ?? 'bybit');
                $results["{$kind}_x{$multiplier}"] = $this->metrics->calculate($simulation->trades, $simulation->equity, (array) $this->config['returns']);
            }
        }
        foreach ($symbols as $symbol) {
            $simulation = $this->engine->simulate([$symbol], $test, $strategy, $paper, (string) $this->config['starting_balance'], $strategy['_exchange'] ?? 'bybit');
            $results['symbol_'.$symbol] = $this->metrics->calculate($simulation->trades, $simulation->equity, (array) $this->config['returns']);
        }
        $baseline = $this->engine->simulate($symbols, $test, $strategy, $paper, (string) $this->config['starting_balance'], $strategy['_exchange'] ?? 'bybit');
        $clean = array_values(array_filter($baseline->trades, fn (array $trade): bool => ! $trade['ambiguous_execution']));
        $results['exclude_ambiguous'] = $this->metrics->calculate($clean, $baseline->equity, (array) $this->config['returns']);
        $results['market_regimes'] = $this->regimeMetrics($baseline->trades, $strategy['_exchange'] ?? 'bybit');

        return $results;
    }

    private function regimeMetrics(array $trades, string $exchange): array
    {
        $groups = ['bullish' => [], 'sideways' => [], 'bearish' => []];
        foreach ($trades as $trade) {
            $at = $trade['signal_at'];
            $latest = CandleRecord::query()->where(['exchange' => $exchange, 'category' => 'linear', 'symbol' => $trade['symbol'], 'interval' => '60', 'is_closed' => true])
                ->where('open_time', '<=', $at->modify('-1 hour'))->latest('open_time')->first();
            $earlier = CandleRecord::query()->where(['exchange' => $exchange, 'category' => 'linear', 'symbol' => $trade['symbol'], 'interval' => '60', 'is_closed' => true])
                ->where('open_time', '<=', $at->modify('-'.(int) $this->config['regimes']['lookback_days'].' days'))->latest('open_time')->first();
            $regime = 'sideways';
            if ($latest !== null && $earlier !== null && ! BigDecimal::of($earlier->close)->isZero()) {
                $return = BigDecimal::of($latest->close)->minus($earlier->close)->dividedBy($earlier->close, 24, RoundingMode::HalfEven);
                $threshold = (string) $this->config['regimes']['trend_return_threshold'];
                $regime = $return->isGreaterThan($threshold) ? 'bullish' : ($return->isLessThan(BigDecimal::of($threshold)->negated()) ? 'bearish' : 'sideways');
            }
            $groups[$regime][] = $trade;
        }
        foreach ($groups as $regime => $items) {
            $groups[$regime] = $this->metrics->calculate($items, [], (array) $this->config['returns']);
        }

        return $groups;
    }

    private function segmentTrades(string $runId, string $kind): array
    {
        return BacktestTradeRecord::query()->where('backtest_run_id', $runId)->whereIn('backtest_segment_id', BacktestSegmentRecord::query()->select('id')->where('backtest_run_id', $runId)->where('kind', $kind))->get()->map(fn ($t): array => $t->toArray())->all();
    }

    private function segmentEquity(string $runId, string $kind): array
    {
        return BacktestEquitySnapshotRecord::query()->where('backtest_run_id', $runId)->whereIn('backtest_segment_id', BacktestSegmentRecord::query()->select('id')->where('backtest_run_id', $runId)->where('kind', $kind))->orderBy('recorded_at')->get()->map(fn ($s): array => $s->toArray())->all();
    }

    private function clearIncomplete(string $runId): void
    {
        DB::transaction(function () use ($runId): void {
            BacktestReportRecord::query()->where('backtest_run_id', $runId)->delete();
            BacktestOptimizationRecord::query()->where('backtest_run_id', $runId)->delete();
            BacktestSegmentRecord::query()->where('backtest_run_id', $runId)->delete();
        });
    }

    private function fingerprint(array $value): string
    {
        $sort = function (&$item) use (&$sort): void {
            if (! is_array($item)) {
                return;
            } foreach ($item as &$nested) {
                $sort($nested);
            } unset($nested);
            if (! array_is_list($item)) {
                ksort($item);
            }
        };
        $sort($value);

        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    public function requestCancellation(string $runId): bool
    {
        return BacktestRunRecord::query()->where('id', $runId)->whereIn('status', ['RUNNING', 'QUEUED'])->update(['status' => 'CANCEL_REQUESTED']) === 1;
    }

    private function guardCancellation(BacktestRunRecord $run): void
    {
        if ($run->fresh()->status === 'CANCEL_REQUESTED') {
            throw new BacktestCancelled('Backtest cancellation requested.');
        }
    }
}
