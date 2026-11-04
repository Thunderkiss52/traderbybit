<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backtest_audit_jobs', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('backtest_run_id')->unique();
            $table->string('status', 24)->default('QUEUED');
            $table->json('row_counts')->nullable();
            $table->string('archive_sha256', 64)->nullable();
            $table->unsignedBigInteger('archive_bytes')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->timestampTz('expires_at', 3)->nullable();
            $table->timestampsTz(3);
            $table->foreign('backtest_run_id')->references('id')->on('backtest_runs');
        });
    }

    public function down(): void
    {
        // Retain audit metadata; an automatic rollback cannot remove evidence.
        throw new LogicException('Backtest audit metadata cannot be removed automatically.');
    }
};
