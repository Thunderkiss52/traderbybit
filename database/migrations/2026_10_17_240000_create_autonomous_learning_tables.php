<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('autonomy_settings', function (Blueprint $table): void {
            $table->string('id', 32)->primary();
            $table->json('capabilities');
            $table->boolean('kill_switch_active')->default(false)->index();
            $table->unsignedInteger('max_active_shadow_experiments')->default(4);
            $table->unsignedInteger('max_forward_paper_trials')->default(2);
            $table->unsignedInteger('max_promotions_per_week')->default(2);
            $table->unsignedInteger('min_shadow_observation_days')->default(7);
            $table->decimal('max_allowed_drawdown_percent', 8, 4)->default(12.0);
            $table->timestampsTz(precision: 3);
        });

        Schema::create('autonomy_approval_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('actor', 32)->index(); // SYSTEM_AUTONOMOUS, USER_OPERATOR
            $table->string('action', 64)->index(); // PERMISSION_CHANGE, KILL_SWITCH, CANDIDATE_PROMOTION, CANDIDATE_ROLLBACK
            $table->string('target_type', 64);
            $table->string('target_id', 64)->nullable()->index();
            $table->string('previous_state', 32)->nullable();
            $table->string('new_state', 32);
            $table->text('reason');
            $table->json('evidence')->nullable();
            $table->timestampTz('created_at', precision: 3)->index();
        });

        Schema::create('learning_feature_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('symbol', 32)->index();
            $table->string('timeframe', 8)->default('15');
            $table->timestampTz('decision_time', precision: 3)->index();
            $table->timestampTz('available_at', precision: 3)->index();
            $table->string('feature_schema_version', 16)->default('1.0.0');
            $table->string('strategy_id', 64)->nullable()->index();
            $table->string('regime', 32)->default('UNKNOWN')->index();
            $table->json('features');
            $table->string('label_status', 24)->default('PENDING')->index(); // PENDING, MATURED, AMBIGUOUS, INVALID
            $table->string('label_outcome', 32)->nullable()->index();
            $table->decimal('label_r_multiple', 16, 8)->nullable();
            $table->timestampTz('outcome_at', precision: 3)->nullable()->index();
            $table->json('data_quality');
            $table->timestampsTz(precision: 3);

            $table->index(['symbol', 'timeframe', 'decision_time'], 'feature_snapshot_lookup');
        });

        Schema::create('learning_candidate_strategies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 128);
            $table->string('parent_strategy_id', 64)->index();
            $table->string('base_version', 32);
            $table->string('candidate_version', 32);
            $table->string('configuration_hash', 64)->index();
            $table->text('hypothesis');
            $table->json('parameters');
            $table->json('parameter_diff');
            $table->json('search_metadata');
            $table->string('status', 32)->default('RESEARCH_ONLY')->index();
            // RESEARCH_ONLY, SHADOW_ELIGIBLE, PAPER_ELIGIBLE, PAPER_PROMOTION_PENDING, PAPER_PROMOTED, REJECTED, QUARANTINED, ROLLED_BACK, RETIRED
            $table->json('validation_gates')->nullable();
            $table->json('metrics')->nullable();
            $table->uuid('paper_account_id')->nullable()->index();
            $table->timestampTz('promoted_at', precision: 3)->nullable();
            $table->timestampTz('rolled_back_at', precision: 3)->nullable();
            $table->text('rollback_reason')->nullable();
            $table->timestampsTz(precision: 3);

            $table->index(['status', 'created_at'], 'candidate_status_lookup');
        });

        Schema::create('learning_model_artifacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 64);
            $table->string('model_type', 32); // LOGISTIC_REGRESSION, EMPIRICAL_COHORT, GRADIENT_BOOSTED_TREE
            $table->string('target_event', 32)->index(); // BOUNCE, BREAKOUT, TP_BEFORE_SL
            $table->string('version', 32);
            $table->json('parameters');
            $table->json('weights')->nullable();
            $table->json('metrics');
            $table->string('artifact_hash', 64)->index();
            $table->string('status', 24)->default('CANDIDATE')->index(); // ACTIVE, CANDIDATE, RETIRED
            $table->timestampsTz(precision: 3);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_model_artifacts');
        Schema::dropIfExists('learning_candidate_strategies');
        Schema::dropIfExists('learning_feature_snapshots');
        Schema::dropIfExists('autonomy_approval_events');
        Schema::dropIfExists('autonomy_settings');
    }
};
