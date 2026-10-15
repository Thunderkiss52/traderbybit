<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_experiments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('strategy_id', 64);
            $table->string('strategy_version', 32);
            $table->string('configuration_hash', 64);
            $table->json('parameters');
            $table->json('selected_parameters')->nullable();
            $table->json('parameter_stability')->nullable();
            $table->json('symbols');
            $table->timestampTz('from_at', 3);
            $table->timestampTz('to_at', 3);
            $table->string('evaluation_mode', 24);
            $table->string('status', 24);
            $table->uuid('backtest_run_id')->nullable();
            $table->json('metrics')->nullable();
            $table->json('warnings')->nullable();
            $table->json('resource_budget');
            $table->unsignedInteger('tested_combinations')->default(0);
            $table->timestampTz('started_at', 3)->nullable();
            $table->timestampTz('completed_at', 3)->nullable();
            $table->timestampsTz(3);
            $table->foreign('backtest_run_id')->references('id')->on('backtest_runs')->nullOnDelete();
            $table->index(['status', 'created_at', 'id']);
            $table->index(['strategy_id', 'created_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_experiments');
    }
};
