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
 * Manages explicit strategy-to-exchange assignment and topology isolation.
 * Prevents unintended automatic cross-product account creation.
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
     * Check if a strategy is actively assigned to an exchange.
     */
    public function hasAssignment(string $strategyId, string $exchangeId, string $marketType = 'perpetual'): bool
    {
        $strategyId = trim($strategyId);
        $exchangeId = strtolower(trim($exchangeId));

        if (! Schema::hasTable('strategy_exchange_assignments')) {
            return true;
        }

        $record = DB::table('strategy_exchange_assignments')
            ->where('strategy_id', $strategyId)
            ->where('exchange_id', $exchangeId)
            ->first();

        if ($record !== null) {
            return (bool) $record->is_active;
        }

        // If this exchange has any explicit assignments in the table, any other strategy is unassigned
        $hasAnyAssignments = DB::table('strategy_exchange_assignments')
            ->where('exchange_id', $exchangeId)
            ->where('is_active', true)
            ->exists();
        if ($hasAnyAssignments) {
            return false;
        }

        // If exchange was declaratively onboarded without assignments, return false
        try {
            if (Schema::hasTable('exchanges')) {
                $exRecord = DB::table('exchanges')->where('id', $exchangeId)->first();
                if ($exRecord !== null) {
                    $meta = json_decode((string) $exRecord->metadata, true) ?: [];
                    if (! empty($meta['onboarded_declaratively'])) {
                        return false;
                    }
                }
            }
        } catch (\Throwable) {
            // Fall through
        }

        // Check if strategy restricts target exchanges
        try {
            $strategyObj = app(StrategyRegistry::class)->get($strategyId);
            if (method_exists($strategyObj, 'targetExchanges')) {
                $targets = $strategyObj->targetExchanges();
                if ($targets !== null && ! in_array($exchangeId, (array) $targets, true)) {
                    return false;
                }
            }
        } catch (\Throwable) {
            return true;
        }

        return true;
    }

    /**
     * Get list of strategy IDs actively assigned to an exchange.
     *
     * @return list<string>
     */
    public function assignedStrategiesForExchange(string $exchangeId): array
    {
        $exchangeId = strtolower(trim($exchangeId));

        if (! Schema::hasTable('strategy_exchange_assignments')) {
            return app(StrategyRegistry::class)->ids();
        }

        // 1. If explicit active assignments exist in table, return them
        $activeAssignments = DB::table('strategy_exchange_assignments')
            ->where('exchange_id', $exchangeId)
            ->where('is_active', true)
            ->pluck('strategy_id')
            ->all();

        if (! empty($activeAssignments)) {
            return array_values(array_unique($activeAssignments));
        }

        // 2. Check if exchange was onboarded declaratively
        try {
            if (Schema::hasTable('exchanges')) {
                $record = DB::table('exchanges')->where('id', $exchangeId)->first();
                if ($record !== null) {
                    $capabilities = json_decode((string) $record->capabilities, true) ?: [];
                    if (array_key_exists('assigned_strategies', $capabilities)) {
                        return (array) $capabilities['assigned_strategies'];
                    }
                    $meta = json_decode((string) $record->metadata, true) ?: [];
                    if (! empty($meta['onboarded_declaratively'])) {
                        // Declaratively onboarded exchange without explicit strategy assignments gets 0 accounts
                        return [];
                    }
                }
            }
        } catch (\Throwable) {
            // Fall through
        }

        // 3. For canonical baseline exchanges, return all un-disabled strategies respecting targetExchanges
        $disabled = DB::table('strategy_exchange_assignments')
            ->where('exchange_id', $exchangeId)
            ->where('is_active', false)
            ->pluck('strategy_id')
            ->all();

        $allStrategies = app(StrategyRegistry::class)->ids();
        $eligible = [];
        foreach ($allStrategies as $strategyId) {
            if (in_array($strategyId, $disabled, true)) {
                continue;
            }
            try {
                $strategyObj = app(StrategyRegistry::class)->get($strategyId);
                if (method_exists($strategyObj, 'targetExchanges')) {
                    $targets = $strategyObj->targetExchanges();
                    if ($targets !== null && ! in_array($exchangeId, (array) $targets, true)) {
                        continue;
                    }
                }
            } catch (\Throwable) {
                // Keep eligible
            }
            $eligible[] = $strategyId;
        }

        return $eligible;
    }

    /**
     * Get list of exchange IDs where a strategy is actively assigned.
     *
     * @return list<string>
     */
    public function assignedExchangesForStrategy(string $strategyId): array
    {
        $strategyId = trim($strategyId);

        if (! Schema::hasTable('strategy_exchange_assignments')) {
            return $this->exchanges->registeredIds();
        }

        return DB::table('strategy_exchange_assignments')
            ->where('strategy_id', $strategyId)
            ->where('is_active', true)
            ->pluck('exchange_id')
            ->unique()
            ->values()
            ->all();
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
        if (Schema::hasTable('strategy_exchange_assignments')) {
            $rows = DB::table('strategy_exchange_assignments')->where('is_active', true)->get();
            foreach ($rows as $row) {
                $assignments[$row->exchange_id][$row->strategy_id] = true;
            }
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

        // 1. Assign canonical PRIMARY strategies to canonical initial exchanges
        foreach ($canonicalExchanges as $exchangeId) {
            foreach ($canonical as $strategyId) {
                DB::table('strategy_exchange_assignments')->updateOrInsert(
                    [
                        'strategy_id' => $strategyId,
                        'exchange_id' => $exchangeId,
                        'market_type' => 'perpetual',
                    ],
                    [
                        'assigned_by' => 'canonical_initial_matrix',
                        'assigned_at' => $now,
                        'is_active' => true,
                        'metadata' => json_encode(['source' => 'canonical_exchanges_enabled'], JSON_THROW_ON_ERROR),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }
        }

        // 2. For any other exchange that already has existing primary accounts in paper_accounts,
        // preserve their active assignment to avoid breaking historical records!
        if (Schema::hasTable('paper_accounts')) {
            $existingAccounts = PaperAccountRecord::query()
                ->where('is_primary', true)
                ->whereNotIn('exchange_id', ['bybit', 'binance'])
                ->get(['exchange_id', 'primary_strategy_id']);

            foreach ($existingAccounts as $acc) {
                if ($acc->primary_strategy_id !== null && $acc->exchange_id !== null) {
                    DB::table('strategy_exchange_assignments')->updateOrInsert(
                        [
                            'strategy_id' => $acc->primary_strategy_id,
                            'exchange_id' => $acc->exchange_id,
                            'market_type' => 'perpetual',
                        ],
                        [
                            'assigned_by' => 'existing_portfolio_reconciliation',
                            'assigned_at' => $now,
                            'is_active' => true,
                            'metadata' => json_encode(['source' => 'paper_accounts_existing'], JSON_THROW_ON_ERROR),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]
                    );
                }
            }
        }
    }
}
