<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historical_research_runs', function (Blueprint $t): void {
            $t->string('id', 64)->primary();
            $t->uuid('batch_id');
            $t->string('dataset_pin', 64);
            $t->string('strategy_id', 64);
            $t->string('strategy_version', 32);
            $t->string('configuration_hash', 64);
            $t->string('engine_checksum', 64);
            $t->jsonb('config');
            $t->string('status', 32)->default('QUEUED');
            $t->timestampTz('next_decision_at', 3);
            $t->uuid('lease_owner')->nullable();
            $t->unsignedBigInteger('fence')->default(0);
            $t->timestampTz('lease_until')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->jsonb('skip_counts')->nullable();
            $t->jsonb('result')->nullable();
            $t->string('error_code', 100)->nullable();
            $t->timestampsTz();
            $t->index(['batch_id', 'status']);
        });
        Schema::create('historical_research_signals', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->string('run_id', 64);
            $t->timestampTz('decision_at', 3);
            $t->jsonb('signal');
            $t->jsonb('features');
            $t->string('regime', 80)->default('UNCLASSIFIED');
            $t->unique(['run_id', 'decision_at']);
        });
        Schema::create('historical_research_trades', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->string('run_id', 64);
            $t->unsignedInteger('trade_number');
            $t->timestampTz('entry_at', 3);
            $t->timestampTz('exit_at', 3);
            $t->jsonb('trade');
            $t->jsonb('features');
            $t->string('regime', 80)->default('UNCLASSIFIED');
            $t->unique(['run_id', 'trade_number']);
            $t->index(['run_id', 'entry_at']);
        });
        Schema::create('historical_research_equity', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->string('run_id', 64);
            $t->timestampTz('at', 3);
            $t->decimal('equity', 30, 12);
            $t->decimal('drawdown_percent', 20, 8);
            $t->unique(['run_id', 'at']);
        });
        Schema::create('historical_research_executions', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->string('run_id', 64);
            $t->unsignedInteger('sequence');
            $t->jsonb('execution');
            $t->unique(['run_id', 'sequence']);
        });
    }

    public function down(): void
    {
        foreach (['historical_research_executions', 'historical_research_equity', 'historical_research_trades', 'historical_research_signals', 'historical_research_runs'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
