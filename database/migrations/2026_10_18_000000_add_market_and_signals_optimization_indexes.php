<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_context_snapshots', function (Blueprint $table): void {
            $table->index(['symbol', 'observed_at'], 'market_context_symbol_observed_index');
        });

        Schema::table('market_candles', function (Blueprint $table): void {
            $table->index(['exchange', 'category', 'symbol', 'interval', 'is_closed', 'open_time'], 'market_candles_lookup_series_index');
        });

        Schema::table('signals', function (Blueprint $table): void {
            $table->index(['symbol', 'timeframe', 'decision_time', 'id'], 'signals_symbol_timeframe_time_index');
            $table->index(['strategy_id', 'decision_time', 'id'], 'signals_strategy_time_page_index');
        });

        Schema::table('strategy_observations', function (Blueprint $table): void {
            $table->index(['decision_time'], 'strategy_obs_decision_time_index');
            $table->index(['strategy_id', 'decision_time'], 'strategy_obs_strategy_decision_time_index');
        });

        Schema::table('virtual_positions', function (Blueprint $table): void {
            $table->index(['symbol', 'opened_at', 'id'], 'positions_symbol_time_page_index');
            $table->index(['paper_account_id', 'symbol', 'opened_at', 'id'], 'positions_account_symbol_time_page_index');
        });
    }

    public function down(): void
    {
        Schema::table('virtual_positions', function (Blueprint $table): void {
            $table->dropIndex('positions_account_symbol_time_page_index');
            $table->dropIndex('positions_symbol_time_page_index');
        });

        Schema::table('strategy_observations', function (Blueprint $table): void {
            $table->dropIndex('strategy_obs_strategy_decision_time_index');
            $table->dropIndex('strategy_obs_decision_time_index');
        });

        Schema::table('signals', function (Blueprint $table): void {
            $table->dropIndex('signals_strategy_time_page_index');
            $table->dropIndex('signals_symbol_timeframe_time_index');
        });

        Schema::table('market_candles', function (Blueprint $table): void {
            $table->dropIndex('market_candles_lookup_series_index');
        });

        Schema::table('market_context_snapshots', function (Blueprint $table): void {
            $table->dropIndex('market_context_symbol_observed_index');
        });
    }
};
