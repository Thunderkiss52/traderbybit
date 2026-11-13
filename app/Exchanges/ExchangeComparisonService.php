<?php

declare(strict_types=1);

namespace App\Exchanges;

use App\Exchanges\Domain\ExchangeLifecycleStatus;
use App\StrategyBuilder\PrimaryStrategies;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Read-only comparison of registered exchange x standalone strategy accounts. */
final readonly class ExchangeComparisonService
{
    public function report(): array
    {
        $strategies = PrimaryStrategies::ids();
        $registry = app(ExchangeRegistry::class);
        $exchanges = $registry->registeredIds();
        // Report every registered strategy/exchange pair, even when an
        // operator has disabled entry on that pair. Missing accounts must
        // remain visible rather than disappearing from topology counts.
        $scopes = array_fill_keys($exchanges, $strategies);
        $rows = DB::table('paper_accounts')->where('is_primary', true)
            ->whereIn('exchange_id', $exchanges)->get();
        $byPair = [];
        foreach ($rows as $account) {
            $strategy = $account->primary_strategy_id;
            $exchange = $account->exchange_id;
            if (! in_array($strategy, $strategies, true)) {
                continue;
            }
            $key = $exchange.'|'.$strategy;
            if (isset($byPair[$key])) {
                throw new RuntimeException('Duplicate PRIMARY paper-account provenance.');
            }
            if ($account->strategy_id !== $strategy || $account->research_experiment_id !== null) {
                throw new RuntimeException('Inconsistent PRIMARY paper-account provenance.');
            }
            $byPair[$key] = $account;
        }

        // Preload diagnostics to classify inactivity reasons efficiently without N+1 queries
        $exchangeApprovals = Schema::hasTable('exchanges')
            ? DB::table('exchanges')->get(['id', 'paper_approved', 'lifecycle_status'])->keyBy('id')
            : collect();

        $candleStats = Schema::hasTable('market_candles')
            ? DB::table('market_candles')
                ->where('is_closed', true)
                ->where('category', 'linear')
                ->selectRaw('exchange, symbol, interval, count(*) as count, max(open_time) as latest_open')
                ->groupBy('exchange', 'symbol', 'interval')
                ->get()
                ->groupBy('exchange')
            : collect();

        $openPositions = Schema::hasTable('virtual_positions')
            ? DB::table('virtual_positions')
                ->whereNull('closed_at')
                ->selectRaw('paper_account_id, count(*) as count')
                ->groupBy('paper_account_id')
                ->pluck('count', 'paper_account_id')
                ->all()
            : [];

        $recentPositions = Schema::hasTable('virtual_positions')
            ? DB::table('virtual_positions')
                ->whereNotNull('closed_at')
                ->where('closed_at', '>=', now('UTC')->subDay())
                ->selectRaw('paper_account_id, count(*) as count')
                ->groupBy('paper_account_id')
                ->pluck('count', 'paper_account_id')
                ->all()
            : [];

        $now = CarbonImmutable::now('UTC');
        // Resolve adapter capabilities once per venue, not once per account:
        // large dynamic strategy matrices must not create an N+1 DB workload.
        $adapters = [];
        foreach ($exchanges as $exchangeId) {
            $adapters[$exchangeId] = $registry->hasAdapter($exchangeId);
        }
        $scopeSets = [];
        foreach ($scopes as $exchangeId => $strategyIds) {
            $scopeSets[$exchangeId] = array_fill_keys($strategyIds, true);
        }
        $requiredSymbols = array_values(array_unique((array) config('market_data.symbols', ['BTCUSDT', 'ETHUSDT'])));
        $requiredIntervals = array_values(array_unique(array_map('strval', (array) config('market_data.intervals', ['5', '15', '30', '60']))));

        $result = [];
        foreach ($strategies as $strategy) {
            foreach ($exchanges as $exchange) {
                if (! isset($scopeSets[$exchange][$strategy])) {
                    continue;
                }
                $account = $byPair[$exchange.'|'.$strategy] ?? null;
                $hasAdapter = $adapters[$exchange];
                $exchangeRecord = $exchangeApprovals->get($exchange);
                $isApproved = $exchangeRecord !== null
                    && (bool) $exchangeRecord->paper_approved
                    && $exchangeRecord->lifecycle_status === ExchangeLifecycleStatus::ActivePaper->value;
                // A single recent candle on one market must never make an entire
                // exchange appear ready for BOTH BTC/ETH and all required timeframes.
                $series = $candleStats->get($exchange, collect());
                $candleCount = (int) $series->sum('count');
                $indexedSeries = $series->keyBy(static fn ($row) => $row->symbol.'|'.$row->interval);
                $missingSeries = [];
                $staleSeries = [];
                foreach ($requiredSymbols as $symbol) {
                    foreach ($requiredIntervals as $interval) {
                        $key = $symbol.'|'.$interval;
                        $row = $indexedSeries->get($key);
                        if ($row === null || $row->latest_open === null) {
                            $missingSeries[] = $key;

                            continue;
                        }
                        $lag = $now->getTimestamp() - CarbonImmutable::parse((string) $row->latest_open)->getTimestamp();
                        // Compare each closed series against its own candle interval.
                        // Negative lag is not evidence of a fresh CLOSED candle.
                        $maxLag = max(120, (int) $interval * 60 * 3);
                        if ($lag < 0 || $lag > $maxLag) {
                            $staleSeries[] = $key;
                        }
                    }
                }
                $isFresh = $missingSeries === [] && $staleSeries === [];

                $accId = $account?->id;
                $openCount = $accId ? (int) ($openPositions[$accId] ?? 0) : 0;
                $recentCount = $accId ? (int) ($recentPositions[$accId] ?? 0) : 0;

                // Deterministic Inactivity Reason Classification
                if ($account === null) {
                    $inactivityReason = 'ACCOUNT_NOT_PROVISIONED';
                    $inactivityDetail = 'Paper account has not been provisioned in database topology.';
                } elseif ($account->status !== 'ACTIVE') {
                    if (! $isApproved) {
                        $inactivityReason = 'PAPER_EXECUTION_UNAPPROVED';
                        $inactivityDetail = "Exchange '{$exchange}' is not approved for virtual paper trading.";
                    } else {
                        $inactivityReason = 'ACCOUNT_BLOCKED';
                        $inactivityDetail = 'Paper account is administratively blocked.';
                    }
                } elseif (! $isApproved) {
                    $inactivityReason = 'PAPER_EXECUTION_UNAPPROVED';
                    $inactivityDetail = "Exchange '{$exchange}' lacks active owner PAPER approval.";
                } elseif (! BigDecimal::of((string) $account->current_equity)->isPositive()) {
                    $inactivityReason = 'ACCOUNT_UNFUNDED';
                    $inactivityDetail = 'Account has no positive current equity for new PAPER entries.';
                } elseif (! $hasAdapter) {
                    $inactivityReason = 'MARKET_DATA_OFFLINE';
                    $inactivityDetail = "Market data adapter for '{$exchange}' is offline or not implemented.";
                } elseif ($candleCount === 0) {
                    $inactivityReason = 'NO_MARKET_CANDLES';
                    $inactivityDetail = "No closed linear market candles ingested for '{$exchange}'.";
                } elseif ($missingSeries !== []) {
                    $inactivityReason = 'MARKET_DATA_INCOMPLETE';
                    $inactivityDetail = 'Missing closed series: '.implode(', ', $missingSeries);
                } elseif (! $isFresh) {
                    $inactivityReason = 'MARKET_DATA_STALE';
                    $inactivityDetail = 'Stale or future-dated closed series: '.implode(', ', $staleSeries);
                } elseif ($openCount > 0) {
                    $inactivityReason = 'TRADING_ACTIVE';
                    $inactivityDetail = "Active paper positions currently open ({$openCount}).";
                } elseif ($recentCount > 0) {
                    $inactivityReason = 'TRADING_IDLE';
                    $inactivityDetail = 'Recent closed executions recorded; awaiting new entry triggers.';
                } else {
                    $inactivityReason = 'AWAITING_SIGNAL';
                    $inactivityDetail = 'Market data live & fresh; evaluating closed candles for strategy entry criteria.';
                }

                $result[] = [
                    'strategy_id' => $strategy,
                    'exchange_id' => $exchange,
                    'account_id' => $account?->id,
                    'status' => $account?->status ?? 'ACCOUNT_NOT_PROVISIONED',
                    'evaluation_status' => $account === null ? 'NOT_EVALUATED'
                        : ($account->status === 'ACTIVE' && $isApproved ? 'STRATEGY_EVALUATING' : 'EXECUTION_BLOCKED'),
                    'inactivity_reason' => $inactivityReason,
                    'inactivity_detail' => $inactivityDetail,
                    'market_data_scope' => 'CLOSED_LINEAR_BTC_ETH_REQUIRED_TIMEFRAMES',
                    'readiness_scope' => 'CATALOG_AND_MARKET_DIAGNOSTIC_NOT_ACCOUNT_ENTRY_GATE',
                    'market_data_missing_series' => $missingSeries,
                    'market_data_stale_series' => $staleSeries,
                    'starting_balance' => $account === null ? null : (string) $account->starting_balance,
                    'current_equity' => $account === null ? null : (string) $account->current_equity,
                    'realized_pnl' => $account === null ? null : (string) $account->realized_pnl,
                    'unrealized_pnl' => $account === null ? null : (string) $account->unrealized_pnl,
                    'drawdown_percent' => $account === null ? null : (string) $account->current_drawdown_percent,
                ];
            }
        }

        return [
            'scope' => 'PER_EXCHANGE_PER_STRATEGY',
            'portfolio_aggregation' => 'DISABLED_BY_DEFAULT',
            'mode' => 'PAPER_ONLY',
            'strategy_families' => count($strategies),
            'expected_accounts' => array_sum(array_map('count', $scopes)),
            'provisioned_accounts' => count(array_filter($result, static fn ($row) => $row['account_id'] !== null)),
            'exchanges' => $exchanges,
            'accounts' => $result,
        ];
    }
}
