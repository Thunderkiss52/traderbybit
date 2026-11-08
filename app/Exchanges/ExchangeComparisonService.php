<?php

declare(strict_types=1);

namespace App\Exchanges;

use App\StrategyBuilder\PrimaryStrategies;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Read-only comparison of registered exchange x standalone strategy accounts. */
final readonly class ExchangeComparisonService
{
    public function report(): array
    {
        $strategies = PrimaryStrategies::ids();
        $exchanges = app(ExchangeRegistry::class)->registeredIds();
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
        $result = [];
        foreach ($strategies as $strategy) {
            foreach ($exchanges as $exchange) {
                if (! in_array($strategy, $scopes[$exchange], true)) {
                    continue;
                }
                $account = $byPair[$exchange.'|'.$strategy] ?? null;
                $result[] = [
                    'strategy_id' => $strategy,
                    'exchange_id' => $exchange,
                    'account_id' => $account?->id,
                    'status' => $account?->status ?? 'ACCOUNT_NOT_PROVISIONED',
                    'evaluation_status' => $account === null ? 'NOT_EVALUATED'
                        : ($account->status === 'ACTIVE' ? 'STRATEGY_EVALUATING' : ($exchange === 'binance' ? 'EXECUTION_BLOCKED' : 'BLOCKED')),
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
