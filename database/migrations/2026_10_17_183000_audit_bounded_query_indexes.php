<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('virtual_executions', fn (Blueprint $table) => $table->index(['market_event_at', 'id'], 'virtual_executions_audit_time'));
        Schema::table('equity_snapshots', fn (Blueprint $table) => $table->index(['recorded_at', 'id'], 'equity_snapshots_audit_time'));
        Schema::table('paper_signal_states', fn (Blueprint $table) => $table->index(['processed_at', 'id'], 'paper_signal_states_audit_time'));
        Schema::table('virtual_positions', fn (Blueprint $table) => $table->index(['opened_at', 'id'], 'virtual_positions_audit_time'));
    }

    public function down(): void
    {
        Schema::table('virtual_positions', fn (Blueprint $table) => $table->dropIndex('virtual_positions_audit_time'));
        Schema::table('paper_signal_states', fn (Blueprint $table) => $table->dropIndex('paper_signal_states_audit_time'));
        Schema::table('equity_snapshots', fn (Blueprint $table) => $table->dropIndex('equity_snapshots_audit_time'));
        Schema::table('virtual_executions', fn (Blueprint $table) => $table->dropIndex('virtual_executions_audit_time'));
    }
};
