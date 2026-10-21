<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A0-A3 — research-owned storage for the autonomous paper-research packages.
 *
 * The tables hold research artefacts only. They never reference the paper
 * ledger, they never change a risk budget and they never carry an order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_candidate_hypotheses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('hypothesis_id', 64);
            $table->string('version', 32);
            $table->string('family', 64);
            $table->string('kind', 32);
            $table->string('state', 32)->default('REGISTERED');
            $table->string('registration_hash', 64);
            $table->timestampTz('preregistered_at', precision: 3);
            $table->json('payload');
            $table->timestampsTz(precision: 3);

            $table->unique(['hypothesis_id', 'version'], 'research_candidate_hypothesis_identity_unique');
        });

        Schema::create('research_challenger_experiments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('experiment_id');
            $table->string('hypothesis_id', 64);
            $table->string('cohort_id', 64);
            $table->string('state', 48);
            $table->string('previous_state', 48)->nullable();
            $table->string('reason', 160);
            $table->string('preregistration_hash', 64)->nullable();
            $table->string('rules_hash', 64)->nullable();
            $table->json('evidence')->nullable();
            $table->timestampTz('recorded_at', precision: 3);
            $table->timestampsTz(precision: 3);

            $table->index(['experiment_id'], 'research_challenger_experiment_idx');
            $table->index(['state'], 'research_challenger_state_idx');
        });

        Schema::create('research_promotion_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('experiment_id')->nullable();
            $table->string('hypothesis_id', 64)->nullable();
            $table->string('verdict', 32);
            $table->string('reason', 160);
            $table->json('failed_gates');
            $table->boolean('feature_flag_enabled')->default(false);
            $table->boolean('applied')->default(false);
            $table->json('evidence');
            $table->timestampTz('decided_at', precision: 3);
            $table->timestampsTz(precision: 3);

            $table->index(['verdict'], 'research_promotion_verdict_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_promotion_decisions');
        Schema::dropIfExists('research_challenger_experiments');
        Schema::dropIfExists('research_candidate_hypotheses');
    }
};
