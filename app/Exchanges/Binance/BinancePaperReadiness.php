<?php

declare(strict_types=1);

namespace App\Exchanges\Binance;

use App\MarketData\Application\MarketCandleConflictService;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Infrastructure\Persistence\MarketFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\StrategyBuilder\PrimaryStrategies;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Read-only readiness inspection for Binance PAPER cohort and operational gates. */
final class BinancePaperReadiness
{
    private MarketCandleConflictService $conflictService;

    public function __construct(
        private readonly ExchangePaperReconciliation $reconciliation,
        ?MarketCandleConflictService $conflictService = null,
    ) {
        $this->conflictService = $conflictService ?? app(MarketCandleConflictService::class);
    }

    public function inspect(bool $requireExecutionApproval = false): array
    {
        $blockers = [];
        $now = now('UTC');
        $nowSeconds = $now->getTimestamp();

        // 1. Cost Configuration
        $costModelConfig = (array) config('exchanges.binance.cost_model', []);
        $takerRate = config('exchanges.binance.paper_taker_rate');
        $slippageRate = config('exchanges.binance.paper_slippage_rate');
        $costValid = true;
        $costError = null;

        try {
            BinancePaperSafety::assertCostEvidence();
        } catch (Throwable $e) {
            $costValid = false;
            $costError = $e->getMessage();
            $blockers[] = "Cost configuration invalid: {$costError}";
        }

        $costReport = [
            'valid' => $costValid,
            'taker_rate' => is_string($takerRate) ? $takerRate : null,
            'slippage_rate' => is_string($slippageRate) ? $slippageRate : null,
            'fee_tier' => $costModelConfig['fee_tier'] ?? 'VIP 0 (Regular User)',
            'fee_source' => $costModelConfig['fee_source'] ?? 'Binance USD(S)-M Futures Official Fee Schedule (fapi.binance.com)',
            'slippage_source' => $costModelConfig['slippage_source'] ?? 'Top-of-book depth empirical model for BTCUSDT/ETHUSDT linear contracts',
            'version' => $costModelConfig['version'] ?? 'binance-usdm-perpetual-2026.1',
            'units' => $costModelConfig['units'] ?? 'decimal_fraction',
            'precision' => $costModelConfig['precision'] ?? 4,
            'error' => $costError,
        ];

        // 2. Paper Execution Approval Flag
        $executionApproved = (bool) config('exchanges.binance.paper_execution_approved', false);
        if ($requireExecutionApproval && ! $executionApproved) {
            $blockers[] = 'Binance paper execution is not approved (VECTOR_BINANCE_PAPER_EXECUTION_APPROVED=false)';
        }

        // 3. 13-Account Census and Funding
        $primaryStrategies = PrimaryStrategies::ids('binance');
        $accounts = PaperAccountRecord::query()
            ->where('exchange_id', 'binance')
            ->where('is_primary', true)
            ->get()
            ->keyBy('primary_strategy_id');

        $censusAccounts = [];
        $activeCount = 0;
        $blockedCount = 0;
        $fundedCount = 0;
        $censusValid = true;

        if ($accounts->count() !== count($primaryStrategies)) {
            $censusValid = false;
            $blockers[] = sprintf('Binance PRIMARY account census incomplete: expected %d, found %d', count($primaryStrategies), $accounts->count());
        }

        foreach ($primaryStrategies as $strategyId) {
            $account = $accounts->get($strategyId);
            if ($account === null) {
                $censusAccounts[] = [
                    'strategy_id' => $strategyId,
                    'account_id' => null,
                    'status' => 'MISSING',
                    'funded' => false,
                    'current_equity' => '0',
                    'provenance' => 'MISSING',
                ];

                continue;
            }

            $provenanceOk = true;
            try {
                BinancePaperSafety::assertProvenance($account);
            } catch (Throwable) {
                $provenanceOk = false;
                $censusValid = false;
                $blockers[] = "Binance account provenance invalid for strategy: {$strategyId}";
            }

            $hasCapitalEvent = DB::table('paper_capital_events')
                ->where('paper_account_id', $account->id)
                ->exists();
            $hasPositiveEquity = BigDecimal::of((string) $account->current_equity)->isGreaterThan('0');
            $isFunded = $hasCapitalEvent && $hasPositiveEquity;

            if ($isFunded) {
                $fundedCount++;
            } else {
                $censusValid = false;
                $blockers[] = "Binance account lacking verified initial funding for strategy: {$strategyId}";
            }

            if ($account->status === 'ACTIVE') {
                $activeCount++;
            } elseif ($account->status === 'BLOCKED') {
                $blockedCount++;
            }

            $censusAccounts[] = [
                'strategy_id' => $strategyId,
                'account_id' => $account->id,
                'status' => $account->status,
                'funded' => $isFunded,
                'current_equity' => (string) $account->current_equity,
                'provenance' => $provenanceOk ? 'VERIFIED' : 'INVALID',
            ];
        }

        $accountCensus = [
            'expected_count' => count($primaryStrategies),
            'actual_count' => $accounts->count(),
            'active_count' => $activeCount,
            'blocked_count' => $blockedCount,
            'funded_count' => $fundedCount,
            'census_valid' => $censusValid,
            'accounts' => $censusAccounts,
        ];

        // 4. Contiguous Closed Candles (220 bars on 5m, 15m, 30m, 60m)
        $symbols = (array) config('market_data.symbols', ['BTCUSDT', 'ETHUSDT']);
        $intervals = ['5', '15', '30', '60'];
        $candleReport = [];
        $allCandlesReady = true;

        foreach ($symbols as $symbol) {
            $candleReport[$symbol] = [];
            foreach ($intervals as $interval) {
                $candles = CandleRecord::query()
                    ->where('exchange', 'binance')
                    ->where('category', 'linear')
                    ->where('symbol', $symbol)
                    ->where('interval', $interval)
                    ->where('is_closed', true)
                    ->orderByDesc('open_time')
                    ->limit(220)
                    ->get(['open_time']);

                $timestamps = $candles->map(fn ($c): int => $c->open_time->getTimestamp())->all();
                $status = BinanceCandleWindow::status(
                    $timestamps,
                    (int) $interval * 60,
                    $nowSeconds,
                    220,
                    $this->conflictService,
                    'binance',
                    $symbol,
                    $interval
                );
                $count = $candles->count();

                $isReady = ($count === 220 && $status === 'READY');
                if (! $isReady) {
                    $allCandlesReady = false;
                    $blockers[] = $status === 'CANDLE_HISTORY_INTEGRITY_CONFLICT'
                        ? "Binance candle window has active integrity conflict for {$symbol}:{$interval} (status: {$status})"
                        : "Binance candle window not ready for {$symbol}:{$interval} (status: {$status}, bars: {$count}/220)";
                }

                $candleReport[$symbol][$interval] = [
                    'count' => $count,
                    'required' => 220,
                    'status' => $status,
                    'ready' => $isReady,
                    'integrity_reason_code' => str_starts_with($status, 'CANDLE_HISTORY_INTEGRITY_')
                        ? $this->conflictService->integrityReport('binance', $symbol, $interval,
                            $candles->last()?->open_time, $candles->first()?->open_time)['reason_code'] : null,
                ];
            }
        }

        // 5. Recent Market Context (< 15 minutes, non-future)
        $contextReport = [];
        $allContextFresh = true;
        foreach ($symbols as $symbol) {
            $context = MarketContextRecord::query()
                ->where('exchange', 'binance')
                ->where('category', 'linear')
                ->where('symbol', $symbol)
                ->where('observed_at', '<=', $now)
                ->orderByDesc('observed_at')
                ->first();

            if ($context === null) {
                $allContextFresh = false;
                $contextReport[$symbol] = [
                    'observed_at' => null,
                    'age_seconds' => null,
                    'fresh' => false,
                ];
                $blockers[] = "Binance market context missing for {$symbol}";
            } else {
                $ageSeconds = $nowSeconds - $context->observed_at->getTimestamp();
                $isFresh = $ageSeconds >= 0 && $ageSeconds <= 900;
                if (! $isFresh) {
                    $allContextFresh = false;
                    $blockers[] = "Binance market context stale for {$symbol} (age: {$ageSeconds}s, max: 900s)";
                }
                $contextReport[$symbol] = [
                    'observed_at' => $context->observed_at->format(DATE_ATOM),
                    'age_seconds' => $ageSeconds,
                    'fresh' => $isFresh,
                ];
            }
        }

        // 6. Recent Binance Funding Settlement Records (< 24 hours, non-future)
        $fundingReport = [];
        $allFundingFresh = true;
        foreach ($symbols as $symbol) {
            $funding = MarketFundingEventRecord::query()
                ->where('exchange', 'binance')
                ->where('category', 'linear')
                ->where('symbol', $symbol)
                ->where('funding_at', '<=', $now)
                ->orderByDesc('funding_at')
                ->first();

            if ($funding === null) {
                $allFundingFresh = false;
                $fundingReport[$symbol] = [
                    'funding_at' => null,
                    'rate' => null,
                    'age_seconds' => null,
                    'fresh' => false,
                ];
                $blockers[] = "Binance funding settlement records missing for {$symbol}";
            } else {
                $ageSeconds = $nowSeconds - $funding->funding_at->getTimestamp();
                $isFresh = $ageSeconds >= 0 && $ageSeconds <= 86400;
                if (! $isFresh) {
                    $allFundingFresh = false;
                    $blockers[] = "Binance funding settlement records stale for {$symbol} (age: {$ageSeconds}s, max: 86400s)";
                }
                $fundingReport[$symbol] = [
                    'funding_at' => $funding->funding_at->format(DATE_ATOM),
                    'rate' => (string) $funding->rate,
                    'age_seconds' => $ageSeconds,
                    'fresh' => $isFresh,
                ];
            }
        }

        // 7. Research-Cycle Freshness for BTCUSDT and ETHUSDT (< 45 minutes, >= 13 strategies)
        $researchReport = [];
        $allResearchFresh = true;
        foreach ($symbols as $symbol) {
            $latestDecisionTime = DB::table('strategy_observations')
                ->where('exchange', 'binance')
                ->where('symbol', $symbol)
                ->where('decision_time', '<=', $now)
                ->max('decision_time');

            if ($latestDecisionTime === null) {
                $allResearchFresh = false;
                $researchReport[$symbol] = [
                    'latest_decision_time' => null,
                    'strategy_count' => 0,
                    'age_seconds' => null,
                    'fresh' => false,
                ];
                $blockers[] = "Binance research cycle observations missing for {$symbol}";
            } else {
                $latestDt = new DateTimeImmutable((string) $latestDecisionTime);
                $ageSeconds = $nowSeconds - $latestDt->getTimestamp();
                $strategyCount = DB::table('strategy_observations')
                    ->where('exchange', 'binance')
                    ->where('symbol', $symbol)
                    ->where('decision_time', $latestDecisionTime)
                    ->count();

                $isFresh = $ageSeconds >= 0 && $ageSeconds <= 2700 && $strategyCount >= count($primaryStrategies);
                if (! $isFresh) {
                    $allResearchFresh = false;
                    $blockers[] = sprintf(
                        'Binance research cycle observations stale or incomplete for %s (age: %ds, strategies: %d/%d)',
                        $symbol,
                        $ageSeconds,
                        $strategyCount,
                        count($primaryStrategies)
                    );
                }

                $researchReport[$symbol] = [
                    'latest_decision_time' => $latestDt->format(DATE_ATOM),
                    'strategy_count' => $strategyCount,
                    'age_seconds' => $ageSeconds,
                    'fresh' => $isFresh,
                ];
            }
        }

        // 8. Strategy Execution Eligibility (all 13 eligible when signal_only_strategies is empty)
        $signalOnlyGlobal = (array) config('research.forward_paper.signal_only_strategies', []);
        $eligibleStrategies = [];
        $signalOnlyStrategies = [];

        foreach ($primaryStrategies as $strategyId) {
            if (in_array($strategyId, $signalOnlyGlobal, true)) {
                $signalOnlyStrategies[] = $strategyId;
            } else {
                $eligibleStrategies[] = $strategyId;
            }
        }

        $strategyEligibility = [
            'eligible_strategies' => $eligibleStrategies,
            'eligible_count' => count($eligibleStrategies),
            'signal_only_strategies' => $signalOnlyStrategies,
            'signal_only_count' => count($signalOnlyStrategies),
        ];

        // 9. M9 Ledger Status
        $m9Report = $this->reconciliation->inspect();
        $m9Pass = ($m9Report['status'] ?? '') === 'LEDGER_PASS_ONLY';
        if (! $m9Pass) {
            $issueCount = count($m9Report['issues'] ?? []);
            $blockers[] = "M9 ledger reconciliation failed (status: {$m9Report['status']}, issues: {$issueCount})";
        }

        // 10. Synthesize Overall Readiness
        if ($blockers === []) {
            $overallReadiness = $executionApproved ? 'READY_FOR_PAPER_EXECUTION' : 'READY_FOR_ACTIVATION';
        } else {
            $overallReadiness = 'BLOCKED';
        }

        return [
            'exchange_id' => 'binance',
            'overall_readiness' => $overallReadiness,
            'blockers' => $blockers,
            'paper_execution_approved' => $executionApproved,
            'execution_readiness' => $executionApproved ? 'READY_FOR_PAPER_EXECUTION' : 'EXECUTION_BLOCKED_PENDING_APPROVAL',
            'cost_configuration' => $costReport,
            'account_census' => $accountCensus,
            'candle_history' => [
                'symbols' => $candleReport,
                'all_ready' => $allCandlesReady,
            ],
            'market_context' => [
                'symbols' => $contextReport,
                'all_fresh' => $allContextFresh,
            ],
            'funding_settlements' => [
                'symbols' => $fundingReport,
                'all_fresh' => $allFundingFresh,
            ],
            'research_cycle' => [
                'symbols' => $researchReport,
                'all_fresh' => $allResearchFresh,
            ],
            'strategy_eligibility' => $strategyEligibility,
            'm9_ledger' => [
                'status' => $m9Report['status'] ?? 'FAIL',
                'expected_pairs' => $m9Report['expected_pairs'] ?? 26,
                'verified_pairs' => $m9Report['verified_pairs'] ?? 0,
                'issue_count' => count($m9Report['issues'] ?? []),
            ],
        ];
    }
}
