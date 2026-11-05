<?php

declare(strict_types=1);

namespace App\HistoricalData;

use App\Strategy\Application\StrategyInputFactory;
use App\Strategy\Application\StrategyRegistry;
use App\StrategyBuilder\ExecutableStore;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Process\Process;

final readonly class ResearchWorker
{
    public function __construct(private AcquisitionDataset $datasets, private CoverageValidator $coverage, private StrategyRegistry $registry, private StrategyInputFactory $inputs, private ExecutableStore $engines, private StagingStorage $storage, private BatchRepository $batches) {}

    public function once(string $id): bool
    {
        $run = DB::transaction(function () use ($id) {
            DB::select('SELECT pg_advisory_xact_lock(20261105,20001)');
            $r = DB::table('historical_research_runs')->where('id', $id)->lockForUpdate()->first();
            if ($r === null || in_array($r->status, ['COMPLETED', 'CANCELLED', 'FAILED'], true) || ($r->lease_owner !== null && CarbonImmutable::parse($r->lease_until)->isFuture())) {
                return null;
            }
            if (DB::table('historical_download_entries')->whereNotNull('lease_owner')->where('lease_until', '>', now('UTC'))->exists() || DB::table('historical_research_runs')->whereNotNull('lease_owner')->where('lease_until', '>', now('UTC'))->exists()) {
                return null;
            }
            if ($r->attempts >= 3) {
                DB::table('historical_research_runs')->where('id', $id)->update(['status' => 'FAILED', 'error_code' => 'RESEARCH_WORKER_ATTEMPTS_EXHAUSTED', 'lease_owner' => null, 'lease_until' => null, 'updated_at' => now('UTC')]);

                return null;
            }
            $r->lease_owner = Uuid::uuid4()->toString();
            $r->fence++;
            DB::table('historical_research_runs')->where('id', $id)->update(['status' => 'RUNNING', 'lease_owner' => $r->lease_owner, 'fence' => $r->fence, 'lease_until' => now('UTC')->addSeconds(300), 'attempts' => $r->attempts + 1, 'updated_at' => now('UTC')]);

            return $r;
        }, 3);
        if ($run === null) {
            return false;
        }$directory = null;
        try {
            $this->datasets->verify($run->dataset_pin);
            $config = json_decode($run->config, true);
            $from = CarbonImmutable::parse($config['scope']['from']);
            $to = CarbonImmutable::parse($config['scope']['to']);
            if ($config['definition'] === null && CarbonImmutable::parse($run->next_decision_at) < $to) {
                $this->snapshot(fn () => $this->signals($run, $config, $from, $to));
                $this->release($run);

                return true;
            }
            $entryId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'history-replay:'.$id)->toString();
            $this->storage->clearExpiredFor($entryId, $run->lease_owner);
            $directory = $this->storage->directory($entryId, $run->lease_owner);
            $this->storage->admit();
            $request = $this->snapshot(fn () => $this->inputsToFiles($run, $config, $directory, $from, $to));
            $binary = $this->engines->resolve($run->engine_checksum);
            $process = new Process([$binary], null, ['GOMAXPROCS' => '1', 'GOMEMLIMIT' => '192MiB']);
            $process->setInput(json_encode($request, JSON_THROW_ON_ERROR));
            $process->setTimeout(1500);
            $process->start();
            try {
                while ($process->isRunning()) {
                    $this->guard($run);
                    $this->heartbeat($run);
                    $process->checkTimeout();
                    usleep(250000);
                }if (! $process->isSuccessful()) {
                    $diagnostic = json_decode(trim($process->getOutput()), true);
                    $reason = (string) ($diagnostic['error'] ?? '');
                    throw new AcquisitionFailure(str_contains($reason, 'finer execution clock') ? 'FUNDING_EXECUTION_CLOCK_UNSUPPORTED' : (str_contains($reason, 'resource bound') ? 'RESEARCH_RESOURCE_BOUND_EXCEEDED' : 'GO_HISTORICAL_REPLAY_FAILED'));
                }
            } finally {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            $this->datasets->verify($run->dataset_pin);
            $this->persistOutput($run, $config, $directory);
            $this->datasets->verify($run->dataset_pin);
            DB::transaction(function () use ($run, $directory): void {
                $this->guard($run, true);
                $result = json_decode(file_get_contents($directory.'/result.json'), true, 64, JSON_THROW_ON_ERROR);
                $result['signal_skip_counts'] = json_decode(DB::table('historical_research_runs')->where('id', $run->id)->value('skip_counts') ?? '{}', true);
                $result['funding_fidelity'] = json_decode($run->config, true)['execution']['funding'];
                $result['dataset_pin'] = $run->dataset_pin;
                $result['configuration_hash'] = $run->configuration_hash;
                $result['engine_checksum'] = $run->engine_checksum;
                DB::table('historical_research_runs')->where('id', $run->id)->update(['status' => 'COMPLETED', 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'lease_owner' => null, 'lease_until' => null, 'updated_at' => now('UTC')]);
                $this->batches->audit($run->batch_id, 'HISTORICAL_REPLAY_COMPLETED', ['run_id' => $run->id, 'simulated_trades' => $result['report']['total_trades'], 'paper_journal_written' => false]);
            }, 3);
        } catch (\Throwable $e) {
            $code = $e instanceof AcquisitionFailure ? $e->reason : 'HISTORICAL_RESEARCH_FAILED';
            DB::table('historical_research_runs')->where('id', $run->id)->where('lease_owner', $run->lease_owner)->where('fence', $run->fence)->where('status', '<>', 'CANCELLED')->update(['status' => 'FAILED', 'error_code' => $code, 'lease_owner' => null, 'lease_until' => null, 'updated_at' => now('UTC')]);
            $this->batches->audit($run->batch_id, 'HISTORICAL_REPLAY_FAILED', ['run_id' => $run->id, 'error_code' => $code]);
            Log::warning('Historical research failed.', ['run_id' => $run->id, 'exception_type' => $e::class]);
        } finally {
            if ($directory !== null) {
                $this->storage->clear($directory);
            }
        }

        return true;
    }

    private function snapshot(callable $work): mixed
    {
        if (DB::transactionLevel() > 0) {
            return $work();
        }

        return DB::transaction(function () use ($work) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

            return $work();
        }, 3);
    }

    private function signals(object $run, array $config, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $strategy = $this->registry->make($run->strategy_id, $config['strategy_parameters']);
        abort_unless(StrategyRegistry::hash($strategy->parameters()) === $config['definition_checksum'], 409, 'Strategy configuration changed.');
        $at = CarbonImmutable::parse($run->next_decision_at);
        $counts = json_decode($run->skip_counts ?? '{}', true);
        $decisions = [];
        for ($n = 0; $n < 128 && $at < $to; $n++,$at = $at->addMinutes(15)) {
            $this->guard($run);
            $this->heartbeat($run);
            $peer = $run->strategy_id === 'btc_eth_lead_lag' ? StrategyInputFactory::peerSymbol($config['symbol']) : null;
            $decision = $strategy->evaluate($this->inputs->make('binance', 'linear', $config['symbol'], $at->toDateTimeImmutable(), $peer, $config['scope']['intended_use']));
            if ($decision->pricePlan === null || $decision->side->value === 'SKIP') {
                foreach ($decision->reasons as $reason) {
                    $counts[$reason] = ($counts[$reason] ?? 0) + 1;
                }

                continue;
            }
            $base = $from->subMinutes($config['warmup'] * 5)->timestamp;
            $bar = intdiv($at->timestamp - $base, 300) - 1;
            $risk = BigDecimal::min(BigDecimal::of($decision->modelRiskPercent), BigDecimal::of($config['execution']['risk_percent']))->dividedBy('100', 12, RoundingMode::HalfEven);
            $signal = ['side' => $decision->side->value, 'decision_time' => $at->toISOString(), 'decision_bar' => $bar, 'entry_bar' => $bar + 1, 'stop_loss' => $decision->pricePlan->stopLoss, 'take_profit' => $decision->pricePlan->takeProfit2, 'reason' => $run->strategy_id.'@'.$run->strategy_version, 'risk_rate' => (string) $risk];
            if (! BigDecimal::of($decision->pricePlan->takeProfit1)->isEqualTo($decision->pricePlan->takeProfit2)) {
                $signal['partial'] = ['Price' => $decision->pricePlan->takeProfit1, 'Fraction' => '0.5'];
            }
            $decisions[] = ['run_id' => $run->id, 'decision_at' => $at, 'signal' => json_encode($signal, JSON_THROW_ON_ERROR), 'features' => json_encode($decision->indicatorSnapshot, JSON_THROW_ON_ERROR), 'regime' => (string) ($decision->indicatorSnapshot['regime'] ?? 'UNCLASSIFIED')];
        }
        $this->datasets->verify($run->dataset_pin);
        DB::transaction(function () use ($run, $decisions, $at, $counts): void {
            $this->guard($run, true);
            if ($decisions !== []) {
                DB::table('historical_research_signals')->insertOrIgnore($decisions);
            }DB::table('historical_research_runs')->where('id', $run->id)->update(['next_decision_at' => $at, 'skip_counts' => json_encode($counts, JSON_THROW_ON_ERROR), 'updated_at' => now('UTC')]);
        }, 3);
    }

    private function inputsToFiles(object $run, array $config, string $directory, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $series = [];
        foreach (['5', '15', '30', '60'] as $tf) {
            $file = $directory.'/candles-'.$tf.'.ndjson';
            $out = fopen($file, 'wb');
            $seconds = (int) $tf * 60;
            $start = CarbonImmutable::createFromTimestamp(intdiv($from->timestamp, $seconds) * $seconds, 'UTC')->subSeconds($config['warmup'] * $seconds);
            $count = 0;
            try {
                foreach (DB::table('market_candles')->where(['exchange' => 'binance', 'category' => 'linear', 'symbol' => $config['symbol'], 'interval' => $tf, 'is_closed' => true])->where('open_time', '>=', $start)->where('open_time', '<=', $to->subSeconds($seconds))->orderBy('open_time')->cursor(['exchange', 'category', 'symbol', 'interval', 'open_time', 'open', 'high', 'low', 'close', 'volume', 'turnover', 'is_closed']) as $row) {
                    $record = (array) $row;
                    $record['open_time'] = CarbonImmutable::parse($row->open_time)->toISOString();
                    fwrite($out, json_encode($record, JSON_THROW_ON_ERROR)."\n");
                    if (++$count % 5000 === 0) {
                        $this->guard($run);
                        $this->heartbeat($run);
                    }abort_if($count > 500000, 422, 'Historical replay input exceeds explicit row resource bound.');
                }
            } finally {
                fclose($out);
            }$series[$tf] = $file;
        }
        $signals = $directory.'/input-signals.ndjson';
        $out = fopen($signals, 'wb');
        try {
            foreach (DB::table('historical_research_signals')->where('run_id', $run->id)->orderBy('decision_at')->cursor(['signal']) as $row) {
                fwrite($out, $row->signal."\n");
            }
        } finally {
            fclose($out);
        }
        $funding = $directory.'/funding.ndjson';
        $out = fopen($funding, 'wb');
        try {
            if ($config['execution']['funding'] === 'EXACT') {
                foreach ($this->coverage->fundingRows($run->batch_id, $config['symbol'], $from, $to) as $row) {
                    if (CarbonImmutable::parse($row['funding_at']) <= $from) {
                        continue;
                    }abort_if($row['mark'] === null, 422, 'Exact historical funding mark unavailable.');
                    fwrite($out, json_encode(['at' => CarbonImmutable::parse($row['funding_at'])->toISOString(), 'rate' => $row['rate'], 'mark' => $row['mark'], 'rate_exact' => $row['rate'], 'mark_exact' => $row['mark']], JSON_THROW_ON_ERROR)."\n");
                }
            }
        } finally {
            fclose($out);
        }

        return ['strategy_id' => $run->strategy_id, 'definition' => $config['definition'], 'series' => $series, 'signals_file' => $signals, 'funding_file' => $funding, 'output' => $directory, 'from' => $from->toISOString(), 'to' => $to->toISOString(), 'symbol' => $config['symbol'], 'capital' => $config['execution']['capital'], 'fee' => $config['execution']['fee'], 'slippage' => $config['execution']['slippage'], 'risk_rate' => (string) BigDecimal::of($config['execution']['risk_percent'])->dividedBy('100', 12, RoundingMode::HalfEven), 'max_holding_bars' => $config['holding_bars']];
    }

    private function persistOutput(object $run, array $config, string $directory): void
    {
        foreach (['signals', 'trades', 'equity', 'executions'] as $family) {
            $file = fopen($directory.'/'.$family.'.ndjson', 'rb');
            $sequence = 0;
            $chunk = [];
            try {
                while (($line = fgets($file, 1048577)) !== false) {
                    $row = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                    $sequence++;
                    if ($family === 'signals') {
                        $record = ['run_id' => $run->id, 'decision_at' => $row['decision_time'], 'signal' => json_encode($row, JSON_THROW_ON_ERROR), 'features' => json_encode($row['features'] ?? []), 'regime' => $config['definition'] !== null && isset($config['definition']['regime']) ? 'REGIME_FILTER_PASSED' : 'UNCLASSIFIED'];
                    } elseif ($family === 'trades') {
                        $record = ['run_id' => $run->id, 'trade_number' => $row['id'], 'entry_at' => $row['entry_time'], 'exit_at' => $row['exit_time'], 'trade' => json_encode($row, JSON_THROW_ON_ERROR), 'features' => '{}', 'regime' => 'UNCLASSIFIED'];
                    } elseif ($family === 'equity') {
                        if (CarbonImmutable::parse($row['time']) < CarbonImmutable::parse($config['scope']['from'])) {
                            continue;
                        }$record = ['run_id' => $run->id, 'at' => $row['time'], 'equity' => $row['equity'], 'drawdown_percent' => (string) $row['drawdown_percent']];
                    } else {
                        $record = ['run_id' => $run->id, 'sequence' => $sequence, 'execution' => json_encode($row, JSON_THROW_ON_ERROR)];
                    }
                    $chunk[] = $record;
                    if (count($chunk) >= 500) {
                        $this->writeOutputChunk($run, $family, $chunk);
                        $chunk = [];
                        $this->heartbeat($run);
                    }
                }if ($chunk !== []) {
                    $this->writeOutputChunk($run, $family, $chunk);
                }
            } finally {
                fclose($file);
            }
        }
    }

    private function writeOutputChunk(object $run, string $family, array $chunk): void
    {
        DB::transaction(function () use ($run, $family, $chunk): void {
            $this->guard($run, true);
            if ($family === 'trades') {
                $features = DB::table('historical_research_signals')->where('run_id', $run->id)->whereIn('decision_at', array_column($chunk, 'entry_at'))->get()->keyBy(fn ($r) => CarbonImmutable::parse($r->decision_at)->getTimestampMs());
                foreach ($chunk as &$record) {
                    $signal = $features->get(CarbonImmutable::parse($record['entry_at'])->getTimestampMs());
                    $record['features'] = $signal->features ?? '{}';
                    $record['regime'] = $signal->regime ?? 'UNCLASSIFIED';
                }unset($record);
            }
            DB::table('historical_research_'.$family)->insertOrIgnore($chunk);
        }, 3);
    }

    private function guard(object $run, bool $lock = false): void
    {
        $query = DB::table('historical_research_runs')->where('id', $run->id);
        if ($lock) {
            $query->lockForUpdate();
        }$row = $query->first();
        if ($row === null || $row->status !== 'RUNNING' || $row->lease_owner !== $run->lease_owner || $row->fence !== $run->fence || CarbonImmutable::parse($row->lease_until)->isPast()) {
            throw new AcquisitionFailure('RESEARCH_CANCELLED_OR_FENCED');
        }
    }

    private function heartbeat(object $run): void
    {
        DB::table('historical_research_runs')->where('id', $run->id)->where('lease_owner', $run->lease_owner)->where('fence', $run->fence)->where('status', 'RUNNING')->update(['lease_until' => now('UTC')->addSeconds(300), 'updated_at' => now('UTC')]);
    }

    private function release(object $run): void
    {
        DB::table('historical_research_runs')->where('id', $run->id)->where('lease_owner', $run->lease_owner)->where('fence', $run->fence)->update(['status' => 'QUEUED', 'attempts' => 0, 'lease_owner' => null, 'lease_until' => null, 'updated_at' => now('UTC')]);
    }
}
