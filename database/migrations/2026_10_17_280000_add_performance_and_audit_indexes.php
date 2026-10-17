<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('risk_decisions', function (Blueprint $table): void {
            $table->index(['signal_id', 'decided_at'], 'idx_risk_decisions_signal_decided');
        });

        Schema::table('paper_signal_states', function (Blueprint $table): void {
            $table->index(['signal_id', 'updated_at'], 'idx_paper_signal_states_signal_updated');
        });

        Schema::table('virtual_positions', function (Blueprint $table): void {
            $table->index(['signal_id', 'opened_at'], 'idx_virtual_positions_signal_opened');
            $table->index(['paper_account_id', 'closed_at'], 'idx_virtual_positions_account_closed');
            $table->index(['paper_account_id', 'r_multiple'], 'idx_virtual_positions_account_r_multiple');
        });

        Schema::table('equity_snapshots', function (Blueprint $table): void {
            $table->index(['paper_account_id', 'recorded_at'], 'idx_equity_snapshots_account_recorded');
        });

        Schema::table('trading_journal_events', function (Blueprint $table): void {
            $table->index(['signal_id', 'occurred_at'], 'idx_trading_journal_events_signal_occurred');
        });
    }

    public function down(): void
    {
        Schema::table('risk_decisions', function (Blueprint $table): void {
            $table->dropIndex('idx_risk_decisions_signal_decided');
        });

        Schema::table('paper_signal_states', function (Blueprint $table): void {
            $table->dropIndex('idx_paper_signal_states_signal_updated');
        });

        Schema::table('virtual_positions', function (Blueprint $table): void {
            $table->dropIndex('idx_virtual_positions_signal_opened');
            $table->dropIndex('idx_virtual_positions_account_closed');
            $table->dropIndex('idx_virtual_positions_account_r_multiple');
        });

        Schema::table('equity_snapshots', function (Blueprint $table): void {
            $table->dropIndex('idx_equity_snapshots_account_recorded');
        });

        Schema::table('trading_journal_events', function (Blueprint $table): void {
            $table->dropIndex('idx_trading_journal_events_signal_occurred');
        });
    }
};
