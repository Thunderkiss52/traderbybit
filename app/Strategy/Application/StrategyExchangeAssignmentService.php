<?php

declare(strict_types=1);

namespace App\Strategy\Application;

use App\Exchanges\ExchangeRegistry;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Manages operator strategy/exchange execution vetoes and explicit assignments.
 * Topology provisioning is automatic for registered executable strategies.
 */
final class StrategyExchangeAssignmentService
{
    public function __construct(
        private readonly ExchangeRegistry $exchanges,
    ) {}

    /**
     * Explicitly assign a strategy to an exchange.
     */
    public function assign(
        string $strategyId,
        string $exchangeId,
        string $assignedBy = 'operator',
        string $marketType = 'perpetual',
        array $metadata = []
    ): void {
        $strategyId = trim($strategyId);
        $exchangeId = strtolower(trim($exchangeId));

        if (! Schema::hasTable('strategy_exchange_assignments')) {
            return;
        }

        $now = CarbonImmutable::now('UTC');

        DB::table('strategy_exchange_assignments')->updateOrInsert(
            [
                'strategy_id' => $strategyId,
                'exchange_id' => $exchangeId,
                'market_type' => $marketType,
            ],
            [
                'assigned_by' => $assignedBy,
                'assigned_at' => $now,
                'is_active' => true,
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        Log::info("Strategy '{$strategyId}' explicitly assigned to exchange '{$exchangeId}' by {$assignedBy}.");
    }

    /**
     * Explicitly disable/unassign a strategy from an exchange.
     * Preserves existing accounts and historical financial records without deletion.
     */
    public function unassign(string $strategyId, string $exchangeId, string $marketType = 'perpetual'): void
    {
        $strategyId = trim($strategyId);
        $exchangeId = strtolower(trim($exchangeId));

        if (! Schema::hasTable('strategy_exchange_assignments')) {
            return;
        }

        $now = CarbonImmutable::now('UTC');
        DB::table('strategy_exchange_assignments')->updateOrInsert(
            [
                'strategy_id' => $strategyId,
                'exchange_id' => $exchangeId,
                'market_type' => $marketType,
            ],
            [
                'is_active' => false,
                'assigned_by' => 'unassigned_by_operator',
                'updated_at' => $now,
            ]
        );

        Log::info("Strategy '{$strategyId}' unassigned from exchange '{$exchangeId}'. Existing accounts preserved.");
    }

    /**
     * Execution assignment is a separate control from PAPER account provisioning.
     * Accounts are provisioned for every registered strategy/exchange pair, but
     * an explicit operator veto or a strategy's supported-venue restriction
     * still prevents entry. Missing catalog or strategy evidence fails closed.
     */
    public function hasAssignment(string $strategyId, string $exchangeId, string $marketType = 'perpetual'): bool
    {
        $strategyId = trim($strategyId);
        $exchangeId = strtolower(trim($exchangeId));
        $registry = app(StrategyRegistry::class);
        if (! $registry->has($strategyId) || ! in_array($exchangeId, $this->exchanges->registeredIds(), true)) {
            return false;
        }

        if (Schema::hasTable('strategy_exchange_assignments')) {
            $record = DB::table('strategy_exchange_assignments')
                ->where('strategy_id', $strategyId)
                ->where('exchange_id', $exchangeId)
                ->where('market_type', $marketType)
                ->first();
            if ($record !== null) {
                return (bool) $record->is_active;
            }
        }

        // A strategy with an explicit supported-venue restriction may still
        // have a PAPER account elsewhere for transparent topology accounting,
        // but it is not allowed to trade outside that supported set.
        $strategy = $registry->get($strategyId);
        if (method_exists($strategy, 'targetExchanges')) {
            $targets = $strategy->targetExchanges();
            if ($targets !== null && ! in_array($exchangeId, (array) $targets, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Active execution-assignment projection. An assignment on one venue must
     * not implicitly suppress compatible strategies on another. Explicit
     * operator unassignments are respected. Account topology deliberately uses
     * the complete strategy/exchange matrix independently of this projection:
     * an execution veto must never delete or omit a historical PAPER account.
     *
     * @return list<string>
     */
    public function assignedStrategiesForExchange(string $exchangeId): array
    {
        $exchangeId = strtolower(trim($exchangeId));
        if (! in_array($exchangeId, $this->exchanges->registeredIds(), true)) {
            return [];
        }

        $allStrategies = app(StrategyRegistry::class)->ids();
        if (! Schema::hasTable('strategy_exchange_assignments')) {
            return $allStrategies;
        }

        $disabled = DB::table('strategy_exchange_assignments')
            ->where('exchange_id', $exchangeId)
            ->where('market_type', 'perpetual')
            ->where('is_active', false)
            ->pluck('strategy_id')
            ->all();

        return array_values(array_diff($allStrategies, $disabled));
    }

    /**
     * Get list of exchange IDs where a strategy is actively assigned.
     *
     * @return list<string>
     */
    public function assignedExchangesForStrategy(string $strategyId): array
    {
        $strategyId = trim($strategyId);

        // Explicit rows alone are not authoritative: canonical exchanges may
        // still use implicit legacy assignments. Match the actual entry gate.
        $result = [];
        foreach ($this->exchanges->registeredIds() as $exchangeId) {
            if ($this->hasAssignment($strategyId, $exchangeId)) {
                $result[] = $exchangeId;
            }
        }

        return $result;
    }

    /**
     * Return complete assignment matrix with status and account existence.
     *
     * @return array<string, array<string, array{assigned: bool, account_exists: bool, account_status: string|null}>>
     */
    public function assignmentMatrix(): array
    {
        $strategies = app(StrategyRegistry::class)->ids();
        $exchanges = $this->exchanges->registeredIds();

        $assignments = [];
        foreach ($exchanges as $exchange) {
            // Use the same assignment policy as the real execution gate.
            // Do not mark canonical implicit assignments as missing in the UI.
            $assignments[$exchange] = array_fill_keys($this->assignedStrategiesForExchange($exchange), true);
        }

        $accounts = [];
        if (Schema::hasTable('paper_accounts')) {
            $accRows = PaperAccountRecord::query()->where('is_primary', true)->get(['exchange_id', 'primary_strategy_id', 'status']);
            foreach ($accRows as $acc) {
                $accounts[$acc->exchange_id][$acc->primary_strategy_id] = $acc->status;
            }
        }

        $matrix = [];
        foreach ($exchanges as $exchange) {
            $matrix[$exchange] = [];
            foreach ($strategies as $strategy) {
                $isAssigned = isset($assignments[$exchange][$strategy]);
                $accStatus = $accounts[$exchange][$strategy] ?? null;

                $matrix[$exchange][$strategy] = [
                    'assigned' => $isAssigned,
                    'account_exists' => $accStatus !== null,
                    'account_status' => $accStatus,
                ];
            }
        }

        return $matrix;
    }

    /**
     * Seeds canonical historical assignments preserving all existing portfolios.
     */
    public function seedCanonicalAssignments(): void
    {
        if (! Schema::hasTable('strategy_exchange_assignments')) {
            return;
        }

        $now = CarbonImmutable::now('UTC');
        $canonical = (array) config('paper.primary_strategies', []);
        $canonicalExchanges = (array) config('exchanges.enabled', ['bybit', 'binance', 'coinbase', 'upbit', 'okx', 'bitget', 'gate', 'kucoin', 'kraken', 'htx']);

        // 1. Seed only missing canonical assignments. Never reactivate a row
        // explicitly disabled by the operator or rewrite its audit metadata.
        foreach ($canonicalExchanges as $exchangeId) {
            foreach ($canonical as $strategyId) {
                DB::table('strategy_exchange_assignments')->insertOrIgnore([
                    'strategy_id' => $strategyId,
                    'exchange_id' => $exchangeId,
                    'market_type' => 'perpetual',
                    'assigned_by' => 'canonical_initial_matrix',
                    'assigned_at' => $now,
                    'is_active' => true,
                    'metadata' => json_encode(['source' => 'canonical_exchanges_enabled'], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // 2. Preserve missing assignment records for existing primary accounts,
        // without changing a historical explicit is_active=false veto.
        if (Schema::hasTable('paper_accounts')) {
            $existingAccounts = PaperAccountRecord::query()
                ->where('is_primary', true)
                ->whereNotIn('exchange_id', ['bybit', 'binance'])
                ->get(['exchange_id', 'primary_strategy_id']);

            foreach ($existingAccounts as $acc) {
                if ($acc->primary_strategy_id !== null && $acc->exchange_id !== null) {
                    DB::table('strategy_exchange_assignments')->insertOrIgnore([
                        'strategy_id' => $acc->primary_strategy_id,
                        'exchange_id' => $acc->exchange_id,
                        'market_type' => 'perpetual',
                        'assigned_by' => 'existing_portfolio_reconciliation',
                        'assigned_at' => $now,
                        'is_active' => true,
                        'metadata' => json_encode(['source' => 'paper_accounts_existing'], JSON_THROW_ON_ERROR),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }
}
