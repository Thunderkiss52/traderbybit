<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategy_health_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('strategy_id', 64)->index();
            $table->string('strategy_version', 32);
            $table->string('symbol', 32)->nullable()->index();
            $table->string('period', 16)->default('24h');
            $table->string('status', 24)->index();
            $table->json('funnel_metrics');
            $table->json('diagnostics');
            $table->timestampTz('snapshot_at', precision: 3)->index();
            $table->timestampsTz(precision: 3);

            $table->index(['strategy_id', 'period', 'snapshot_at'], 'strategy_health_lookup');
        });

        Schema::create('reconciliation_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('paper_account_id')->index();
            $table->string('status', 24)->index();
            $table->decimal('starting_balance', 36, 12);
            $table->decimal('recalculated_balance', 36, 12);
            $table->decimal('stored_balance', 36, 12);
            $table->decimal('recalculated_equity', 36, 12);
            $table->decimal('stored_equity', 36, 12);
            $table->decimal('recalculated_realized_pnl', 36, 12);
            $table->decimal('recalculated_unrealized_pnl', 36, 12);
            $table->decimal('recalculated_fees', 36, 12);
            $table->decimal('recalculated_funding', 36, 12);
            $table->decimal('recalculated_drawdown_percent', 16, 8);
            $table->decimal('stored_drawdown_percent', 16, 8);
            $table->unsignedInteger('discrepancy_count')->default(0);
            $table->json('findings');
            $table->string('methodology_version', 32)->default('v1.0.0');
            $table->timestampTz('completed_at', precision: 3)->index();
            $table->timestampsTz(precision: 3);

            $table->index(['paper_account_id', 'completed_at'], 'reconciliation_account_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_runs');
        Schema::dropIfExists('strategy_health_snapshots');
    }
};
