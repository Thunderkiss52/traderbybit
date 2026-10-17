<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategy_degradation_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('strategy_id', 64)->index();
            $table->string('strategy_version', 32);
            $table->string('symbol', 32)->nullable()->index();
            $table->string('horizon', 16)->default('14d'); // 7d, 14d, 30d
            $table->unsignedInteger('sample_size')->default(0);
            $table->decimal('expectancy_r', 16, 8)->nullable();
            $table->decimal('profit_factor', 16, 8)->nullable();
            $table->decimal('win_rate', 8, 4)->nullable();
            $table->decimal('win_rate_ci_lower', 8, 4)->nullable();
            $table->decimal('win_rate_ci_upper', 8, 4)->nullable();
            $table->decimal('max_drawdown_percent', 8, 4)->nullable();
            $table->string('status', 32)->default('STABLE')->index(); // STABLE, WATCH, DEGRADING, CRITICAL_DRAWDOWN, INSUFFICIENT_DATA
            $table->string('degradation_type', 32)->default('NONE')->index(); // NONE, REGIME_MISMATCH, POSSIBLE_ALPHA_DECAY
            $table->json('diagnostics');
            $table->timestampTz('as_of_time', precision: 3)->index();
            $table->timestampsTz(precision: 3);

            $table->index(['strategy_id', 'horizon', 'as_of_time'], 'strategy_degradation_lookup');
        });

        Schema::create('portfolio_correlation_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('horizon', 16)->default('14d');
            $table->json('pair_matrix');
            $table->json('concurrent_overlap');
            $table->decimal('concentration_score', 8, 4)->default(0); // Normalized HHI [0, 1]
            $table->json('alerts');
            $table->timestampTz('evaluated_at', precision: 3)->index();
            $table->timestampsTz(precision: 3);
        });

        Schema::create('research_recommendations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('category', 32)->index(); // RISK_MITIGATION, ALPHA_IMPROVEMENT, PORTFOLIO_DIVERSIFICATION, EXPERIMENT_PROMOTION
            $table->string('strategy_id', 64)->nullable()->index();
            $table->string('title', 128);
            $table->text('description');
            $table->json('evidence');
            $table->json('proposed_changes')->nullable();
            $table->string('status', 24)->default('OPEN')->index(); // OPEN, ACCEPTED, DISMISSED
            $table->timestampsTz(precision: 3);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_recommendations');
        Schema::dropIfExists('portfolio_correlation_snapshots');
        Schema::dropIfExists('strategy_degradation_snapshots');
    }
};
