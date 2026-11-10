<?php

declare(strict_types=1);

namespace App\PaperTrading\Application;

use App\Exchanges\Binance\BinancePaperProvisioner;
use App\Exchanges\ExchangeRegistry;
use App\PaperTrading\Infrastructure\Persistence\EquitySnapshotRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\Strategy\Application\StrategyRegistry;
use App\StrategyBuilder\PrimaryStrategies;
use Brick\Math\BigDecimal;
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
        private ?NewPrimaryCapitalPolicy $capitalPolicy = null,
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
                if ($apply) {
                    // Advisory transaction lock to prevent concurrent synchronization races
                    DB::select('SELECT pg_advisory_xact_lock(20261110, 10002)');
                }

                $existing = PaperAccountRecord::query()->where('is_primary', true)->get();
                $pairs = [];
                foreach ($existing as $account) {
                    $key = $account->exchange_id.'|'.$account->primary_strategy_id;
                    if (isset($pairs[$key]) || $account->strategy_id !== $account->primary_strategy_id
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

                $policy = $this->capitalPolicy ?? app(NewPrimaryCapitalPolicy::class);
                $initiallyApproved = (array) config('paper.initially_approved_bybit_strategies', []);
                $createdBybit = 0;
                $createdOther = 0;
                $createdBinance = 0;

                $missingAccounts = [];
                $missingSnapshots = [];
                $missingCapitalEvents = [];
                $now = now('UTC');

                // Ensure default Bybit baseline account exists first if required
                if (in_array('bybit', $exchangeIds, true) && in_array('baseline', $scopes['bybit'], true) && ! isset($pairs['bybit|baseline'])) {
                    $this->accounts->default();
                    $createdBybit++;
                    $pairs['bybit|baseline'] = PaperAccountRecord::query()->where('primary_strategy_id', 'baseline')->where('exchange_id', 'bybit')->first();
                }

                foreach ($missing as $m) {
                    $exchange = $m['exchange_id'];
                    $id = $m['strategy_id'];
                    $key = $exchange.'|'.$id;
                    if (isset($pairs[$key])) {
                        continue;
                    }

                    $strategy = $this->strategies->get($id);
                    $parameters = $strategy->parameters();
                    $version = $strategy->version();

                    $accountId = ($exchange === 'bybit')
                        ? Uuid::uuid5(Uuid::NAMESPACE_URL, "traderbybit:paper:primary:{$id}")->toString()
                        : Uuid::uuid5(Uuid::NAMESPACE_URL, "traderbybit:paper:primary:{$exchange}:{$id}")->toString();

                    $startingBalance = $policy->openingBalance($exchange, $id);
                    $isExecutionApproved = (bool) config("exchanges.{$exchange}.paper_execution_approved", false);
                    $accountStatus = ($exchange === 'bybit' || $isExecutionApproved) ? 'ACTIVE' : 'BLOCKED';

                    $missingAccounts[] = [
                        'id' => $accountId,
                        'name' => strtoupper($exchange).' Paper '.$id,
                        'status' => $accountStatus,
                        'starting_balance' => $startingBalance,
                        'current_equity' => $startingBalance,
                        'peak_equity' => $startingBalance,
                        'realized_pnl' => '0',
                        'unrealized_pnl' => '0',
                        'reserved_risk' => '0',
                        'maximum_drawdown_percent' => '0',
                        'current_drawdown_percent' => '0',
                        'mode' => 'STRATEGY_FORWARD_PAPER',
                        'exchange_id' => $exchange,
                        'strategy_id' => $id,
                        'primary_strategy_id' => $id,
                        'is_primary' => true,
                        'strategy_version' => $version,
                        'configuration_hash' => StrategyRegistry::hash($parameters),
                        'strategy_parameters' => json_encode($parameters, JSON_THROW_ON_ERROR),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (BigDecimal::of($startingBalance)->isPositive()) {
                        $missingCapitalEvents[] = [
                            'id' => Uuid::uuid5(Uuid::NAMESPACE_URL, "vector:paper-allocation:{$accountId}:initial")->toString(),
                            'paper_account_id' => $accountId,
                            'exchange_id' => $exchange,
                            'amount' => $startingBalance,
                            'approval_reference' => 'POLICY_INITIAL_CAPITAL',
                            'reason' => 'INITIAL_RESEARCH_CAPITAL_ALLOCATION',
                            'occurred_at' => $now,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    $missingSnapshots[] = [
                        'paper_account_id' => $accountId,
                        'exchange_id' => $exchange,
                        'recorded_at' => $now,
                        'equity' => $startingBalance,
                        'realized_pnl' => '0',
                        'unrealized_pnl' => '0',
                        'drawdown_percent' => '0',
                        'source_event_key' => 'account:initial',
                        'valued_through' => $now,
                        'valuation_fresh' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if ($exchange === 'bybit') {
                        $createdBybit++;
                    } elseif ($exchange === 'binance') {
                        $createdBinance++;
                    } else {
                        $createdOther++;
                    }
                }

                // Batch insert chunked for massive scalability
                if (! empty($missingAccounts)) {
                    foreach (array_chunk($missingAccounts, 250) as $chunk) {
                        PaperAccountRecord::query()->insert($chunk);
                    }
                }

                if (! empty($missingCapitalEvents)) {
                    foreach (array_chunk($missingCapitalEvents, 250) as $chunk) {
                        DB::table('paper_capital_events')->insertOrIgnore($chunk);
                    }
                }

                if (! empty($missingSnapshots)) {
                    foreach (array_chunk($missingSnapshots, 250) as $chunk) {
                        EquitySnapshotRecord::query()->insertOrIgnore($chunk);
                    }
                }

                // Ensure all existing primary accounts have at least an initial equity snapshot
                $existingWithoutSnapshots = PaperAccountRecord::query()
                    ->where('is_primary', true)
                    ->whereNotIn('id', DB::table('equity_snapshots')->select('paper_account_id'))
                    ->get();

                if ($existingWithoutSnapshots->isNotEmpty()) {
                    $recoverySnapshots = [];
                    foreach ($existingWithoutSnapshots as $acc) {
                        $recoverySnapshots[] = [
                            'paper_account_id' => $acc->id,
                            'exchange_id' => $acc->exchange_id,
                            'recorded_at' => $acc->created_at ?? $now,
                            'equity' => $acc->starting_balance,
                            'realized_pnl' => '0',
                            'unrealized_pnl' => '0',
                            'drawdown_percent' => '0',
                            'source_event_key' => 'account:initial',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                    EquitySnapshotRecord::query()->insertOrIgnore($recoverySnapshots);
                }

                return [
                    'mode' => 'APPLIED',
                    'expected_pairs' => array_sum(array_map('count', $scopes)),
                    'created_bybit' => $createdBybit,
                    'created_binance_blocked_unfunded' => $createdBinance,
                    'created_other_blocked_unfunded' => $createdOther,
                    'created_binance' => $createdBinance,
                    'created_other' => $createdOther,
                    'binance_pending_approval' => in_array('binance', $exchangeIds, true) && ! (bool) config('exchanges.binance_paper_execution_approved', false),
                    'paper_orders' => 'NEVER_REAL',
                ];
            }, attempts: 1);
        } finally {
            $lock?->release();
        }
    }
}
