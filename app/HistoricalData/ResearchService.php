<?php

declare(strict_types=1);

namespace App\HistoricalData;

use App\Strategy\Application\StrategyRegistry;
use App\StrategyBuilder\DefinitionValidator;
use App\StrategyBuilder\EvaluationProfile;
use App\StrategyBuilder\ExecutableStore;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final readonly class ResearchService
{
    public function __construct(private AcquisitionDataset $datasets, private CoverageValidator $coverage, private StrategyRegistry $registry, private ExecutableStore $engines, private DefinitionValidator $definitions, private SourcePolicy $policy, private BatchRepository $batches) {}

    public function catalog(): array
    {
        $items = array_map(fn ($s) => ['strategy_id' => $s->id(), 'version' => $s->version(), 'definition_id' => null, 'kind' => 'REGISTERED', 'parameters' => $s->parameters()], $this->registry->all());
        foreach (DB::table('strategy_definitions')->whereNotIn('status', ['ARCHIVED', 'REJECTED'])->orderBy('strategy_id')->orderByDesc('version')->limit(500)->get() as $row) {
            $items[] = ['strategy_id' => $row->strategy_id, 'version' => (string) $row->version, 'definition_id' => $row->id, 'kind' => 'DSL', 'parameters' => json_decode($row->definition, true)];
        }

        return array_values($items);
    }

    public function create(string $batch, array $input): object
    {
        $data = Validator::make($input, [
            'strategy_id' => 'required|string|max:64', 'definition_id' => 'nullable|integer|min:1', 'symbol' => 'required|string|max:24',
            'from' => 'required|date', 'to' => 'required|date|after:from', 'capital' => 'required|string|regex:/^[0-9]{1,9}(\.[0-9]{1,8})?$/D',
            'fee' => 'required|string|regex:/^0(?:\.[0-9]{1,8})?$/D', 'slippage' => 'required|string|regex:/^0(?:\.[0-9]{1,8})?$/D', 'risk_percent' => 'required|string|regex:/^0(?:\.[0-9]{1,4})?$/D',
            'funding' => 'required|in:EXACT,DISABLED', 'intended_use' => 'required|in:NONCOMMERCIAL_RESEARCH,NONCOMMERCIAL_LEARNING',
        ])->validate();
        abort_unless(BigDecimal::of($data['capital'])->isPositive() && BigDecimal::of($data['risk_percent'])->isPositive() && BigDecimal::of($data['risk_percent'])->isLessThanOrEqualTo('0.5') && BigDecimal::of($data['fee'])->isLessThan('0.1') && BigDecimal::of($data['slippage'])->isLessThan('0.1'), 422, 'Invalid bounded execution assumptions.');
        $from = CarbonImmutable::parse($data['from'])->utc();
        $to = CarbonImmutable::parse($data['to'])->utc();
        abort_unless($from->timestamp % 900 === 0 && $to->timestamp % 900 === 0, 422, 'Research boundaries must align to closed 15-minute decisions.');
        $symbols = [$data['symbol']];
        $definition = null;
        if (isset($data['definition_id'])) {
            $row = DB::table('strategy_definitions')->find($data['definition_id']);
            abort_if($row === null, 404);
            abort_unless($row->strategy_id === $data['strategy_id'] && ! in_array($row->status, ['REJECTED', 'ARCHIVED'], true), 422, 'Immutable definition identity/lifecycle mismatch.');
            $definition = $this->definitions->validate(json_decode($row->definition, true));
            abort_unless(in_array('binance', $definition['exchanges'], true) && in_array($data['symbol'], $definition['symbols'], true), 422, 'Definition scope excludes Binance or instrument.');
            abort_unless(BigDecimal::of((string) $definition['risk_percent'])->isLessThanOrEqualTo($data['risk_percent']), 422, 'Execution risk ceiling is below the immutable definition risk.');
            $version = (string) $row->version;
            $parameters = $definition;
            $warmup = EvaluationProfile::window($definition) + 1;
            $holding = (int) ceil($definition['timeout_bars'] * (int) $definition['timeframe'] / 5);
            abort_unless(($definition['funding']['enabled'] ?? false) === ($data['funding'] === 'EXACT'), 422, 'Funding assumption must match the immutable strategy definition.');
        } else {
            $strategy = $this->registry->get($data['strategy_id']);
            abort_if(str_starts_with($strategy->id(), 'user_'), 422, 'Select the immutable DSL definition version.');
            $parameters = $strategy->parameters();
            $version = $strategy->version();
            $warmup = max(220, (int) config('strategy.history_limit', 220));
            $holding = (int) ceil(($parameters['maximum_holding_minutes'] ?? 240) / 5);
            if ($strategy->id() === 'btc_eth_lead_lag') {
                $symbols = ['BTCUSDT', 'ETHUSDT'];
            }
        }
        if ($data['funding'] === 'EXACT') {
            foreach ($symbols as $symbol) {
                $funding = $this->coverage->fundingCoverage($batch, $symbol, $from, $to);
                abort_unless($funding['complete'], 422, 'Complete settlement events and exact historical funding marks are required.');
            }
        }
        $scope = ['exchange' => 'binance', 'symbols' => $symbols, 'from' => $from->toISOString(), 'to' => $to->toISOString(), 'intended_use' => $data['intended_use']];
        $pin = $this->datasets->pin($batch, $scope, $warmup);
        $engine = $this->engines->capture((string) config('historical_data.replay_binary'));
        $config = ['scope' => $scope, 'symbol' => $data['symbol'], 'strategy_parameters' => $parameters, 'definition' => $definition, 'definition_checksum' => StrategyRegistry::hash($parameters), 'execution' => $data, 'warmup' => $warmup, 'holding_bars' => $holding, 'entry_model' => 'NEXT_CLOSED_BAR_MARKET_OPEN', 'intrabar_order' => 'CONSERVATIVE_STOP_FIRST', 'signal_authority' => $definition === null ? 'EXISTING_VERSIONED_PHP_STRATEGY' : 'GO_DSL', 'research_only' => true];
        $id = StrategyRegistry::hash([$pin->id, $config, $engine]);
        DB::transaction(function () use ($id, $batch, $pin, $data, $version, $config, $engine): void {
            $inserted = DB::table('historical_research_runs')->insertOrIgnore(['id' => $id, 'batch_id' => $batch, 'dataset_pin' => $pin->id, 'strategy_id' => $data['strategy_id'], 'strategy_version' => $version, 'configuration_hash' => StrategyRegistry::hash($config), 'engine_checksum' => $engine, 'config' => json_encode($config, JSON_THROW_ON_ERROR), 'status' => 'QUEUED', 'next_decision_at' => $config['scope']['from'], 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            if ($inserted) {
                $this->batches->audit($batch, 'HISTORICAL_REPLAY_QUEUED', ['run_id' => $id, 'dataset_pin' => $pin->id, 'configuration_hash' => StrategyRegistry::hash($config), 'paper_account_mutation' => false]);
            }
        }, 3);

        return DB::table('historical_research_runs')->find($id);
    }

    public function cancel(string $id): object
    {
        return DB::transaction(function () use ($id): object {
            $row = DB::table('historical_research_runs')->where('id', $id)->lockForUpdate()->first();
            abort_if($row === null, 404);
            if (! in_array($row->status, ['COMPLETED', 'CANCELLED'], true)) {
                DB::table('historical_research_runs')->where('id', $id)->update(['status' => 'CANCELLED', 'lease_owner' => null, 'lease_until' => null, 'updated_at' => now('UTC')]);
                $this->batches->audit($row->batch_id, 'HISTORICAL_REPLAY_CANCELLED', ['run_id' => $id]);
            }

            return DB::table('historical_research_runs')->find($id);
        }, 3);
    }
}
