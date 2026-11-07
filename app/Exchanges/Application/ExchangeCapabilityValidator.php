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

        $hasCandleData = CandleRecord::query()->where('exchange', $exchange->id)->exists();

        $supportedTimeframes = array_map(UniversalAcquisitionService::normalizeInterval(...), (array) ($capabilities['supported_timeframes'] ?? []));
        $requiredTimeframes = ['5', '15', '30', '60'];
        $hasTimeframes = count(array_intersect($requiredTimeframes, $supportedTimeframes)) === count($requiredTimeframes);

        $hasCostModel = $this->validRate($costModel['taker_rate'] ?? null)
            && $this->validRate($costModel['slippage_rate'] ?? null);
        // These are the only venue/market models the authoritative ledger currently executes.
        $hasExecutionModel = in_array($exchange->id, ['bybit', 'binance'], true)
            && $exchange->market_type === 'linear_perpetual';
        $symbols = (array) config('market_data.symbols', ['BTCUSDT', 'ETHUSDT']);
        $hasFreshContext = $symbols !== [];
        $hasFundingSettlement = $symbols !== [];
        foreach ($symbols as $symbol) {
            $context = MarketContextRecord::query()->where('exchange', $exchange->id)
                ->where('category', 'linear')->where('symbol', $symbol)
                ->whereBetween('observed_at', [now('UTC')->subSeconds(300), now('UTC')])
                ->whereNotNull('qty_step')->whereNotNull('min_order_qty')
                ->whereNotNull('max_order_qty')->whereNotNull('min_notional_value')
                ->where('qty_step', '>', 0)->where('min_order_qty', '>', 0)
                ->whereColumn('max_order_qty', '>=', 'min_order_qty')->where('min_notional_value', '>=', 0)
                ->where('bid', '>', 0)->whereColumn('ask', '>=', 'bid')->whereNotNull('funding_rate')->exists();
            $hasFreshContext = $hasFreshContext && $context;
            $hours = config("market_data.funding_schedules.{$exchange->id}.{$symbol}.interval_hours");
            $settled = is_int($hours) && $hours > 0 && MarketFundingEventRecord::query()
                ->where('exchange', $exchange->id)->where('category', 'linear')->where('symbol', $symbol)
                ->whereBetween('funding_at', [now('UTC')->subHours($hours)->subSeconds(300), now('UTC')])
                ->whereNotNull('mark_price')->where('mark_price', '>', 0)->exists();
            $hasFundingSettlement = $hasFundingSettlement && $settled;
        }

        $unresolvedConflicts = DB::table('market_candle_conflicts')
            ->where('exchange', $exchange->id)
            ->where('status', 'UNRESOLVED')
            ->count();
        $zeroConflicts = $unresolvedConflicts === 0;

        $hasPrecision = isset($capabilities['price_precision']) && isset($capabilities['qty_precision']);

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
            $reasonsMissing[] = 'NO_CANDLE_DATA: No historical or public candles exist in the database for this exchange.';
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
