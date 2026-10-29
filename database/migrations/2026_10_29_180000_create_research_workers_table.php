<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_workers', function (Blueprint $table) {
            $table->string('worker_id', 128)->primary();
            $table->string('engine', 32)->index(); // 'go', 'php', 'python'
            $table->string('version', 32)->default('1.0.0');
            $table->string('status', 32)->default('ready')->index(); // 'ready', 'busy', 'stopped', 'offline', 'unsupported_version'
            $table->string('hostname', 128)->nullable();
            $table->integer('pid')->nullable();
            $table->json('supported_algorithms')->nullable();
            $table->timestampTz('heartbeat_at', precision: 3)->index();
            $table->timestampTz('registered_at', precision: 3);
            $table->string('last_job_id', 64)->nullable();
            $table->timestampTz('last_job_finished_at', precision: 3)->nullable();
            $table->unsignedInteger('total_jobs_completed')->default(0);
            $table->unsignedInteger('total_jobs_failed')->default(0);
            $table->timestampsTz(precision: 3);

            $table->index(['engine', 'status']);
            $table->index(['engine', 'heartbeat_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_workers');
    }
};
