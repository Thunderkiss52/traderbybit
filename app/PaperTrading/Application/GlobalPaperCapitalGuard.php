<?php

declare(strict_types=1);

namespace App\PaperTrading\Application;

use App\Exchanges\ExchangeRegistry;
use App\StrategyBuilder\PrimaryStrategies;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Entry-only circuit breaker for aggregate active, funded PRIMARY virtual capital.
 * Distinguishes provisioned capital, funded capital, execution-approved capital,
 * active risk-bearing capital, and risk-blocked capital.
 * BLOCKED, UNAPPROVED, or non-active accounts must NEVER inflate the deployable risk budget.
 */
final class GlobalPaperCapitalGuard
{
    /**
     * Determine whether a proposed entry is rejected by global capital or risk-budget limits.
     */
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

            $summary = $this->computeCapitalSummary();
            if ($summary['status'] !== 'VALID') {
                return $summary['rejection_reason'];
            }

            $activeStarting = BigDecimal::of($summary['active_risk_bearing_capital']);
            $activeProtectedPeak = BigDecimal::of($summary['active_protected_peak']);
            $activePeakTotal = BigDecimal::of($summary['active_peak_total']);
            $activeEquity = BigDecimal::of($summary['active_current_equity']);
            $reserved = BigDecimal::of($summary['reserved_risk']);

            if ($summary['active_funded_count'] === 0 || $activeStarting->isLessThanOrEqualTo('0')) {
                return 'global_capital_no_active_risk_capital';
            }

            // Realized portfolio loss evaluated solely against active risk-bearing protected peak
            $loss = $activePeakTotal->minus($activeEquity);
            $lossLimit = $activeProtectedPeak->multipliedBy($cap)->dividedBy(100, 24, RoundingMode::HalfEven);
            if ($loss->isGreaterThanOrEqualTo($lossLimit)) {
                return 'global_capital_loss_limit_exceeded';
            }

            // Risk budget evaluated solely against active risk-bearing capital
            if ($proposedRiskCapital !== null) {
                if (! preg_match('/^(?:0|[0-9]+)(?:\.[0-9]+)?$/D', $proposedRiskCapital)) {
                    return 'global_risk_budget_unverified';
                }
                $riskAfter = $reserved->plus(BigDecimal::of($proposedRiskCapital));
                $riskBudget = $activeStarting->multipliedBy($cap)->dividedBy(100, 24, RoundingMode::HalfEven);
                if ($riskAfter->isGreaterThan($riskBudget)) {
                    return 'global_risk_budget_exceeded';
                }
            }

