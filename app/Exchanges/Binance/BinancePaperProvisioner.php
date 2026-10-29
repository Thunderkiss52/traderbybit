<?php

declare(strict_types=1);

namespace App\Exchanges\Binance;

use App\Strategy\Application\StrategyRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/** Explicitly approved, idempotent provisioning; never modifies Bybit account rows. */
final class BinancePaperProvisioner
{
    public function provision(): array
    {
        if (config('exchanges.binance_paper_provisioning_approved') !== true) {
            throw new RuntimeException('Explicit Binance provisioning approval is required.');
        }
        if (! Schema::hasColumn('paper_accounts', 'exchange_id')) {
            throw new RuntimeException('Exchange-scoped schema migration required before provisioning.');
        }

        return DB::transaction(static function (): array {
            $created = 0;
            $existing = 0;
            foreach ((array) config('paper.primary_strategies', []) as $strategyId) {
                $bybit = DB::table('paper_accounts')->where('exchange_id', 'bybit')
                    ->where('primary_strategy_id', $strategyId)->where('is_primary', true)->first();
                $canonicalBybitIds = array_filter([
                    $strategyId === 'baseline' ? Uuid::uuid5(Uuid::NAMESPACE_URL, 'traderbybit:paper:default')->toString() : null,
                    Uuid::uuid5(Uuid::NAMESPACE_URL, 'traderbybit:paper:primary:'.$strategyId)->toString(),
                    Uuid::uuid5(Uuid::NAMESPACE_URL, 'traderbybit:paper:strategy:'.$strategyId)->toString(),
                ]);
                if ($bybit === null || ! in_array($bybit->id, $canonicalBybitIds, true) || $bybit->strategy_id !== $strategyId
                    || $bybit->research_experiment_id !== null) {
                    // The legacy 2026-10-18 migration inferred PRIMARY from strategy_id.
                    // Noncanonical existing accounts require explicit provenance reconciliation.
                    throw new RuntimeException('Canonical Bybit account provenance is not verified.');
                }
                $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'traderbybit:paper:primary:binance:'.$strategyId)->toString();
                $account = DB::table('paper_accounts')->where('exchange_id', 'binance')
                    ->where('primary_strategy_id', $strategyId)->first();
                $strategy = app(StrategyRegistry::class)->get($strategyId);
                $version = $strategy->version();
                $parameters = $strategy->parameters();
                $hash = StrategyRegistry::hash($parameters);
                if ($account !== null) {
                    if ($account->id !== $id || ! $account->is_primary || $account->strategy_id !== $strategyId
                        || $account->research_experiment_id !== null) {
                        throw new RuntimeException('Existing Binance account requires manual provenance reconciliation.');
                    }
                    if ($account->configuration_hash === null && $account->strategy_version === $version) {
                        DB::table('paper_accounts')->where('id', $id)->update([
                            'configuration_hash' => $hash,
                            'strategy_parameters' => json_encode($parameters, JSON_THROW_ON_ERROR),
                        ]);
                    }
                    $existing++;

                    continue;
                }
                if (DB::table('paper_accounts')->where('id', $id)->exists()) {
                    throw new RuntimeException('Canonical Binance account ID is occupied.');
                }
                $now = now('UTC');
                // Per File D Option A: zero opening balance, no fabricated funding.
                DB::table('paper_accounts')->insert([
                    'id' => $id, 'name' => 'Binance Paper '.$strategyId,
                    'status' => 'BLOCKED', 'starting_balance' => '0', 'current_equity' => '0',
                    'peak_equity' => '0', 'realized_pnl' => '0', 'unrealized_pnl' => '0',
                    'reserved_risk' => '0', 'maximum_drawdown_percent' => '0', 'current_drawdown_percent' => '0',
                    'mode' => 'STRATEGY_FORWARD_PAPER', 'strategy_id' => $strategyId,
                    'primary_strategy_id' => $strategyId, 'is_primary' => true,
                    'strategy_version' => $version,
                    'configuration_hash' => $hash,
                    'strategy_parameters' => json_encode($parameters, JSON_THROW_ON_ERROR),
                    'exchange_id' => 'binance', 'created_at' => $now, 'updated_at' => $now,
                ]);
                // Provisioning is not funded trading performance. The approved capital
                // allocation records the first performance snapshot atomically.
                $created++;
            }

            return ['created' => $created, 'already_provisioned' => $existing,
                'exchange_id' => 'binance', 'paper_execution' => 'BLOCKED_UNTIL_VERIFIED'];
        });
    }
}
