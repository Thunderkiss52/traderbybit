<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

return new class extends Migration
{
    /** @var list<string> */
    private array $strategies = [
        'funding_open_interest_squeeze',
        'liquidity_sweep_reversal',
        'volatility_compression_breakout',
        'btc_eth_lead_lag',
        'multi_timeframe_trend_following',
    ];

    public function up(): void
    {
        foreach ($this->strategies as $strategyId) {
            // Existing candidate/research accounts retain their identity and history.
            if (DB::table('paper_accounts')->where('primary_strategy_id', $strategyId)->exists()) {
                continue;
            }

            $id = Uuid::uuid5(Uuid::NAMESPACE_URL, "traderbybit:paper:primary:{$strategyId}")->toString();
            $now = now();
            DB::table('paper_accounts')->insertOrIgnore([
                'id' => $id,
                'name' => 'Forward Paper '.$strategyId,
                'status' => 'ACTIVE',
                'starting_balance' => '10000',
                'current_equity' => '10000',
                'peak_equity' => '10000',
                'realized_pnl' => '0',
                'unrealized_pnl' => '0',
                'reserved_risk' => '0',
                'maximum_drawdown_percent' => '0',
                'current_drawdown_percent' => '0',
                'mode' => 'STRATEGY_FORWARD_PAPER',
                'is_primary' => true,
                'strategy_id' => $strategyId,
                'primary_strategy_id' => $strategyId,
                'strategy_version' => '1.0.0',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('equity_snapshots')->insertOrIgnore([
                'paper_account_id' => $id,
                'recorded_at' => $now,
                'equity' => '10000',
                'realized_pnl' => '0',
                'unrealized_pnl' => '0',
                'drawdown_percent' => '0',
                'source_event_key' => 'account:initial',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally irreversible: deleting paper accounts would destroy ledger history.
        // The five accounts must be retired by status, never by dropping their records.
    }
};
