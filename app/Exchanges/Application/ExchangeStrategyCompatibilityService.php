<?php

declare(strict_types=1);

namespace App\Exchanges\Application;

use App\Exchanges\ExchangeRegistry;
use App\Exchanges\Infrastructure\Persistence\ExchangeRecord;
use App\Exchanges\Infrastructure\Persistence\ExchangeStrategyCompatibilityRecord;
use App\HistoricalData\UniversalAcquisitionService;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\Strategy\Application\StrategyRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final readonly class ExchangeStrategyCompatibilityService
{
    public const EVALUATION_VERSION = 'v1.1';

    public const MINIMUM_WARMUP_BARS = 220;

    public function __construct(
        private ExchangeRegistry $exchangeRegistry,
        private StrategyRegistry $strategyRegistry
    ) {}

    /**
     * Reconcile compatibility for all registered exchanges against all registered strategies.
     *
     * @return array{
     *     exchanges_evaluated: int,
     *     strategies_evaluated: int,
     *     evaluations_count: int,
     *     statuses: array<string, int>
     * }
     */
    public function reconcileAll(bool $force = false): array
    {
        $exchanges = ExchangeRecord::query()->orderBy('id')->get();
        $strategies = $this->discoverStrategies();

        $evaluationsCount = 0;
        $statuses = [];

        foreach ($exchanges as $exchange) {
            foreach ($strategies as $strategy) {
                $record = $this->evaluateAndPersist($exchange, $strategy, $force);
                $evaluationsCount++;
                $statuses[$record->status] = ($statuses[$record->status] ?? 0) + 1;
            }
        }

        return [
            'exchanges_evaluated' => $exchanges->count(),
            'strategies_evaluated' => count($strategies),
            'evaluations_count' => $evaluationsCount,
            'statuses' => $statuses,
        ];
    }

    /**
     * Reconcile compatibility for a specific exchange against all strategies.
     */
    public function reconcileExchange(string $exchangeId, bool $force = false): array
    {
        $exchange = ExchangeRecord::query()->find(strtolower(trim($exchangeId)))
            ?? throw new \InvalidArgumentException("Exchange '{$exchangeId}' is not registered.");
        $strategies = $this->discoverStrategies();

        $results = [];
        foreach ($strategies as $strategy) {
            $results[] = $this->evaluateAndPersist($exchange, $strategy, $force);
        }

        return [
            'exchange_id' => $exchangeId,
            'strategies_evaluated' => count($strategies),
            'results' => array_map(fn ($r) => $this->formatRecord($r), $results),
        ];
    }

    /**
     * Reconcile compatibility for a specific strategy against all exchanges.
     */
    public function reconcileStrategy(string $strategyId, bool $force = false): array
    {
        $strategy = $this->findStrategy($strategyId);
        $exchanges = ExchangeRecord::query()->orderBy('id')->get();

        $results = [];
        foreach ($exchanges as $exchange) {
            $results[] = $this->evaluateAndPersist($exchange, $strategy, $force);
        }

        return [
            'strategy_id' => $strategyId,
            'exchanges_evaluated' => $exchanges->count(),
            'results' => array_map(fn ($r) => $this->formatRecord($r), $results),
        ];
    }

    /**
     * Retrieve the full compatibility matrix.
     *
     * @return array<string, mixed>
     */
    public function getMatrix(): array
    {
        $records = ExchangeStrategyCompatibilityRecord::query()
            ->orderBy('exchange_id')
            ->orderBy('strategy_id')
            ->get();

        $matrix = [];
        foreach ($records as $record) {
            $matrix[$record->exchange_id][$record->strategy_id] = $this->formatRecord($record);
        }

        return [
            'matrix' => $matrix,
            'total_evaluations' => $records->count(),
            'updated_at' => now('UTC')->format(DATE_ATOM),
        ];
    }

    /**
     * Evaluate and persist compatibility for a single exchange x strategy pair.
     */
    public function evaluateAndPersist(ExchangeRecord $exchange, array $strategy, bool $force = false): ExchangeStrategyCompatibilityRecord
    {
        $strategyId = (string) $strategy['id'];
        $strategyVersion = (string) ($strategy['version'] ?? '1.0.0');

        $evaluation = $this->evaluate($exchange, $strategy);

        return ExchangeStrategyCompatibilityRecord::query()->updateOrCreate(
            [
                'exchange_id' => $exchange->id,
                'strategy_id' => $strategyId,
                'strategy_version' => $strategyVersion,
            ],
            [
                'status' => $evaluation['status'],
                'research_eligible' => $evaluation['research_eligible'],
                'shadow_eligible' => $evaluation['shadow_eligible'],
                'paper_eligible' => $evaluation['paper_eligible'],
                'market_type_compatible' => $evaluation['checks']['market_type_compatible'],
                'instruments_compatible' => $evaluation['checks']['instruments_compatible'],
                'timeframes_compatible' => $evaluation['checks']['timeframes_compatible'],
                'history_compatible' => $evaluation['checks']['history_compatible'],
                'features_compatible' => $evaluation['checks']['features_compatible'],
                'execution_model_compatible' => $evaluation['checks']['execution_model_compatible'],
                'diagnostics' => [
                    'missing_requirements' => $evaluation['reasons_missing'],
                    'checks' => $evaluation['checks'],
                ],
                'capability_snapshot' => [
                    'exchange_capabilities' => $exchange->capabilities ?? [],
                    'strategy_requirements' => $strategy,
                ],
                'dataset_coverage' => $evaluation['coverage'],
                'evaluated_at' => now('UTC'),
                'evaluation_version' => self::EVALUATION_VERSION,
            ]
        );
    }

    /**
     * Pure evaluation of an exchange against a strategy.
     *
     * @return array{
     *     status: string,
     *     research_eligible: bool,
     *     shadow_eligible: bool,
     *     paper_eligible: bool,
     *     checks: array<string, bool>,
     *     reasons_missing: list<string>,
     *     coverage: array<string, mixed>
     * }
     */
    public function evaluate(ExchangeRecord $exchange, array $strategy): array
    {
        $capabilities = (array) ($exchange->capabilities ?? []);
        $costModel = (array) ($exchange->cost_model ?? []);

        // 1. Market type compatibility
        $strategyMarketType = (string) ($strategy['market_type'] ?? 'linear_perpetual');
        $supportedMarketTypes = (array) ($capabilities['market_types'] ?? [$exchange->market_type]);
        $marketTypeCompatible = ($exchange->market_type === $strategyMarketType)
            || in_array($strategyMarketType, $supportedMarketTypes, true)
            || ($strategyMarketType === 'linear_perpetual' && in_array('perpetual', $supportedMarketTypes, true))
            || ($strategyMarketType === 'perpetual' && $exchange->market_type === 'linear_perpetual');

        // 2. Instrument compatibility
        $requiredSymbols = (array) ($strategy['symbols'] ?? ['BTCUSDT']);
        $instrumentsCompatible = true;
        $missingSymbols = [];
        foreach ($requiredSymbols as $symbol) {
            $candleExists = CandleRecord::query()
                ->where('exchange', $exchange->id)
                ->where('symbol', $symbol)
                ->exists();
            if (! $candleExists) {
                // If no candle data yet, check if exchange is connected and supports standard linear pairs
                if ($exchange->lifecycle_status === 'OFFLINE_DATA_SOURCE' && ! $candleExists) {
                    $instrumentsCompatible = false;
                    $missingSymbols[] = $symbol;
                }
            }
        }

        // 3. Timeframe compatibility
        $requiredTimeframes = array_map(UniversalAcquisitionService::normalizeInterval(...), (array) ($strategy['timeframes'] ?? ['5', '15', '30', '60']));
        $supportedTimeframes = array_map(UniversalAcquisitionService::normalizeInterval(...), (array) ($capabilities['supported_timeframes'] ?? []));
        $missingTimeframes = array_diff($requiredTimeframes, $supportedTimeframes);
        $timeframesCompatible = empty($missingTimeframes);

        // 4. Historical coverage & minimum history derived from strategy indicators
        $minWarmupBars = $this->requiredWarmupBars($strategy);
        $coverageInfo = [];
        $totalBars = 0;
        $historyCompatible = true;
        $freshCandles = true;
        foreach ($requiredSymbols as $symbol) {
            foreach ($requiredTimeframes as $interval) {
                $seconds = ctype_digit((string) $interval) ? (int) $interval * 60 : 0;
                $times = $seconds > 0 ? CandleRecord::query()
                    ->where('exchange', $exchange->id)->where('symbol', $symbol)
                    ->where('category', $strategyMarketType === 'spot' ? 'spot' : 'linear')
                    ->where('interval', (string) $interval)->where('is_closed', true)
                    ->where('open_time', '<=', now('UTC')->subSeconds($seconds))
                    ->orderByDesc('open_time')->limit($minWarmupBars)->pluck('open_time') : collect();
                $bars = $times->count();
                $totalBars += $bars;
                $contiguous = $bars >= $minWarmupBars;
                $previous = null;
                foreach ($times as $time) {
                    $timestamp = CarbonImmutable::parse($time)->getTimestamp();
                    if ($previous !== null && $previous - $timestamp !== $seconds) {
                        $contiguous = false;
                    }
                    $previous = $timestamp;
                }
                $fresh = $bars > 0 && CarbonImmutable::parse($times->first())->addSeconds($seconds)->gte(now('UTC')->subSeconds(300));
                $freshCandles = $freshCandles && $fresh;
                $historyCompatible = $historyCompatible && $contiguous;
                $coverageInfo[$symbol][(string) $interval] = ['bars' => $bars, 'min_required' => $minWarmupBars, 'sufficient' => $contiguous, 'fresh' => $fresh];
            }
        }

        // 5. Feature calculation compatibility
        $requiresFunding = (bool) ($strategy['requires_funding'] ?? false);
        $requiresOpenInterest = (bool) ($strategy['requires_open_interest'] ?? false);

        $hasFunding = DB::table('market_funding_events')->where('exchange', $exchange->id)
            ->whereIn('symbol', $requiredSymbols)->where('funding_at', '<=', now('UTC'))->exists();
        if (! $hasFunding && Schema::hasTable('historical_funding_observations')) {
            $hasFunding = DB::table('historical_funding_observations')
                ->where('exchange', $exchange->id)
                ->whereIn('symbol', $requiredSymbols)->where('funding_at', '<=', now('UTC'))
                ->where('status', 'VERIFIED_OBSERVATION')
                ->exists();
        }

        $hasOpenInterest = DB::table('market_context_snapshots')->where('exchange', $exchange->id)
            ->whereIn('symbol', $requiredSymbols)->where('observed_at', '<=', now('UTC'))
            ->whereNotNull('open_interest')->exists();

        $featuresCompatible = true;
        $featureBlockers = [];
        if ($requiresFunding && ! $hasFunding) {
            $featuresCompatible = false;
            $featureBlockers[] = 'MISSING_FUNDING_DATA: Strategy requires funding rate observations, but exchange has no funding feed or historical events.';
        }
        if ($requiresOpenInterest && ! $hasOpenInterest) {
            $featuresCompatible = false;
            $featureBlockers[] = 'MISSING_OPEN_INTEREST: Strategy requires open interest metrics, not supported by this exchange.';
        }

        // 6. Execution model compatibility
        $validator = new ExchangeCapabilityValidator($this->exchangeRegistry);
        $hasTakerRate = $validator->validRate($costModel['taker_rate'] ?? null);
        $hasSlippage = $validator->validRate($costModel['slippage_rate'] ?? null);
        $executionModelCompatible = $hasTakerRate && $hasSlippage;

        // 7. Live adapter check
        $hasLiveAdapter = $this->exchangeRegistry->hasAdapter($exchange->id);
        $execution = $validator->evaluate($exchange);

        // 8. Candle conflicts check
        $unresolvedConflicts = 0;
        if (Schema::hasTable('market_candle_conflicts')) {
            $unresolvedConflicts = DB::table('market_candle_conflicts')
                ->where('exchange', $exchange->id)
                ->where('status', 'UNRESOLVED')
                ->count();
        }
        $zeroConflicts = $unresolvedConflicts === 0;

        // 9. Precision specifications check
        $hasPrecision = isset($capabilities['price_precision']) && isset($capabilities['qty_precision']);

        // Collect reasons missing
        $reasonsMissing = [];
        if (! $marketTypeCompatible) {
            $reasonsMissing[] = "INCOMPATIBLE_MARKET_TYPE: Strategy expects market type '{$strategyMarketType}', but exchange provides '{$exchange->market_type}'.";
        }
        if (! empty($missingSymbols)) {
            $reasonsMissing[] = 'MISSING_REQUIRED_SYMBOLS: Missing data for symbols: '.implode(', ', $missingSymbols).'.';
        }
        if (! empty($missingTimeframes)) {
            $reasonsMissing[] = 'MISSING_REQUIRED_TIMEFRAMES: Missing required timeframes: '.implode(', ', $missingTimeframes).'.';
        }
        if (! $historyCompatible) {
            $reasonsMissing[] = "INSUFFICIENT_HISTORICAL_DATA: Historical candle count ({$totalBars} bars) does not meet minimum warmup threshold ({$minWarmupBars} bars).";
        }
        foreach ($featureBlockers as $blocker) {
            $reasonsMissing[] = $blocker;
        }
        if (! $executionModelCompatible) {
            $reasonsMissing[] = 'MISSING_EXECUTION_COST_MODEL: Missing valid taker fee and slippage rate configuration.';
        }
        if (! $hasLiveAdapter) {
            $reasonsMissing[] = 'MISSING_LIVE_ADAPTER: Dedicated REST/WebSocket adapter class is not registered.';
        }
        if (! $zeroConflicts) {
            $reasonsMissing[] = "UNRESOLVED_CANDLE_CONFLICTS: {$unresolvedConflicts} conflicting candle observations present.";
        }
        if (! $hasPrecision) {
            $reasonsMissing[] = 'MISSING_PRECISION_SPECS: Price tick size and quantity step precision rules must be declared.';
        }
        if (! $freshCandles) {
            $reasonsMissing[] = 'STALE_REQUIRED_CANDLES: Fresh closed candles are required on every strategy timeframe for Shadow and PAPER.';
        }
        if (! empty($strategy['requires_streaming']) || ! empty($strategy['requires_order_book'])) {
            $reasonsMissing[] = 'UNVERIFIED_STREAMING_INPUTS: Streaming and order-book strategies require a verified feed and strategy-specific latency evidence.';
        }
        $reasonsMissing = array_values(array_unique(array_merge($reasonsMissing, $execution['reasons_missing'])));

        // Eligibility determination:
        // Research: Historical data exists, intervals covered, features calculable, execution model defined.
        // OFFLINE HISTORICAL EXCHANGES ARE RESEARCH ELIGIBLE!
        $researchEligible = $marketTypeCompatible
            && $instrumentsCompatible
            && $timeframesCompatible
            && $historyCompatible
            && $executionModelCompatible
            && $featuresCompatible;

        // Shadow: Public market data / adapter or live data available, features calculable, signals evaluable.
        $shadowEligible = $researchEligible
            && $hasLiveAdapter
            && $freshCandles
            && empty($strategy['requires_streaming'])
            && empty($strategy['requires_order_book'])
            && in_array($exchange->lifecycle_status, ['CONNECTED_PUBLIC_DATA', 'RESEARCH_ENABLED', 'PAPER_ELIGIBLE', 'ACTIVE_PAPER'], true);

        // PAPER: Full technical eligibility including live adapter, cost model, 0 conflicts, precision, and exchange approved.
        $paperEligible = $shadowEligible
            && $execution['eligible']
            && $zeroConflicts
            && $hasPrecision
            && in_array($exchange->lifecycle_status, ['PAPER_ELIGIBLE', 'ACTIVE_PAPER'], true);

        // Overall compatibility status:
        if ($paperEligible) {
            $status = 'PAPER_ELIGIBLE';
        } elseif ($shadowEligible) {
            $status = 'SHADOW_ELIGIBLE';
        } elseif ($researchEligible) {
            $status = 'RESEARCH_ELIGIBLE';
        } elseif (! $historyCompatible) {
            $status = 'INSUFFICIENT_DATA';
        } elseif (! $marketTypeCompatible || ! $instrumentsCompatible || ! $timeframesCompatible) {
            $status = 'INCOMPATIBLE';
        } else {
            $status = 'BLOCKED';
        }

        $checks = [
            'market_type_compatible' => $marketTypeCompatible,
            'instruments_compatible' => $instrumentsCompatible,
            'timeframes_compatible' => $timeframesCompatible,
            'history_compatible' => $historyCompatible,
            'features_compatible' => $featuresCompatible,
            'execution_model_compatible' => $executionModelCompatible,
            'has_live_adapter' => $hasLiveAdapter,
            'zero_candle_conflicts' => $zeroConflicts,
            'has_precision_specs' => $hasPrecision,
            'fresh_required_candles' => $freshCandles,
            'integrated_execution_model' => $execution['checks']['has_execution_model'],
        ];

        return [
            'status' => $status,
            'research_eligible' => $researchEligible,
            'shadow_eligible' => $shadowEligible,
            'paper_eligible' => $paperEligible,
            'checks' => $checks,
            'reasons_missing' => $reasonsMissing,
            'coverage' => [
                'total_bars' => $totalBars,
                'symbols' => $coverageInfo,
            ],
        ];
    }

    /**
     * Discover all registered strategies (compiled primary + user custom definitions).
     *
     * @return list<array<string, mixed>>
     */
    public function discoverStrategies(): array
    {
        $list = [];

        // Portfolio identities come from the executable registry, never the method library.
        foreach ($this->strategyRegistry->all() as $registered) {
            $id = $registered->id();
            $list[] = [
                'id' => $id,
                'name' => $registered->description(),
                'version' => $registered->version(),
                'kind' => 'REGISTERED_PRIMARY',
                'market_type' => 'linear_perpetual',
                'symbols' => $id === 'btc_eth_lead_lag' ? ['BTCUSDT', 'ETHUSDT'] : ['BTCUSDT'],
                'timeframes' => ['5', '15', '30', '60'],
                'requires_funding' => $id === 'funding_open_interest_squeeze',
                'requires_open_interest' => $id === 'funding_open_interest_squeeze',
            ];
        }

        // 2. Custom DSL Strategy definitions from DB
        if (Schema::hasTable('strategy_definitions')) {
            try {
                $definitions = DB::table('strategy_definitions')
                    ->whereNotIn('status', ['ARCHIVED'])
                    ->get();

                foreach ($definitions as $def) {
                    $json = json_decode((string) $def->definition, true);
                    if (! is_array($json)) {
                        continue;
                    }
                    if (in_array((string) $def->strategy_id, array_column($list, 'id'), true)) {
                        continue;
                    }
                    $list[] = [
                        'id' => (string) ($def->strategy_id ?? $json['id'] ?? 'user_custom_'.$def->id),
                        'name' => (string) ($json['id'] ?? 'Custom Strategy #'.$def->id),
                        'version' => (string) ($def->version ?? $json['version'] ?? '1'),
                        'kind' => 'USER_DSL',
                        'market_type' => 'linear_perpetual',
                        'symbols' => (array) ($json['symbols'] ?? ['BTCUSDT']),
                        'timeframes' => (array) (isset($json['timeframe']) ? [$json['timeframe']] : ['15', '60']),
                        'requires_funding' => ! empty($json['funding']['enabled']),
                        'requires_open_interest' => false,
                        'definition_id' => $def->id,
                    ];
                }
            } catch (Throwable) {
                // Ignore DB error during initial setup
            }
        }

        return $list;
    }

    private function findStrategy(string $id): array
    {
        foreach ($this->discoverStrategies() as $strategy) {
            if ($strategy['id'] === $id) {
                return $strategy;
            }
        }

        return [
            'id' => $id,
            'name' => ucfirst(str_replace('_', ' ', $id)),
            'version' => '1.0.0',
            'kind' => 'UNKNOWN',
            'market_type' => 'linear_perpetual',
            'symbols' => ['BTCUSDT'],
            'timeframes' => ['5', '15', '30', '60'],
            'requires_funding' => false,
            'requires_open_interest' => false,
        ];
    }

    /**
     * Derive required warmup bars from actual strategy indicator parameters and timeframes.
     * Prevents assuming an arbitrary fixed threshold across diverse strategies.
     */
    public function requiredWarmupBars(array $strategy): int
    {
        if (isset($strategy['min_bars']) && is_numeric($strategy['min_bars'])) {
            return max(1, (int) $strategy['min_bars']);
        }

        $strategyId = (string) ($strategy['id'] ?? '');
        if ($this->strategyRegistry->has($strategyId)) {
            $instance = $this->strategyRegistry->get($strategyId);
            $params = $instance->parameters();
            if (isset($params['min_bars']) && is_numeric($params['min_bars'])) {
                return max(1, (int) $params['min_bars']);
            }
            if (isset($params['history_limit']) && is_numeric($params['history_limit'])) {
                return max(1, (int) $params['history_limit']);
            }
            if (isset($params['period']) && is_numeric($params['period'])) {
                return max(1, (int) $params['period'] + 10);
            }
            if (isset($params['entry_ema_slow']) && is_numeric($params['entry_ema_slow'])) {
                return max(1, (int) $params['entry_ema_slow'] + 10);
            }
            if (isset($params['trend_ema_slow']) && is_numeric($params['trend_ema_slow'])) {
                return max(1, (int) $params['trend_ema_slow'] + 10);
            }
        }

        return self::MINIMUM_WARMUP_BARS;
    }

    private function formatRecord(ExchangeStrategyCompatibilityRecord $record): array
    {
        return [
            'exchange_id' => $record->exchange_id,
            'strategy_id' => $record->strategy_id,
            'strategy_version' => $record->strategy_version,
            'status' => $record->status,
            'research_eligible' => $record->research_eligible,
            'shadow_eligible' => $record->shadow_eligible,
            'paper_eligible' => $record->paper_eligible,
            'checks' => [
                'market_type_compatible' => $record->market_type_compatible,
                'instruments_compatible' => $record->instruments_compatible,
                'timeframes_compatible' => $record->timeframes_compatible,
                'history_compatible' => $record->history_compatible,
                'features_compatible' => $record->features_compatible,
                'execution_model_compatible' => $record->execution_model_compatible,
            ],
            'diagnostics' => $record->diagnostics,
            'dataset_coverage' => $record->dataset_coverage,
            'evaluated_at' => $record->evaluated_at?->format(DATE_ATOM),
        ];
    }
}
