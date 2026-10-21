<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategy_risk_metrics', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('generation_id')->index();
            $table->string('dimension_key', 64)->index();
            $table->uuid('paper_account_id')->index();
            $table->string('strategy_id', 64);
            $table->string('symbol', 32)->default('ALL');
            $table->string('accounting_scope', 32);
            $table->timestampTz('as_of', precision: 3);
            $table->timestampTz('window_start', precision: 3)->nullable();
            $table->timestampTz('window_end', precision: 3)->nullable();
            $table->string('metric_schema_version', 16)->default('1.0.0');

            // Sample counts and exclusions
            $table->unsignedInteger('n_positions')->default(0);
            $table->unsignedInteger('n_wins')->default(0);
            $table->unsignedInteger('n_losses')->default(0);
            $table->unsignedInteger('n_flat')->default(0);
            $table->unsignedInteger('n_days')->default(0);
            $table->unsignedInteger('exclusions_count')->default(0);

            // Risk-adjusted return metrics
            $table->decimal('net_r_mean', 20, 8)->nullable();
            $table->decimal('net_r_median', 20, 8)->nullable();
            $table->decimal('cost_to_risk_ratio', 20, 8)->nullable();
            $table->decimal('profit_factor', 20, 8)->nullable();
            $table->string('profit_factor_reason', 64)->nullable();
            $table->decimal('win_rate', 10, 6)->nullable();
            $table->decimal('payoff_ratio', 20, 8)->nullable();

            // Drawdown and equity metrics
            $table->decimal('max_drawdown_pct', 16, 8)->nullable();
            $table->decimal('time_underwater_pct', 10, 6)->nullable();
            $table->decimal('twa_equity', 36, 12)->nullable();
            $table->unsignedInteger('max_carry_forward_age_seconds')->default(86400);
            $table->unsignedInteger('coverage_gap_seconds')->default(0);

            // Capital efficiency metrics
            $table->decimal('turnover', 20, 8)->nullable();
            $table->decimal('net_return_on_equity', 20, 8)->nullable();
            $table->decimal('net_pnl_per_risk_capital', 20, 8)->nullable();
            $table->decimal('average_gross_notional_exposure_pct', 16, 8)->nullable();
            $table->decimal('average_open_risk_capital_pct', 16, 8)->nullable();
            $table->decimal('time_with_open_exposure_pct', 10, 6)->nullable();
            $table->decimal('notional_per_initial_risk_capital', 20, 8)->nullable();
            $table->decimal('average_margin_used_pct_of_equity', 16, 8)->nullable();
            $table->string('average_margin_reason', 64)->nullable();

            // Time-series & statistical sufficiency
            $table->decimal('sharpe_like', 20, 8)->nullable();
            $table->decimal('ess', 16, 6)->nullable();
            $table->boolean('ess_clamped')->default(false);
            $table->string('ess_method', 64)->default('BARTLETT_AUTOCORRELATION_LAG_FLOOR_N_DIV_4');
            $table->string('sample_sufficiency', 32)->default('INSUFFICIENT_DATA');
            $table->json('sample_sufficiency_reasons')->nullable();

            $table->string('provenance', 32)->default('RECOMPUTED_FROM_SOURCE');
            $table->timestampsTz(precision: 3);

            $table->unique(['generation_id', 'dimension_key'], 'uq_strategy_risk_metrics_gen_dim');
            $table->index(['strategy_id', 'accounting_scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategy_risk_metrics');
    }
};
