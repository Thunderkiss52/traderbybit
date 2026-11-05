<?php

declare(strict_types=1);

namespace App\PaperTrading\Application;

use App\StrategyBuilder\PrimaryStrategies;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Entry-only circuit breaker for the aggregate, funded PRIMARY virtual capital.
 * SUM(account peak) is a conservative upper bound on a synchronous portfolio peak.
 * This does NOT reconstruct synchronized portfolio drawdown or guarantee an exit at 5%:
 * already-open positions still settle and gaps/slippage can exceed the boundary.
 */
final class GlobalPaperCapitalGuard
{
    public function entryRejectionReason(?string $proposedRiskCapital = null): ?string
    {
        if (config('paper.global_capital.enabled', true) !== true) {
            return null;
        }
        try {
            $cap = BigDecimal::of((string) config('paper.global_capital.maximum_loss_percent', '5'));
            // Never allow configuration to silently relax the owner-defined 5% cap.
            if ($cap->isLessThanOrEqualTo('0') || $cap->isGreaterThan('5')) {
                return 'global_capital_limit_unverified';
            }
            $approvedDbExchanges = DB::table('exchanges')->where('paper_approved', true)->pluck('id')->all();
            $exchanges = array_values(array_unique(array_merge((array) config('exchanges.enabled', ['bybit', 'binance']), $approvedDbExchanges)));
            $strategies = PrimaryStrategies::ids();
            if ($exchanges === [] || $strategies === [] || count($exchanges) !== count(array_unique($exchanges))
                || count($strategies) !== count(array_unique($strategies))) {
                return 'global_capital_topology_unverified';
            }
            $accounts = DB::table('paper_accounts')->where('is_primary', true)->get();
            $starting = BigDecimal::zero();
            $peakTotal = BigDecimal::zero();
            $protectedPeak = BigDecimal::zero();
            $equity = BigDecimal::zero();
            $reserved = BigDecimal::zero();
            $fundedCount = 0;
            $seen = [];
            foreach ($accounts as $account) {
                if (! in_array($account->exchange_id, $exchanges, true)
                    || ! in_array($account->primary_strategy_id, $strategies, true)
                    || $account->primary_strategy_id !== $account->strategy_id
                    || $account->research_experiment_id !== null) {
                    return 'global_capital_topology_unverified';
                }
                $key = $account->exchange_id.'|'.$account->primary_strategy_id;
                if (isset($seen[$key])) {
                    return 'global_capital_topology_unverified';
                }
                $seen[$key] = true;
                foreach (['starting_balance', 'current_equity', 'peak_equity', 'reserved_risk'] as $field) {
                    if (! is_numeric($account->{$field}) || str_contains(strtolower((string) $account->{$field}), 'e')) {
                        return 'global_capital_valuation_unverified';
                    }
                }
                $base = BigDecimal::of((string) $account->starting_balance);
                $current = BigDecimal::of((string) $account->current_equity);
                $peak = BigDecimal::of((string) $account->peak_equity);
                $accountRisk = BigDecimal::of((string) $account->reserved_risk);
                if ($base->isNegative() || $accountRisk->isNegative() || $peak->isLessThan($base) || $peak->isLessThan($current)) {
                    return 'global_capital_valuation_unverified';
                }
                if ($base->isZero()) {
                    // Unfunded, BLOCKED provisioning rows carry no virtual capital.
                    if (! $current->isZero() || ! $peak->isZero() || ! $accountRisk->isZero() || $account->status === 'ACTIVE') {
                        return 'global_capital_valuation_unverified';
                    }

                    continue;
                }
                if (! str_starts_with($account->strategy_id, 'user_')) {
                    $starting = $starting->plus($base);
                    $protectedPeak = $protectedPeak->plus($peak);
                }
                $peakTotal = $peakTotal->plus($peak);
                $equity = $equity->plus($current);
                $reserved = $reserved->plus($accountRisk);
                $fundedCount++;
            }
            if ($fundedCount === 0 || $starting->isLessThanOrEqualTo('0')) {
                return 'global_capital_valuation_unverified';
            }
            $loss = $peakTotal->minus($equity);
            if ($loss->isGreaterThanOrEqualTo($protectedPeak->multipliedBy($cap)->dividedBy(100, 24, RoundingMode::HalfEven))) {
                return 'global_capital_loss_limit_exceeded';
            }
            if ($proposedRiskCapital !== null) {
                if (! preg_match('/^(?:0|[0-9]+)(?:\.[0-9]+)?$/D', $proposedRiskCapital)) {
                    return 'global_risk_budget_unverified';
                }
                $riskAfter = $reserved->plus(BigDecimal::of($proposedRiskCapital));
                if ($riskAfter->isGreaterThan($starting->multipliedBy($cap)->dividedBy(100, 24, RoundingMode::HalfEven))) {
                    return 'global_risk_budget_exceeded';
                }
            }

            return null;
        } catch (Throwable) {
            return 'global_capital_valuation_unverified';
        }
    }

    /** Serialize simultaneous entry decisions on PostgreSQL across all accounts. */
    public function lockEntryDecision(): void
    {
        if (config('paper.global_capital.enabled', true) === true && DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(20261103, 50001)');
        }
    }
}
