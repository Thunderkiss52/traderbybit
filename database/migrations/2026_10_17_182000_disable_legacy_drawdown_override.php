<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve accounts and all trading records. Disable any old unlimited bypass.
        DB::table('paper_accounts')->where('drawdown_override', true)
            ->update(['drawdown_override' => false, 'updated_at' => now()]);

        // A non-destructive baseline for older accounts; does not create or rewrite trades.
        DB::table('paper_accounts')->orderBy('id')->chunkById(100, function ($accounts): void {
            foreach ($accounts as $account) {
                DB::table('equity_snapshots')->insertOrIgnore([
                    'paper_account_id' => $account->id,
                    'recorded_at' => $account->created_at,
                    'equity' => $account->starting_balance,
                    'realized_pnl' => '0', 'unrealized_pnl' => '0', 'drawdown_percent' => '0',
                    'source_event_key' => 'account:initial',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Intentionally do not restore a risk-safety bypass.
    }
};
