<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historical_download_batches', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('fingerprint', 64);
            $t->string('status', 40)->default('PLANNED');
            $t->jsonb('plan');
            $t->jsonb('license');
            $t->string('intended_use', 48);
            $t->string('acknowledgement', 160)->nullable();
            $t->timestampTz('acknowledged_at')->nullable();
            $t->jsonb('quality')->nullable();
            $t->timestampsTz();
        });
        Schema::create('historical_download_entries', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('batch_id');
            $t->foreign('batch_id')->references('id')->on('historical_download_batches');
            $t->uuid('parent_id')->nullable();
            $t->string('idempotency_key', 64)->unique();
            $t->string('family', 32);
            $t->string('symbol', 24);
            $t->string('interval', 8)->nullable();
            $t->string('period', 10);
            $t->string('granularity', 16);
            $t->text('url');
            $t->text('checksum_url')->nullable();
            $t->string('filename', 160);
            $t->timestampTz('source_from', 3);
            $t->timestampTz('source_to', 3);
            $t->timestampTz('requested_from', 3);
            $t->timestampTz('requested_to', 3);
            $t->string('status', 40)->default('PLANNED');
            $t->string('download_status', 32)->default('PENDING');
            $t->string('verification_status', 32)->default('PENDING');
            $t->string('import_status', 32)->default('PENDING');
            $t->unsignedInteger('attempts')->default(0);
            $t->uuid('lease_owner')->nullable();
            $t->unsignedBigInteger('fence')->default(0);
            $t->timestampTz('lease_until')->nullable();
            $t->timestampTz('available_at')->nullable();
            $t->string('sha256', 64)->nullable();
            $t->unsignedBigInteger('download_bytes')->default(0);
            $t->unsignedBigInteger('parsed_rows')->default(0);
            $t->unsignedBigInteger('checkpoint')->default(0);
            $t->unsignedBigInteger('imported_rows')->default(0);
            $t->unsignedBigInteger('duplicate_rows')->default(0);
            $t->unsignedBigInteger('conflict_rows')->default(0);
            $t->jsonb('quality')->nullable();
            $t->string('error_code', 80)->nullable();
            $t->jsonb('diagnostics')->nullable();
            $t->timestampTz('downloaded_at')->nullable();
            $t->timestampTz('verified_at')->nullable();
            $t->timestampTz('imported_at')->nullable();
            $t->timestampsTz();
            $t->index(['batch_id', 'status']);
            $t->index(['status', 'available_at']);
        });
        Schema::create('historical_source_revisions', function (Blueprint $t): void {
            $t->string('id', 64)->primary();
            $t->uuid('entry_id');
            $t->text('uri');
            $t->string('sha256', 64);
            $t->string('family', 32);
            $t->string('provider', 40)->default('BINANCE_VISION');
            $t->jsonb('license');
            $t->string('parser_version', 40);
            $t->string('validation_version', 40);
            $t->jsonb('metrics');
            $t->timestampTz('acquired_at');
            $t->unique(['entry_id', 'sha256']);
            $t->index('sha256');
        });
        Schema::create('historical_download_attempts', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->uuid('entry_id');
            $t->unsignedInteger('attempt');
            $t->unsignedBigInteger('fence');
            $t->string('status', 40);
            $t->string('error_code', 80)->nullable();
            $t->jsonb('details')->nullable();
            $t->timestampsTz();
            $t->unique(['entry_id', 'attempt']);
        });
        Schema::create('historical_acquisition_events', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->uuid('batch_id');
            $t->string('action', 64);
            $t->string('actor', 80);
            $t->jsonb('details');
            $t->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        foreach (['historical_acquisition_events', 'historical_download_attempts', 'historical_source_revisions', 'historical_download_entries', 'historical_download_batches'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
