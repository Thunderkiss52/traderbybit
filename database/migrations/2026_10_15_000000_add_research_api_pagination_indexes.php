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
            $table->index(['decision_time', 'id'], 'signals_time_page_index');
            $table->index(['symbol', 'decision_time', 'id'], 'signals_symbol_time_page_index');
        });
        Schema::table('virtual_positions', function (Blueprint $table): void {
            $table->index(['opened_at', 'id'], 'positions_time_page_index');
            $table->index(['paper_account_id', 'opened_at', 'id'], 'positions_account_time_page_index');
        });
        Schema::table('virtual_executions', fn (Blueprint $table) => $table->index(['position_id', 'market_event_at', 'id'], 'executions_position_time_page_index'));
        Schema::table('position_funding_events', fn (Blueprint $table) => $table->index(['position_id', 'funding_at', 'id'], 'funding_position_time_page_index'));
        Schema::table('trading_journal_events', fn (Blueprint $table) => $table->index(['occurred_at', 'sequence'], 'journal_time_page_index'));
        Schema::table('risk_decisions', fn (Blueprint $table) => $table->index(['paper_account_id', 'decided_at', 'id'], 'risk_account_time_page_index'));
        Schema::table('backtest_runs', fn (Blueprint $table) => $table->index(['created_at', 'id'], 'backtest_runs_time_page_index'));
        Schema::table('backtest_trades', fn (Blueprint $table) => $table->index(['backtest_run_id', 'closed_at', 'id'], 'backtest_trades_time_page_index'));
        Schema::table('backtest_equity_snapshots', fn (Blueprint $table) => $table->index(['backtest_run_id', 'recorded_at', 'id'], 'backtest_equity_time_page_index'));
    }

    public function down(): void
    {
        Schema::table('backtest_equity_snapshots', fn (Blueprint $table) => $table->dropIndex('backtest_equity_time_page_index'));
        Schema::table('backtest_trades', fn (Blueprint $table) => $table->dropIndex('backtest_trades_time_page_index'));
        Schema::table('backtest_runs', fn (Blueprint $table) => $table->dropIndex('backtest_runs_time_page_index'));
        Schema::table('risk_decisions', fn (Blueprint $table) => $table->dropIndex('risk_account_time_page_index'));
        Schema::table('trading_journal_events', fn (Blueprint $table) => $table->dropIndex('journal_time_page_index'));
        Schema::table('position_funding_events', fn (Blueprint $table) => $table->dropIndex('funding_position_time_page_index'));
        Schema::table('virtual_executions', fn (Blueprint $table) => $table->dropIndex('executions_position_time_page_index'));
        Schema::table('virtual_positions', function (Blueprint $table): void {
            $table->dropIndex('positions_account_time_page_index');
            $table->dropIndex('positions_time_page_index');
        });
        Schema::table('signals', function (Blueprint $table): void {
            $table->dropIndex('signals_symbol_time_page_index');
            $table->dropIndex('signals_time_page_index');
        });
    }
};
