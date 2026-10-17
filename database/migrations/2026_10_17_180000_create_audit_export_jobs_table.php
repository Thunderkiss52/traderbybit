<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_export_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('cache_key', 64)->unique();
            $table->unsignedTinyInteger('period_days');
            $table->timestampTz('from_at', 3);
            $table->timestampTz('to_at', 3);
            $table->timestampTz('as_of', 3);
            $table->string('status', 16)->default('QUEUED');
            $table->unsignedInteger('progress_percent')->default(0);
            $table->json('row_counts')->nullable();
            $table->unsignedBigInteger('archive_bytes')->nullable();
            $table->string('archive_sha256', 64)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestampTz('finished_at', 3)->nullable();
            $table->timestampTz('expires_at', 3)->nullable();
            $table->timestampsTz(3);
            $table->index(['created_at', 'status']);
            $table->index(['expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_export_jobs');
    }
};
