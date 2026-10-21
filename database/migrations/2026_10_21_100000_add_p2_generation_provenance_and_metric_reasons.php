<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROFIT-1 P-2 (Rev 2.1 §6.4, §8.2, §8.4) — additive schema completion.
 *
 * Adds the immutable-generation provenance fields required by §6.4 and the
 * explicit "NULL plus a reason" fields required by §6.2 / §8.2.
 * Additive only: no column is dropped or retyped, so existing rows stay valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('research_generations', function (Blueprint $table): void {
            $table->string('config_hash', 64)->nullable()->after('request_payload_hash');
            $table->string('metric_schema_version', 32)->nullable()->after('config_hash');
            $table->string('algorithm_version', 32)->nullable()->after('metric_schema_version');
            $table->json('coverage_manifest')->nullable()->after('metadata');
            $table->unsignedInteger('missing_data_count')->nullable()->after('coverage_manifest');
            $table->string('failure_code', 64)->nullable()->after('error_message');
            $table->timestampTz('completed_at', precision: 3)->nullable()->after('stale_after_seconds');
        });

        Schema::table('strategy_risk_metrics', function (Blueprint $table): void {
            $table->json('exclusion_reasons')->nullable()->after('exclusions_count');
            $table->string('net_r_reason', 64)->nullable()->after('net_r_median');
            $table->string('cost_to_risk_reason', 128)->nullable()->after('cost_to_risk_ratio');
            $table->string('cost_to_risk_provenance', 64)->nullable()->after('cost_to_risk_reason');
            $table->string('payoff_ratio_reason', 64)->nullable()->after('payoff_ratio');
            $table->string('win_rate_reason', 64)->nullable()->after('win_rate');
            $table->string('sharpe_like_reason', 96)->nullable()->after('sharpe_like');
            $table->string('notional_basis', 64)->nullable()->after('average_margin_reason');
            $table->unsignedInteger('equity_observations')->nullable()->after('coverage_gap_seconds');
            $table->json('position_population')->nullable()->after('sample_sufficiency_reasons');
        });
    }

    public function down(): void
    {
        Schema::table('strategy_risk_metrics', function (Blueprint $table): void {
            $table->dropColumn([
                'exclusion_reasons', 'net_r_reason', 'cost_to_risk_reason', 'cost_to_risk_provenance',
                'payoff_ratio_reason', 'win_rate_reason', 'sharpe_like_reason', 'notional_basis',
                'equity_observations', 'position_population',
            ]);
        });

        Schema::table('research_generations', function (Blueprint $table): void {
            $table->dropColumn([
                'config_hash', 'metric_schema_version', 'algorithm_version',
                'coverage_manifest', 'missing_data_count', 'failure_code', 'completed_at',
            ]);
        });
    }
};
