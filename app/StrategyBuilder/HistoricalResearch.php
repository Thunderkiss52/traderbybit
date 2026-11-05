<?php

declare(strict_types=1);

namespace App\StrategyBuilder;

use App\Backtest\Application\DatasetInspector;
use App\MarketData\Application\MarketCandleConflictService;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Infrastructure\Persistence\MarketFundingEventRecord;
use App\Strategy\Application\StrategyRegistry;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class HistoricalResearch
{
    public function __construct(private DatasetInspector $datasets, private DefinitionValidator $validator, private MarketCandleConflictService $integrity, private GoEngine $engine, private ExecutableStore $executables) {}

    public function run(int $id, array $scope): object
    {
        if (DB::transactionLevel() > 0) {
            return $this->runSnapshot($id, $scope);
        }

        return DB::transaction(function () use ($id, $scope): object {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

            return $this->runSnapshot($id, $scope);
        }, 3);
    }

    private function runSnapshot(int $id, array $scope): object
    {
        $row = DB::table('strategy_definitions')->find($id);
        abort_if($row === null, 404);
        $d = $this->validator->validate(json_decode($row->definition, true, flags: JSON_THROW_ON_ERROR));
        $from = new DateTimeImmutable($scope['from']);
        $to = new DateTimeImmutable($scope['to']);
        if (! in_array($scope['exchange'], $d['exchanges'], true) || ! in_array($scope['symbol'], $d['symbols'], true) || $from >= $to || $to->getTimestamp() - $from->getTimestamp() > (int) config('strategy_builder.max_days') * 86400 || $to > new DateTimeImmutable('now') || ! BigDecimal::of($scope['capital'])->isPositive()) {
            throw ValidationException::withMessages(['scope' => 'Invalid research scope or range exceeds seven-day resource bound.']);
        }
        $binary = $this->executables->resolve($row->engine_checksum ?? null);
        if (! is_executable($binary)) {
            throw ValidationException::withMessages(['engine' => 'Go DSL binary unavailable; build research-go/cmd/strategy-dsl.']);
        }
        $cost = DB::table('exchanges')->where('id', $scope['exchange'])->value('cost_model');
        $cost = is_string($cost) ? json_decode($cost, true) : $cost;
        if ($scope['exchange'] === 'bybit') {
            $cost = ['taker_rate' => config('paper.fees.taker_rate'), 'slippage_rate' => config('paper.slippage_rate')];
        }
        if ($scope['exchange'] === 'binance') {
            $cost = ['taker_rate' => config('exchanges.binance.paper_taker_rate'), 'slippage_rate' => config('exchanges.binance.paper_slippage_rate')];
        }
        foreach (['taker_rate', 'slippage_rate'] as $key) {
            $value = $cost[$key] ?? null;
            if (! is_string($value) || ! preg_match('/^(?:0|[1-9][0-9]*)(?:\\.[0-9]+)?$/D', $value) || BigDecimal::of($value)->isGreaterThanOrEqualTo(1)) {
                throw ValidationException::withMessages(['cost_model' => 'Explicit exchange-specific costs required.']);
            }
        }
        $warmup = EvaluationProfile::window($d) + ($d['schema_version'] === 2 ? 1 : 0);
        try {
            $this->assertIntegrity($scope, $from, $to, $warmup);
            $pin = $this->datasets->pin([$scope['symbol']], $from, $to, $warmup, $scope['exchange']);
            if (isset($scope['dataset_pin_id']) && $scope['dataset_pin_id'] !== $pin['id']) {
                throw new \RuntimeException('Selected dataset pin does not match this exchange, instrument, range and required warmup.');
            }
            foreach ($pin['warnings'] as $warning) {
                if (str_starts_with($warning, 'insufficient_warmup:')) {
                    throw new \RuntimeException('Complete indicator warmup is required: '.$warning);
                }
            }
            $pin = ['id' => $pin['id'], 'checksum' => $pin['checksum']];
            $series = [];
            foreach (['5', '15', '30', '60'] as $tf) {
                $seconds = (int) $tf * 60;
                $start = (new DateTimeImmutable('@'.(intdiv($from->getTimestamp(), $seconds) * $seconds)))->modify('-'.$warmup * $seconds.' seconds');
                $series[$tf] = CandleRecord::query()->where(['exchange' => $scope['exchange'], 'category' => 'linear', 'symbol' => $scope['symbol'], 'interval' => $tf, 'is_closed' => true])->where('open_time', '>=', $start)->where('open_time', '<=', $to->modify('-'.$seconds.' seconds'))->orderBy('open_time')->get()->map(fn ($c) => ['exchange' => $c->exchange, 'category' => $c->category, 'symbol' => $c->symbol, 'interval' => (string) $c->interval, 'open_time' => $c->open_time->toIso8601String(), 'open' => $c->open, 'high' => $c->high, 'low' => $c->low, 'close' => $c->close, 'volume' => $c->volume, 'turnover' => $c->turnover, 'is_closed' => true])->all();
            }
            $input = ['definition' => $d, 'exchange' => $scope['exchange'], 'symbol' => $scope['symbol'], 'from' => $from->format(DATE_ATOM), 'as_of' => $to->format(DATE_ATOM), 'series' => $series, 'capital' => $scope['capital'], 'fee' => $cost['taker_rate'], 'slippage' => $cost['slippage_rate'], 'seed' => (int) ($scope['seed'] ?? 0)];
            $fundingEnabled = ($d['funding']['enabled'] ?? false) === true;
            if ($fundingEnabled) {
                $input['funding'] = MarketFundingEventRecord::query()->where(['exchange' => $scope['exchange'], 'category' => 'linear', 'symbol' => $scope['symbol']])->where('funding_at', '>', $from)->where('funding_at', '<', $to)->orderBy('funding_at')->get()->map(fn ($event) => ['at' => $event->funding_at->toIso8601String(), 'rate' => $event->rate, 'mark' => $event->mark_price])->all();
            }
            $metadata = ['definition_checksum' => $row->checksum, 'dataset_pin' => $pin, 'scope' => $scope, 'costs' => $cost, 'engine_sha256' => hash_file('sha256', $binary), 'input_checksum' => StrategyRegistry::hash($input), 'funding_complete' => $fundingEnabled];
            $fingerprint = StrategyRegistry::hash($metadata);
            $existing = DB::table('strategy_definition_runs')->find($fingerprint);
            if ($existing !== null) {
                $this->datasets->verifyPin($pin['id']);
                $this->assertIntegrity($scope, $from, $to, $warmup);

                return $existing;
            }
            $result = $this->engine->run($input, $row->engine_checksum ?? null);
            $this->datasets->verifyPin($pin['id']);
            $this->assertIntegrity($scope, $from, $to, $warmup);
            DB::transaction(function () use ($fingerprint, $id, $metadata, $result): void {
                DB::table('strategy_definition_runs')->insertOrIgnore(['id' => $fingerprint, 'definition_id' => $id, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now('UTC')]);
            }, 3);

            return DB::table('strategy_definition_runs')->find($fingerprint);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['dataset' => $e->getMessage()]);
        }
    }

    private function assertIntegrity(array $scope, DateTimeImmutable $from, DateTimeImmutable $to, int $warmup): void
    {
        foreach (['5', '15', '30', '60'] as $tf) {
            $start = $from->modify('-'.(($warmup + 1) * (int) $tf).' minutes');
            $report = $this->integrity->integrityReport($scope['exchange'], $scope['symbol'], $tf, $start, $to);
            if ($report['status'] !== 'VALID') {
                throw new \RuntimeException('Candle integrity blocks research: '.($report['reason_code'] ?? $report['status']));
            }
        }
    }
}
