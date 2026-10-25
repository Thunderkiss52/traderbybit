<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P0 Data Repair Migration:
 * Explicit, idempotent repair for cross-exchange equity snapshots.
 * Corrects 'bybit' exchange_id to 'binance' on checkpoint snapshots
 * belonging to canonical Binance primary accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('paper_accounts') || ! Schema::hasTable('equity_snapshots')) {
            return;
        }

        if (! Schema::hasColumn('paper_accounts', 'exchange_id') || ! Schema::hasColumn('equity_snapshots', 'exchange_id')) {
            return;
        }

        $binanceAccountIds = DB::table('paper_accounts')
            ->where('exchange_id', 'binance')
            ->where('is_primary', true)
            ->pluck('id')
            ->all();

        if (empty($binanceAccountIds)) {
            return;
        }

        DB::table('equity_snapshots')
            ->whereIn('paper_account_id', $binanceAccountIds)
            ->where('exchange_id', 'bybit')
            ->where('source_event_key', 'like', 'checkpoint:%')
            ->update(['exchange_id' => 'binance']);
    }

    public function down(): void
    {
        // Preserved: Historical financial snapshots must not be destructively reverted to incorrect exchange identities.
    }
};
