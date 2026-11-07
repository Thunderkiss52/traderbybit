<?php

declare(strict_types=1);

namespace App\PaperTrading\Application;

use App\Exchanges\Binance\BinancePaperProvisioner;
use App\Exchanges\ExchangeRegistry;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\Strategy\Application\StrategyRegistry;
use App\StrategyBuilder\PrimaryStrategies;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Reconciles the explicitly enabled exchange x PRIMARY-strategy PAPER topology.
 * Adding a strategy to the approved registry generates its missing pair(s).
 * New pairs are never activated for trading without the exchange-specific gate.
 */
final readonly class PaperTopologySynchronizer
{
    public function __construct(
        private StrategyRegistry $strategies,
        private ExchangeRegistry $exchanges,
        private PaperAccountService $accounts,
        private BinancePaperProvisioner $binance,
        private CacheFactory $cache,
    ) {}

    public function sync(bool $apply = false): array
    {
        $strategyIds = array_values(PrimaryStrategies::ids());
        $exchangeIds = $this->exchanges->registeredIds();
        if ($strategyIds === [] || $exchangeIds === [] || count($strategyIds) !== count(array_unique($strategyIds))
            || count($exchangeIds) !== count(array_unique($exchangeIds))) {
            throw new RuntimeException('Exchange and PRIMARY strategy lists must be nonempty and unique.');
        }
        foreach ($strategyIds as $id) {
            // Presence in configuration alone is not a functioning strategy.
            $this->strategies->get($id);
        }
        foreach ($exchangeIds as $id) {
            if ($this->exchanges->hasAdapter($id)) {
                $this->exchanges->resolve($id);
            }
            // A public-data adapter may be registered before PAPER readiness.
            // All non-Bybit/Binance accounts will remain unfunded and BLOCKED.
        }
        $scopes = PrimaryStrategies::byExchange($exchangeIds);
        // A dry-run must not write a distributed cache lock or change PAPER state.
        $lock = $apply ? $this->cache->lock('paper:topology-sync', 120) : null;
        if ($lock !== null && ! $lock->get()) {
            throw new RuntimeException('PAPER topology synchronization is already running.');
        }
        try {
            return DB::transaction(function () use ($exchangeIds, $apply, $scopes): array {
                $existing = PaperAccountRecord::query()->where('is_primary', true)->get();
                $pairs = [];
                foreach ($existing as $account) {
                    $key = $account->exchange_id.'|'.$account->primary_strategy_id;
                    if (isset($pairs[$key]) || ! in_array($account->exchange_id, $exchangeIds, true)
                        || ! in_array($account->primary_strategy_id, $scopes[$account->exchange_id] ?? [], true)
                        || $account->strategy_id !== $account->primary_strategy_id
                        || $account->research_experiment_id !== null) {
                        throw new RuntimeException('Unexpected or inconsistent PRIMARY account; owner reconciliation required.');
                    }
                    $pairs[$key] = $account;
                }

                $missing = [];
                foreach ($exchangeIds as $exchange) {
                    foreach ($scopes[$exchange] as $strategy) {
                        if (! isset($pairs[$exchange.'|'.$strategy])) {
                            $missing[] = ['exchange_id' => $exchange, 'strategy_id' => $strategy];
                        }
                    }
                }
                $binanceApproved = config('exchanges.binance_paper_provisioning_approved') === true;
                if (! $apply) {
                    return [
                        'mode' => 'DRY_RUN', 'expected_pairs' => array_sum(array_map('count', $scopes)),
                        'existing_pairs' => count($pairs), 'missing_pairs' => $missing,
                        'binance_provisioning_approved' => $binanceApproved,
                        'paper_orders' => 'NEVER_REAL',
                    ];
                }
                $createdBybit = 0;
                if (in_array('bybit', $exchangeIds, true)) {
                    foreach ($scopes['bybit'] as $id) {
                        $missingBybit = ! isset($pairs['bybit|'.$id]);
                        $account = $this->accounts->primaryAccount($id, 'bybit');
                        if ($missingBybit) {
                            // A newly registered strategy is provisioned, NOT automatically validated.
                            // Existing 13 PRIMARY accounts are never implicitly reblocked or reactivated.
                            if (! in_array($id, (array) config('paper.initially_approved_bybit_strategies', []), true)) {
                                $account->update(['status' => 'BLOCKED']);
                            }
                            $createdBybit++;
                        }
                    }
                }
                $createdOther = 0;
                $createdBinance = 0;
                foreach ($exchangeIds as $exchange) {
                    if ($exchange === 'bybit' || ($exchange === 'binance' && $binanceApproved)) {
                        continue;
                    }
                    foreach ($scopes[$exchange] as $id) {
                        if (isset($pairs[$exchange.'|'.$id])) {
                            continue;
                        }
                        $strategy = $this->strategies->get($id);
                        $parameters = $strategy->parameters();
                        $accountId = Uuid::uuid5(Uuid::NAMESPACE_URL, "traderbybit:paper:primary:{$exchange}:{$id}")->toString();
                        if (PaperAccountRecord::query()->where('id', $accountId)->exists()) {
                            throw new RuntimeException('Canonical PRIMARY account identifier already occupied.');
                        }
                        PaperAccountRecord::query()->create([
                            'id' => $accountId, 'name' => strtoupper($exchange).' Paper '.$id,
                            'status' => 'BLOCKED', 'starting_balance' => '0',
                            'current_equity' => '0', 'peak_equity' => '0',
                            'realized_pnl' => '0', 'unrealized_pnl' => '0', 'reserved_risk' => '0',
                            'maximum_drawdown_percent' => '0', 'current_drawdown_percent' => '0',
                            'mode' => 'STRATEGY_FORWARD_PAPER', 'exchange_id' => $exchange,
                            'strategy_id' => $id, 'primary_strategy_id' => $id, 'is_primary' => true,
                            'strategy_version' => $strategy->version(),
                            'configuration_hash' => StrategyRegistry::hash($parameters),
                            'strategy_parameters' => $parameters,
                        ]);
                        if ($exchange === 'binance') {
                            $createdBinance++;
                        } else {
                            $createdOther++;
                        }
                    }
                }
                if (in_array('binance', $exchangeIds, true) && $binanceApproved) {
                    $createdBinance = $this->binance->provision()['created'];
                }

                return [
                    'mode' => 'APPLIED', 'expected_pairs' => array_sum(array_map('count', $scopes)),
                    'created_bybit' => $createdBybit, 'created_binance_blocked_unfunded' => $createdBinance,
                    'created_other_blocked_unfunded' => $createdOther,
                    'binance_pending_approval' => in_array('binance', $exchangeIds, true) && ! $binanceApproved,
                    'paper_orders' => 'NEVER_REAL',
                ];
            }, attempts: 1);
        } finally {
            $lock?->release();
        }
    }
}
