<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategy_optimization_experiments', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->foreignId('definition_id')->constrained('strategy_definitions');
            $table->string('definition_checksum', 64);
            $table->string('engine_checksum', 64);
            $table->jsonb('scope');
            $table->jsonb('options');
            $table->string('baseline_run_id', 64);
            $table->string('status', 32)->default('QUEUED');
            $table->unsignedInteger('attempts')->default(0);
            $table->jsonb('results')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('lease_until')->nullable();
            $table->timestampsTz();
        });
        Schema::create('strategy_optimization_candidates', function (Blueprint $table): void {
            $table->id();
            $table->string('experiment_id', 64);
            $table->foreign('experiment_id')->references('id')->on('strategy_optimization_experiments');
            $table->foreignId('definition_id')->constrained('strategy_definitions');
            $table->jsonb('parameters');
            $table->jsonb('scorecard');
            $table->string('status', 32)->default('EXPERIMENTAL');
            $table->unique(['experiment_id', 'definition_id']);
        });
        Schema::create('strategy_shadow_subscriptions', function (Blueprint $table): void {
            $table->foreignId('definition_id')->primary()->constrained('strategy_definitions');
            $table->boolean('enabled')->default(false);
            $table->timestampTz('from_at');
            $table->timestampsTz();
        });
        Schema::create('strategy_shadow_evaluations', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->foreignId('definition_id')->constrained('strategy_definitions');
            $table->string('exchange', 32);
            $table->string('symbol', 24);
            $table->timestampTz('decision_at');
            $table->string('status', 32);
            $table->jsonb('result');
            $table->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategy_shadow_evaluations');
        Schema::dropIfExists('strategy_shadow_subscriptions');
        Schema::dropIfExists('strategy_optimization_candidates');
        Schema::dropIfExists('strategy_optimization_experiments');
    }
};
