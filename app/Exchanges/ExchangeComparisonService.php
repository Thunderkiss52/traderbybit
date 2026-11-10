<?php

declare(strict_types=1);

namespace App\Exchanges;

use App\StrategyBuilder\PrimaryStrategies;
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
        $scopes = PrimaryStrategies::byExchange($exchanges);
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
            ? DB::table('exchanges')->pluck('paper_approved', 'id')->all()
            : [];

        $candleStats = Schema::hasTable('market_candles')
            ? DB::table('market_candles')
                ->selectRaw('exchange, count(*) as count, max(open_time) as latest_open')
                ->groupBy('exchange')
                ->get()
                ->keyBy('exchange')
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

        $result = [];
        foreach ($strategies as $strategy) {
            foreach ($exchanges as $exchange) {
                if (! in_array($strategy, $scopes[$exchange], true)) {
                    continue;
                }
                $account = $byPair[$exchange.'|'.$strategy] ?? null;
                $hasAdapter = $registry->hasAdapter($exchange);
                $isApproved = (bool) ($exchangeApprovals[$exchange] ?? false);
                $candleRow = $candleStats->get($exchange);
                $candleCount = (int) ($candleRow->count ?? 0);
                $latestOpen = $candleRow->latest_open ?? null;
                $isFresh = false;
                if ($latestOpen !== null) {
                    $diffSeconds = abs($now->getTimestamp() - CarbonImmutable::parse((string) $latestOpen)->getTimestamp());
                    $isFresh = ($diffSeconds <= 3600);
                }

                $accId = $account?->id;
                $openCount = $accId ? (int) ($openPositions[$accId] ?? 0) : 0;
                $recentCount = $accId ? (int) ($recentPositions[$accId] ?? 0) : 0;

                // Deterministic Inactivity Reason Classification
                if ($account === null) {
                    $inactivityReason = 'ACCOUNT_NOT_PROVISIONED';
                    $inactivityDetail = 'Paper account has not been provisioned in database topology.';
                } elseif ($account->status === 'BLOCKED') {
                    if (! $isApproved) {
                        $inactivityReason = 'PAPER_EXECUTION_UNAPPROVED';
                        $inactivityDetail = "Exchange '{$exchange}' is not approved for virtual paper trading.";
                    } else {
                        $inactivityReason = 'ACCOUNT_BLOCKED';
                        $inactivityDetail = 'Paper account is administratively blocked.';
                    }
                } elseif (! $hasAdapter) {
                    $inactivityReason = 'MARKET_DATA_OFFLINE';
                    $inactivityDetail = "Market data adapter for '{$exchange}' is offline or not implemented.";
                } elseif ($candleCount === 0) {
                    $inactivityReason = 'NO_MARKET_CANDLES';
                    $inactivityDetail = "No market candles ingested for '{$exchange}'.";
                } elseif (! $isFresh) {
                    $inactivityReason = 'MARKET_DATA_STALE';
                    $inactivityDetail = "Market candle feed for '{$exchange}' is stale (> 3600s lag).";
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
                        : ($account->status === 'ACTIVE' ? 'STRATEGY_EVALUATING' : ($exchange === 'binance' ? 'EXECUTION_BLOCKED' : 'BLOCKED')),
                    'inactivity_reason' => $inactivityReason,
                    'inactivity_detail' => $inactivityDetail,
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
