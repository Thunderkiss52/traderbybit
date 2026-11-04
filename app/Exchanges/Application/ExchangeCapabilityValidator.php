<?php

declare(strict_types=1);

namespace App\Exchanges\Application;

use App\Exchanges\ExchangeRegistry;
use App\Exchanges\Infrastructure\Persistence\ExchangeRecord;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
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

        $hasLiveAdapter = $this->registry->hasAdapter($exchange->id)
            || (! empty($capabilities['adapter_class']) && class_exists((string) $capabilities['adapter_class']));

        $hasCandleData = CandleRecord::query()->where('exchange', $exchange->id)->exists();

        $supportedTimeframes = (array) ($capabilities['supported_timeframes'] ?? []);
        $requiredTimeframes = ['5', '15', '30', '60'];
        $hasTimeframes = count(array_intersect($requiredTimeframes, $supportedTimeframes)) === count($requiredTimeframes);

        $hasCostModel = ! empty($costModel['taker_rate']) && ! empty($costModel['slippage_rate']);

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

        $eligible = empty($reasonsMissing);

        return [
            'eligible' => $eligible,
            'reasons_missing' => $reasonsMissing,
            'checks' => $checks,
            'status' => $exchange->lifecycle_status,
        ];
    }
}
