<?php

declare(strict_types=1);

namespace App\Exchanges\Application;

use App\Exchanges\ExchangeRegistry;
use App\Exchanges\Infrastructure\Persistence\ExchangeRecord;
use App\HistoricalData\UniversalAcquisitionService;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Infrastructure\Persistence\MarketFundingEventRecord;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

final readonly class ExchangeCapabilityValidator
{
    public function __construct(private ExchangeRegistry $registry) {}

    /**
     * Test live public network connectivity to an exchange.
     *
     * @return array{
     *     reachable: bool,
     *     latency_ms: float|null,
     *     probe_status: string,
     *     error: string|null
     * }
     */
    public function testConnectivity(ExchangeRecord|string $exchange): array
    {
        $id = $exchange instanceof ExchangeRecord ? $exchange->id : $exchange;
        if (! $this->registry->hasAdapter($id)) {
            return [
                'reachable' => false,
                'latency_ms' => null,
                'probe_status' => 'NO_ADAPTER',
                'error' => "No live adapter registered for '{$id}'.",
            ];
        }

        try {
            $adapter = $this->registry->resolve($id);
            if (method_exists($adapter, 'testConnectivity')) {
                /** @var array{reachable: bool, latency_ms: float|null, error: string|null} $res */
                $res = $adapter->testConnectivity();

                return [
                    'reachable' => (bool) $res['reachable'],
                    'latency_ms' => $res['latency_ms'],
                    'probe_status' => $res['reachable'] ? 'SUCCESS' : 'FAILED',
                    'error' => $res['error'],
                ];
            }

            $start = microtime(true);
            $adapter->fetchTicker('BTCUSDT');
            $latency = round((microtime(true) - $start) * 1000, 2);

            return [
                'reachable' => true,
                'latency_ms' => $latency,
                'probe_status' => 'SUCCESS',
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'reachable' => false,
                'latency_ms' => null,
                'probe_status' => 'FAILED',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Audit whether an exchange qualifies for PAPER eligibility and research.
     *
     * @return array{
     *     eligible: bool,
     *     reasons_missing: list<string>,
     *     checks: array<string, bool>,
     *     status: string
     * }
     */
    public function evaluate(ExchangeRecord $exchange): array
    {
        $capabilities = (array) ($exchange->capabilities ?? []);
        $costModel = (array) ($exchange->cost_model ?? []);

        $hasLiveAdapter = $this->registry->hasAdapter($exchange->id);

        $hasCandleData = false; // A single historical row does not establish usable coverage.

        $supportedTimeframes = array_map(UniversalAcquisitionService::normalizeInterval(...), (array) ($capabilities['supported_timeframes'] ?? []));
        $requiredTimeframes = ['5', '15', '30', '60'];
        $hasTimeframes = count(array_intersect($requiredTimeframes, $supportedTimeframes)) === count($requiredTimeframes);
        $missingCandleCoverage = [];
        $marketCategory = (string) $exchange->market_type === 'spot' ? 'spot' : 'linear';
        $coverageSymbols = (array) config('market_data.symbols', ['BTCUSDT', 'ETHUSDT']);
        $hasCandleData = $coverageSymbols !== [];
        foreach ($coverageSymbols as $symbol) {
            foreach ($requiredTimeframes as $interval) {
                $minutes = (int) $interval;
                $lastThree = CandleRecord::query()
                    ->where('exchange', $exchange->id)
                    ->where('category', $marketCategory)
                    ->where('symbol', $symbol)
                    ->where('interval', $interval)
                    ->where('is_closed', true)
                    ->orderByDesc('open_time')
                    ->limit(3)
                    ->get(['open_time']);
                $valid = $lastThree->count() === 3;
                if ($valid) {
                    $openTimes = $lastThree->map(fn ($row): int => $row->open_time->getTimestamp())->all();
                    $step = $minutes * 60;
                    $expectedLatestOpen = intdiv(now('UTC')->getTimestamp(), $step) * $step - $step;
                    $valid = $openTimes[0] <= $expectedLatestOpen
                        && $openTimes[0] >= $expectedLatestOpen - (2 * $step)
                        && $step === $openTimes[0] - $openTimes[1]
                        && $step === $openTimes[1] - $openTimes[2];
                }
                if (! $valid) {
                    $missingCandleCoverage[] = $symbol.':'.$interval;
                    $hasCandleData = false;
                }
            }
        }
        $hasTimeframes = $hasTimeframes && $hasCandleData;

        $hasCostModel = $this->validRate($costModel['taker_rate'] ?? null)
            && $this->validRate($costModel['slippage_rate'] ?? null);

        $hasPrecision = isset($capabilities['price_precision']) && isset($capabilities['qty_precision']);

        // Technical execution support must be independently verifiable BEFORE
        // operator approval; checking paper_execution_approved here creates an
        // impossible circular dependency for dynamically onboarded venues.
        $hasExecutionModel = PaperExecutionModelContract::supported(
            (string) $exchange->market_type,
            $capabilities,
            (array) ($exchange->paper_execution_assumptions ?? []),
            $costModel,
            $hasLiveAdapter,
        );
        $isSpot = ($exchange->market_type === 'spot') || empty($capabilities['has_funding']);

        $symbols = (array) config('market_data.symbols', ['BTCUSDT', 'ETHUSDT']);
        $hasFreshContext = $symbols !== [];
        $hasFundingSettlement = true;
        foreach ($symbols as $symbol) {
            $contextQuery = MarketContextRecord::query()->where('exchange', $exchange->id)
                ->where('symbol', $symbol)
                ->whereBetween('observed_at', [now('UTC')->subSeconds(300), now('UTC')])
                ->whereNotNull('qty_step')->whereNotNull('min_order_qty')
                ->whereNotNull('max_order_qty')->whereNotNull('min_notional_value')
                ->where('qty_step', '>', 0)->where('min_order_qty', '>', 0)
                ->whereColumn('max_order_qty', '>=', 'min_order_qty')->where('min_notional_value', '>=', 0)
                ->where('bid', '>', 0)->whereColumn('ask', '>=', 'bid');

            if (! $isSpot) {
                $contextQuery->whereNotNull('funding_rate');
            }

            $hasFreshContext = $hasFreshContext && $contextQuery->exists();

            if (! $isSpot) {
                $hours = config("market_data.funding_schedules.{$exchange->id}.{$symbol}.interval_hours");
                $settled = is_int($hours) && $hours > 0 && MarketFundingEventRecord::query()
                    ->where('exchange', $exchange->id)->where('symbol', $symbol)
                    ->whereBetween('funding_at', [now('UTC')->subHours($hours)->subSeconds(300), now('UTC')])
                    ->whereNotNull('mark_price')->where('mark_price', '>', 0)->exists();
                $hasFundingSettlement = $hasFundingSettlement && $settled;
            }
        }

        $unresolvedConflicts = DB::table('market_candle_conflicts')
            ->where('exchange', $exchange->id)
            ->where('status', 'UNRESOLVED')
            ->count();
        $zeroConflicts = $unresolvedConflicts === 0;

        $checks = [
            'has_live_adapter' => $hasLiveAdapter,
            'has_candle_data' => $hasCandleData,
            'has_required_timeframes' => $hasTimeframes,
            'has_cost_model' => $hasCostModel,
            'zero_candle_conflicts' => $zeroConflicts,
            'has_precision_specs' => $hasPrecision,
            'has_execution_model' => $hasExecutionModel,
            'has_fresh_context_and_constraints' => $hasFreshContext,
            'has_funding_settlement' => $hasFundingSettlement,
        ];

        $reasonsMissing = [];
        if (! $hasLiveAdapter) {
            $reasonsMissing[] = 'MISSING_LIVE_ADAPTER: Dedicated REST/WebSocket adapter class is not compiled or registered in ExchangeRegistry.';
        }
        if (! $hasCandleData) {
            $reasonsMissing[] = 'INCOMPLETE_OR_STALE_CANDLE_COVERAGE: Required three contiguous fresh closed bars missing for '.implode(', ', $missingCandleCoverage).'.';
        }
        if (! $hasTimeframes) {
            $missing = array_diff($requiredTimeframes, $supportedTimeframes);
            $reasonsMissing[] = 'MISSING_REQUIRED_TIMEFRAMES: Exchange must declare and support 5m, 15m, 30m, and 60m timeframes (missing: '.implode(', ', $missing).').';
        }
        if (! $hasCostModel) {
            $reasonsMissing[] = 'MISSING_COST_MODEL: Documented taker fee rate and slippage assumptions are required for realistic virtual execution.';
        }
        if (! $zeroConflicts) {
            $reasonsMissing[] = "UNRESOLVED_CANDLE_CONFLICTS: {$unresolvedConflicts} conflicting candle observations must be resolved or quarantined before PAPER activation.";
        }
        if (! $hasPrecision) {
            $reasonsMissing[] = 'MISSING_PRECISION_SPECS: Price tick size and quantity step precision rules must be declared.';
        }
        if (! $hasExecutionModel) {
            $reasonsMissing[] = 'UNIMPLEMENTED_PAPER_EXECUTION_MODEL: This venue and market type have no integrated virtual fill, settlement and ledger model.';
        }
        if (! $hasFreshContext) {
            $reasonsMissing[] = 'MISSING_FRESH_EXECUTION_CONTEXT: Fresh non-future exchange-specific quotes, funding and instrument constraints are required for every configured symbol.';
        }
        if (! $hasFundingSettlement) {
            $reasonsMissing[] = 'MISSING_FUNDING_SETTLEMENT: Recent non-future funding with mark prices and a known settlement schedule is required.';
        }

        $eligible = empty($reasonsMissing);

        return [
            'eligible' => $eligible,
            'reasons_missing' => $reasonsMissing,
            'checks' => $checks,
            'status' => $exchange->lifecycle_status,
        ];
    }

    public function validRate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^0(?:\.[0-9]+)?$/D', $value) === 1
            && BigDecimal::of($value)->isGreaterThanOrEqualTo('0')
            && BigDecimal::of($value)->isLessThan('0.1');
    }
}
