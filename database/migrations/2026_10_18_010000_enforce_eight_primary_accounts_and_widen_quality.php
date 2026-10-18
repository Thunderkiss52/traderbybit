<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paper_accounts', function (Blueprint $table): void {
            $table->string('primary_strategy_id', 64)->nullable()->after('strategy_id');
            $table->boolean('is_primary')->default(false)->after('mode');
            $table->unique('primary_strategy_id', 'paper_accounts_primary_strategy_unique');
        });

        Schema::table('signals', function (Blueprint $table): void {
            $table->string('quality_class', 16)->nullable()->change();
        });

        Schema::table('strategy_observations', function (Blueprint $table): void {
            $table->string('quality_class', 16)->nullable()->change();
        });

        Schema::table('backtest_trades', function (Blueprint $table): void {
            $table->string('quality_class', 16)->nullable()->change();
        });

        $strategies = [
            'baseline',
            'trend_pullback',
            'bollinger_mean_reversion',
            'donchian_atr_breakout',
            'regime_switching',
            'momentum',
            'support_resistance_bounce',
            'support_resistance_breakout',
        ];

        foreach ($strategies as $strategyId) {
            $existing = DB::table('paper_accounts')
                ->where('strategy_id', $strategyId)
                ->whereIn('mode', ['FORWARD_PAPER', 'STRATEGY_FORWARD_PAPER', 'PRIMARY'])
                ->orderBy('created_at')
                ->first();

            if ($existing !== null) {
                DB::table('paper_accounts')
                    ->where('id', $existing->id)
                    ->update([
                        'primary_strategy_id' => $strategyId,
                        'is_primary' => true,
                    ]);
            } elseif (! app()->runningUnitTests()) {
                $id = $strategyId === 'baseline'
                    ? Uuid::uuid5(Uuid::NAMESPACE_URL, 'traderbybit:paper:default')->toString()
                    : Uuid::uuid5(Uuid::NAMESPACE_URL, "traderbybit:paper:primary:{$strategyId}")->toString();
                DB::table('paper_accounts')->insert([
                    'id' => $id,
                    'name' => $strategyId === 'baseline' ? 'Research Portfolio' : 'Forward Paper '.$strategyId,
                    'status' => 'ACTIVE',
                    'starting_balance' => '10000',
                    'current_equity' => '10000',
                    'peak_equity' => '10000',
                    'realized_pnl' => '0',
                    'unrealized_pnl' => '0',
                    'reserved_risk' => '0',
                    'maximum_drawdown_percent' => '0',
                    'current_drawdown_percent' => '0',
                    'mode' => $strategyId === 'baseline' ? 'FORWARD_PAPER' : 'STRATEGY_FORWARD_PAPER',
                    'is_primary' => true,
                    'strategy_id' => $strategyId,
                    'primary_strategy_id' => $strategyId,
                    'strategy_version' => '1.0.0',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('equity_snapshots')->insertOrIgnore([
                    'paper_account_id' => $id,
                    'recorded_at' => now(),
                    'equity' => '10000',
                    'realized_pnl' => '0',
                    'unrealized_pnl' => '0',
                    'drawdown_percent' => '0',
                    'source_event_key' => 'account:initial:'.$strategyId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('paper_accounts', function (Blueprint $table): void {
            $table->dropUnique('paper_accounts_primary_strategy_unique');
            $table->dropColumn(['primary_strategy_id', 'is_primary']);
        });

        Schema::table('backtest_trades', function (Blueprint $table): void {
            $table->string('quality_class', 1)->nullable()->change();
        });

        Schema::table('strategy_observations', function (Blueprint $table): void {
            $table->string('quality_class', 1)->nullable()->change();
        });

        Schema::table('signals', function (Blueprint $table): void {
            $table->string('quality_class', 1)->nullable()->change();
        });
    }
};
