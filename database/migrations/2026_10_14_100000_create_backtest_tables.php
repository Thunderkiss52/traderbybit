<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backtest_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('fingerprint', 64)->unique();
            $table->string('type', 24);
            $table->string('status', 24);
            $table->timestampTz('from_at', 3);
            $table->timestampTz('to_at', 3);
            $table->json('symbols');
            $table->json('strategy_config');
            $table->json('risk_config');
            $table->json('execution_config');
            $table->string('strategy_version', 32);
            $table->string('risk_version', 32);
            $table->string('execution_version', 32);
            $table->string('backtest_version', 32);
            $table->string('schema_version', 32);
            $table->string('dataset_checksum', 64);
            $table->unsignedBigInteger('seed')->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->json('warnings')->nullable();
            $table->json('errors')->nullable();
            $table->json('metrics')->nullable();
            $table->timestampTz('started_at', 3)->nullable();
            $table->timestampTz('completed_at', 3)->nullable();
            $table->timestampsTz(3);
        });

        Schema::create('backtest_segments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('backtest_run_id');
            $table->unsignedInteger('window_index')->default(0);
            $table->string('kind', 24);
            $table->timestampTz('from_at', 3);
            $table->timestampTz('to_at', 3);
            $table->json('parameters');
            $table->json('metrics')->nullable();
            $table->json('warnings')->nullable();
            $table->json('decisions')->nullable();
            $table->string('status', 24);
            $table->timestampsTz(3);
            $table->foreign('backtest_run_id')->references('id')->on('backtest_runs')->cascadeOnDelete();
            $table->unique(['backtest_run_id', 'window_index', 'kind']);
        });

        Schema::create('backtest_trades', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('backtest_run_id');
            $table->uuid('backtest_segment_id');
            $table->string('identity_key', 64);
            $table->string('symbol', 32);
            $table->string('side', 8);
            $table->string('quality_class', 1)->nullable();
            $table->string('strategy_version', 32);
            $table->timestampTz('signal_at', 3);
            $table->timestampTz('opened_at', 3);
            $table->timestampTz('closed_at', 3);
            $table->decimal('entry_price', 30, 12);
            $table->decimal('exit_price', 30, 12);
            $table->decimal('quantity', 30, 12);
            $table->decimal('gross_pnl', 36, 12);
            $table->decimal('fees', 36, 12);
            $table->decimal('funding', 36, 12);
            $table->decimal('net_pnl', 36, 12);
            $table->decimal('r_multiple', 20, 10)->nullable();
            $table->unsignedInteger('holding_seconds');
            $table->string('close_reason', 32);
            $table->boolean('ambiguous_execution')->default(false);
            $table->boolean('funding_complete')->default(true);
            $table->json('executions');
            $table->timestampsTz(3);
            $table->foreign('backtest_run_id')->references('id')->on('backtest_runs')->cascadeOnDelete();
            $table->foreign('backtest_segment_id')->references('id')->on('backtest_segments')->cascadeOnDelete();
            $table->unique(['backtest_run_id', 'identity_key']);
        });

        Schema::create('backtest_equity_snapshots', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('backtest_run_id');
            $table->uuid('backtest_segment_id');
            $table->timestampTz('recorded_at', 3);
            $table->decimal('equity', 36, 12);
            $table->decimal('realized_pnl', 36, 12);
            $table->decimal('unrealized_pnl', 36, 12);
            $table->decimal('drawdown_percent', 16, 8);
            $table->boolean('exposed');
            $table->timestampsTz(3);
            $table->foreign('backtest_run_id')->references('id')->on('backtest_runs')->cascadeOnDelete();
            $table->foreign('backtest_segment_id')->references('id')->on('backtest_segments')->cascadeOnDelete();
            $table->unique(['backtest_segment_id', 'recorded_at']);
        });

        Schema::create('backtest_optimizations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('backtest_run_id');
            $table->unsignedInteger('window_index')->default(0);
            $table->unsignedInteger('combination_index');
            $table->json('parameters');
            $table->json('train_metrics');
            $table->json('validation_metrics');
            $table->decimal('objective_score', 36, 12);
            $table->boolean('selected')->default(false);
            $table->timestampsTz(3);
            $table->foreign('backtest_run_id')->references('id')->on('backtest_runs')->cascadeOnDelete();
            $table->unique(['backtest_run_id', 'window_index', 'combination_index'], 'backtest_optimization_unique');
        });

        Schema::create('backtest_reports', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('backtest_run_id');
            $table->string('kind', 40);
            $table->json('payload');
            $table->timestampsTz(3);
            $table->foreign('backtest_run_id')->references('id')->on('backtest_runs')->cascadeOnDelete();
            $table->unique(['backtest_run_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backtest_reports');
        Schema::dropIfExists('backtest_optimizations');
        Schema::dropIfExists('backtest_equity_snapshots');
        Schema::dropIfExists('backtest_trades');
        Schema::dropIfExists('backtest_segments');
        Schema::dropIfExists('backtest_runs');
    }
};
