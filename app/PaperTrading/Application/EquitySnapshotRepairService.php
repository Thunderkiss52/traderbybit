<?php

declare(strict_types=1);

namespace App\PaperTrading\Application;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Explicit, idempotent, auditable repair service for incorrectly labeled
 * cross-exchange equity snapshots on canonical Binance PAPER accounts.
 *
 * Strict Selection Criteria:
 * 1. Owning account has is_primary = true and exchange_id = 'binance'.
 * 2. equity_snapshots.paper_account_id resolves to that canonical Binance account.
 * 3. equity_snapshots.exchange_id is incorrectly 'bybit'.
 * 4. equity_snapshots.source_event_key starts with 'checkpoint:'.
 * 5. Update ONLY exchange_id to 'binance'; never alter equity, PnL, timestamps, or account history.
 */
final class EquitySnapshotRepairService
{
    public function __construct(
        private CacheFactory $cache,
    ) {}

    /**
     * Inspect candidate rows for repair without taking locks or mutating data.
     */
    public function inspect(): array
    {
        $binanceAccounts = DB::table('paper_accounts')
            ->where('exchange_id', 'binance')
            ->where('is_primary', true)
            ->get(['id', 'primary_strategy_id', 'exchange_id'])
            ->keyBy('id');

        if ($binanceAccounts->isEmpty()) {
            return [
                'status' => 'NO_CANONICAL_BINANCE_ACCOUNTS',
                'candidates_count' => 0,
                'candidates' => [],
            ];
        }

        $candidates = DB::table('equity_snapshots')
            ->whereIn('paper_account_id', $binanceAccounts->keys()->all())
            ->where('exchange_id', 'bybit')
            ->where('source_event_key', 'like', 'checkpoint:%')
            ->orderBy('id')
            ->get();

        $manifest = [];
        foreach ($candidates as $row) {
            $account = $binanceAccounts->get($row->paper_account_id);
            $manifest[] = [
                'snapshot_id' => $row->id,
                'paper_account_id' => $row->paper_account_id,
                'strategy_id' => $account?->primary_strategy_id,
                'account_exchange' => 'binance',
                'stored_exchange' => $row->exchange_id,
                'target_exchange' => 'binance',
                'source_event_key' => $row->source_event_key,
                'recorded_at' => (string) $row->recorded_at,
                'equity' => (string) $row->equity,
            ];
        }

        return [
            'status' => count($manifest) > 0 ? 'MISMATCHED_SNAPSHOTS_FOUND' : 'CLEAN',
            'candidates_count' => count($manifest),
            'candidates' => $manifest,
        ];
    }

    /**
     * Execute repair. Defaults to dry-run; requires $apply = true for database mutation.
     */
    public function repair(bool $apply = false): array
    {
        $lock = $this->cache->lock('paper:equity-snapshot-repair', 30);
        if (! $lock->get()) {
            throw new RuntimeException('Equity snapshot repair is already running.');
        }

        try {
            return DB::transaction(function () use ($apply): array {
                $binanceAccounts = DB::table('paper_accounts')
                    ->where('exchange_id', 'binance')
                    ->where('is_primary', true)
                    ->lockForUpdate()
                    ->get(['id', 'primary_strategy_id', 'exchange_id'])
                    ->keyBy('id');

                if ($binanceAccounts->isEmpty()) {
                    return [
                        'status' => 'NO_CANONICAL_BINANCE_ACCOUNTS',
                        'applied' => false,
                        'candidates_count' => 0,
                        'repaired_count' => 0,
                        'repaired_ids' => [],
                        'manifest' => [],
                    ];
                }

                $candidates = DB::table('equity_snapshots')
                    ->whereIn('paper_account_id', $binanceAccounts->keys()->all())
                    ->where('exchange_id', 'bybit')
                    ->where('source_event_key', 'like', 'checkpoint:%')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $manifest = [];
                $idsToRepair = [];

                foreach ($candidates as $row) {
                    $account = $binanceAccounts->get($row->paper_account_id);
                    if ($account === null || $account->exchange_id !== 'binance') {
                        throw new RuntimeException("Ambiguous ownership for snapshot ID [{$row->id}]: account is not canonical Binance primary.");
                    }

                    if (! str_starts_with((string) $row->source_event_key, 'checkpoint:')) {
                        throw new RuntimeException("Unsafe event category for snapshot ID [{$row->id}]: [{$row->source_event_key}]. Only checkpoint snapshots may be repaired.");
                    }

                    $idsToRepair[] = $row->id;
                    $manifest[] = [
                        'snapshot_id' => $row->id,
                        'paper_account_id' => $row->paper_account_id,
                        'strategy_id' => $account->primary_strategy_id,
                        'current_exchange_id' => $row->exchange_id,
                        'repaired_exchange_id' => 'binance',
                        'source_event_key' => $row->source_event_key,
                        'recorded_at' => (string) $row->recorded_at,
                        'equity' => (string) $row->equity,
                    ];
                }

                if (! $apply || empty($idsToRepair)) {
                    return [
                        'status' => empty($idsToRepair) ? 'ALREADY_CONSISTENT' : 'DRY_RUN_PENDING_APPROVAL',
                        'applied' => false,
                        'candidates_count' => count($idsToRepair),
                        'repaired_count' => 0,
                        'repaired_ids' => [],
                        'manifest' => $manifest,
                    ];
                }

                // Explicit, isolated single-column update. Preserves updated_at, equity, pnl, timestamps, keys.
                $updatedCount = DB::table('equity_snapshots')
                    ->whereIn('id', $idsToRepair)
                    ->update(['exchange_id' => 'binance']);

                if ($updatedCount !== count($idsToRepair)) {
                    throw new RuntimeException('Repair row count mismatch: expected ['.count($idsToRepair)."], updated [{$updatedCount}].");
                }

                return [
                    'status' => 'REPAIR_APPLIED_SUCCESSFULLY',
                    'applied' => true,
                    'candidates_count' => count($idsToRepair),
                    'repaired_count' => $updatedCount,
                    'repaired_ids' => $idsToRepair,
                    'manifest' => $manifest,
                ];
            });
        } finally {
            $lock->release();
        }
    }
}