            return null;
        } catch (Throwable) {
            return 'global_capital_valuation_unverified';
        }
    }

    /**
     * Compute canonical breakdown of risk-bearing capital across all primary accounts.
     *
     * @return array<string, mixed>
     */
    public function computeCapitalSummary(): array
    {
        $cap = BigDecimal::of((string) config('paper.global_capital.maximum_loss_percent', '5'));
        $exchanges = app(ExchangeRegistry::class)->registeredIds();
        $strategies = PrimaryStrategies::ids();

        if ($exchanges === [] || $strategies === []
            || count($exchanges) !== count(array_unique($exchanges))
            || count($strategies) !== count(array_unique($strategies))) {
            return [
                'status' => 'INVALID',
                'rejection_reason' => 'global_capital_topology_unverified',
            ];
        }

        $accounts = DB::table('paper_accounts')->where('is_primary', true)->get();

        $provisionedCapital = BigDecimal::zero();
        $fundedCapital = BigDecimal::zero();
        $executionApprovedCapital = BigDecimal::zero();
        $activeRiskBearingCapital = BigDecimal::zero();
        $activeProtectedPeak = BigDecimal::zero();
        $activePeakTotal = BigDecimal::zero();
        $activeEquity = BigDecimal::zero();
        $riskBlockedCapital = BigDecimal::zero();
        $reservedRisk = BigDecimal::zero();

        $totalAccountsCount = count($accounts);
        $activeFundedCount = 0;
        $blockedCount = 0;
        $seen = [];

        foreach ($accounts as $account) {
            if (! in_array($account->exchange_id, $exchanges, true)
                || ! in_array($account->primary_strategy_id, $strategies, true)
                || $account->primary_strategy_id !== $account->strategy_id
                || $account->research_experiment_id !== null) {
                return [
                    'status' => 'INVALID',
                    'rejection_reason' => 'global_capital_topology_unverified',
                ];
            }

            $key = $account->exchange_id.'|'.$account->primary_strategy_id;
            if (isset($seen[$key])) {
                return [
                    'status' => 'INVALID',
                    'rejection_reason' => 'global_capital_topology_unverified',
                ];
            }
            $seen[$key] = true;

            foreach (['starting_balance', 'current_equity', 'peak_equity', 'reserved_risk'] as $field) {
                if (! is_numeric($account->{$field}) || str_contains(strtolower((string) $account->{$field}), 'e')) {
                    return [
                        'status' => 'INVALID',
                        'rejection_reason' => 'global_capital_valuation_unverified',
                    ];
                }
            }

            $base = BigDecimal::of((string) $account->starting_balance);
            $current = BigDecimal::of((string) $account->current_equity);
            $peak = BigDecimal::of((string) $account->peak_equity);
            $accountRisk = BigDecimal::of((string) $account->reserved_risk);

            if ($base->isNegative() || $accountRisk->isNegative() || $peak->isLessThan($base) || $peak->isLessThan($current)) {
                return [
                    'status' => 'INVALID',
                    'rejection_reason' => 'global_capital_valuation_unverified',
                ];
            }

            $provisionedCapital = $provisionedCapital->plus($base);
            if ($base->isPositive()) {
                $fundedCapital = $fundedCapital->plus($base);
            }

            $isApprovedVenue = $this->isExchangeExecutionApproved((string) $account->exchange_id);
            if ($isApprovedVenue) {
                $executionApprovedCapital = $executionApprovedCapital->plus($base);
            }

            $isCooldown = $account->cooldown_until !== null && ! Carbon::parse($account->cooldown_until)->isPast();
            $isActiveRiskBearing = ($account->status === 'ACTIVE') && $isApprovedVenue && ! $isCooldown;

            if ($isActiveRiskBearing) {
                if (! str_starts_with((string) $account->strategy_id, 'user_')) {
                    $activeRiskBearingCapital = $activeRiskBearingCapital->plus($base);
                    $activeProtectedPeak = $activeProtectedPeak->plus($peak);
                }
                $activePeakTotal = $activePeakTotal->plus($peak);
                $activeEquity = $activeEquity->plus($current);
                $reservedRisk = $reservedRisk->plus($accountRisk);
                $activeFundedCount++;
            } else {
                $riskBlockedCapital = $riskBlockedCapital->plus($base);
                $blockedCount++;
            }
        }

        $deployableLossBudget = $activeProtectedPeak->multipliedBy($cap)->dividedBy(100, 24, RoundingMode::HalfEven);
        $deployableRiskBudget = $activeRiskBearingCapital->multipliedBy($cap)->dividedBy(100, 24, RoundingMode::HalfEven);
        $availableRiskBudget = BigDecimal::max(BigDecimal::zero(), $deployableRiskBudget->minus($reservedRisk));

        return [
            'status' => 'VALID',
            'provisioned_capital' => (string) $provisionedCapital,
            'funded_capital' => (string) $fundedCapital,
            'execution_approved_capital' => (string) $executionApprovedCapital,
            'active_risk_bearing_capital' => (string) $activeRiskBearingCapital,
            'active_protected_peak' => (string) $activeProtectedPeak,
            'active_peak_total' => (string) $activePeakTotal,
            'active_current_equity' => (string) $activeEquity,
            'risk_blocked_capital' => (string) $riskBlockedCapital,
            'reserved_risk' => (string) $reservedRisk,
            'deployable_loss_budget' => (string) $deployableLossBudget,
            'deployable_risk_budget' => (string) $deployableRiskBudget,
            'available_risk_budget' => (string) $availableRiskBudget,
            'active_funded_count' => $activeFundedCount,
            'blocked_accounts_count' => $blockedCount,
            'total_accounts_count' => $totalAccountsCount,
            'cap_percent' => (string) $cap,
        ];
    }

    /**
     * Check if a given exchange is approved for live virtual paper execution.
     */
    public function isExchangeExecutionApproved(string $exchangeId): bool
    {
        if ($exchangeId === 'bybit') {
            return true;
        }

        return (bool) config("exchanges.{$exchangeId}.paper_execution_approved", false);
    }

    /** Serialize simultaneous entry decisions on PostgreSQL across all accounts. */
    public function lockEntryDecision(): void
    {
        if (config('paper.global_capital.enabled', true) === true && DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(20261103, 50001)');
        }
    }
}
