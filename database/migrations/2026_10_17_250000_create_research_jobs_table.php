<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('job_id')->unique();
            $table->unsignedInteger('schema_version')->default(1);
            $table->string('idempotency_key', 64)->index();
            $table->string('kind', 32)->index(); // BACKTEST, WALK_FORWARD, PATTERN_MINE, REPLAY, SKIP_OUTCOMES, MONTE_CARLO, FEATURE_BUILD, MODEL_TRAIN
            $table->string('status', 32)->default('QUEUED')->index(); // QUEUED, CLAIMED, RUNNING, SUCCEEDED, FAILED, CANCELLED, EXPIRED, RETRY_WAIT
            $table->string('algorithm_version', 64)->default('1.0.0');
            $table->string('code_revision', 64)->default('main');
            $table->string('strategy_id', 64)->nullable()->index();
            $table->string('strategy_version', 64)->nullable();
            $table->string('configuration_hash', 64)->nullable();
            $table->string('model_version', 64)->nullable();
            $table->uuid('dataset_id')->nullable();
            $table->string('dataset_sha256', 64)->nullable();
            $table->timestampTz('as_of_utc', precision: 3)->nullable();
            $table->timestampTz('training_cutoff_utc', precision: 3)->nullable();
            $table->string('research_mode', 32)->default('BACKTEST');
            $table->bigInteger('random_seed')->default(12345);
            $table->json('limits')->nullable(); // max_rows, max_duration_s, max_memory_mib
            $table->json('payload')->nullable(); // Specific job parameters
            $table->string('created_by', 64)->default('system');
            $table->timestampTz('requested_at_utc', precision: 3);
            $table->string('lease_owner', 128)->nullable();
            $table->timestampTz('lease_expires_at', precision: 3)->nullable()->index();
            $table->timestampTz('heartbeat_at', precision: 3)->nullable();
            $table->bigInteger('fencing_token')->default(1);
            $table->unsignedSmallInteger('progress')->default(0);
            $table->string('progress_message', 255)->nullable();
            $table->string('result_manifest_sha256', 64)->nullable();
            $table->json('result_payload')->nullable();
            $table->json('runtime_stats')->nullable(); // duration_ms, memory_bytes, peak_rss, cpu_time_ms, rows_processed
            $table->text('sanitized_error')->nullable();
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->unsignedSmallInteger('max_retries')->default(3);
            $table->timestampTz('claimed_at', precision: 3)->nullable();
            $table->timestampTz('started_at', precision: 3)->nullable();
            $table->timestampTz('completed_at', precision: 3)->nullable();
            $table->timestampsTz(precision: 3);

            $table->index(['kind', 'idempotency_key']);
            $table->index(['status', 'requested_at_utc']);
            $table->index(['strategy_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_jobs');
    }
};
