<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('counterfactual_evaluations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('signal_id')->nullable()->index();
            $table->string('strategy_id', 64)->index();
            $table->string('strategy_version', 32);
            $table->string('symbol', 32)->index();
            $table->string('timeframe', 8)->default('15');
            $table->timestampTz('decision_time', precision: 3)->index();
            $table->string('skip_reason', 64)->index();
            $table->string('filter_family', 32)->index();
            $table->string('candidate_direction', 8);
            $table->decimal('hypothetical_entry', 30, 12);
            $table->decimal('hypothetical_sl', 30, 12);
            $table->decimal('hypothetical_tp', 30, 12);
            $table->string('status', 24)->default('PENDING_MATURITY')->index();
            $table->decimal('mfe', 30, 12)->nullable();
            $table->decimal('mae', 30, 12)->nullable();
            $table->decimal('r_multiple', 16, 8)->nullable();
            $table->decimal('net_return_percent', 16, 8)->nullable();
            $table->timestampTz('resolved_at', precision: 3)->nullable()->index();
            $table->json('decision_snapshot');
            $table->timestampsTz(precision: 3);

            $table->index(['strategy_id', 'skip_reason', 'decision_time'], 'counterfactual_filter_lookup');
        });

        Schema::create('decision_replay_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('evaluation_id', 64)->index();
            $table->string('mode', 24)->default('AS_OF_DECISION');
            $table->string('status', 24)->default('COMPLETED');
            $table->timestampTz('as_of_time', precision: 3);
            $table->json('recomputed_decision');
            $table->json('stored_decision');
            $table->json('diff');
            $table->boolean('is_reproducible')->default(true);
            $table->timestampsTz(precision: 3);
        });

        Schema::table('research_experiments', function (Blueprint $table): void {
            $table->boolean('is_shadow')->default(false)->index();
            $table->string('shadow_status', 24)->default('DRAFT')->index();
            $table->text('hypothesis')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('research_experiments', function (Blueprint $table): void {
            $table->dropColumn(['hypothesis', 'shadow_status', 'is_shadow']);
        });
        Schema::dropIfExists('decision_replay_runs');
        Schema::dropIfExists('counterfactual_evaluations');
    }
};
