<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signals', function (Blueprint $table): void {
            $table->index(['side', 'decision_time', 'id'], 'signals_side_time_id_index');
        });

        Schema::table('strategy_observations', function (Blueprint $table): void {
            $table->index(['strategy_id', 'configuration_hash', 'decision_time'], 'strategy_obs_hash_time_index');
            $table->index(['symbol', 'decision_time'], 'strategy_obs_symbol_time_index');
        });

        Schema::table('equity_snapshots', function (Blueprint $table): void {
            $table->index(['paper_account_id', 'recorded_at', 'id'], 'equity_account_recorded_index');
        });

        Schema::table('market_candles', function (Blueprint $table): void {
            $table->index(['symbol', 'interval', 'is_closed', 'open_time'], 'market_candles_closed_series_index');
        });

        Schema::table('trading_journal_events', function (Blueprint $table): void {
            $table->index(['event_type', 'occurred_at', 'sequence'], 'journal_event_type_time_index');
        });

        Schema::table('virtual_positions', function (Blueprint $table): void {
            $table->index(['paper_account_id', 'closed_at'], 'positions_account_closed_index');
        });
    }

    public function down(): void
    {
        Schema::table('virtual_positions', function (Blueprint $table): void {
            $table->dropIndex('positions_account_closed_index');
        });

        Schema::table('trading_journal_events', function (Blueprint $table): void {
            $table->dropIndex('journal_event_type_time_index');
        });

        Schema::table('market_candles', function (Blueprint $table): void {
            $table->dropIndex('market_candles_closed_series_index');
        });

        Schema::table('equity_snapshots', function (Blueprint $table): void {
            $table->dropIndex('equity_account_recorded_index');
        });

        Schema::table('strategy_observations', function (Blueprint $table): void {
            $table->dropIndex('strategy_obs_symbol_time_index');
            $table->dropIndex('strategy_obs_hash_time_index');
        });

        Schema::table('signals', function (Blueprint $table): void {
            $table->dropIndex('signals_side_time_id_index');
        });
    }
};
