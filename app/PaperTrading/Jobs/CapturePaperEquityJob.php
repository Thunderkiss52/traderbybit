<?php

declare(strict_types=1);

namespace App\PaperTrading\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/** Regular valuation checkpoints, including when there are no open positions. */
final class CapturePaperEquityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function handle(): void
    {
        $at = now('UTC')->startOfMinute();
        $key = 'checkpoint:'.$at->format('Y-m-d\\TH:i:00\\Z');
        DB::table('paper_accounts')->orderBy('id')->chunkById(100, static function ($accounts) use ($at, $key): void {
            foreach ($accounts as $account) {
                DB::table('equity_snapshots')->insertOrIgnore([
                    'paper_account_id' => $account->id, 'recorded_at' => $at,
                    'equity' => $account->current_equity, 'realized_pnl' => $account->realized_pnl,
                    'unrealized_pnl' => $account->unrealized_pnl,
                    'drawdown_percent' => $account->current_drawdown_percent,
                    'source_event_key' => $key, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }
}
