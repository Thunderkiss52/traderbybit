<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qim_evidence_files', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('file_uuid', 36)->unique();
            $table->string('original_filename');
            $table->string('storage_path');
            $table->string('mime_type', 64);
            $table->unsignedBigInteger('file_size_bytes');
            $table->string('sha256_hash', 64);
            $table->string('provenance_class', 32);
            $table->string('declared_source', 64);
            $table->string('declared_timezone', 32);
            $table->json('missing_metadata')->nullable();
            $table->string('investigation_status', 32)->default('INSUFFICIENT_EVIDENCE');
            $table->string('uploaded_by', 64)->default('operator');
            $table->timestampTz('uploaded_at', 3)->useCurrent();
            $table->timestampTz('created_at', 3)->useCurrent();
            $table->timestampTz('updated_at', 3)->useCurrent();

            $table->index('file_uuid', 'qim_ev_uuid_idx');
            $table->index('sha256_hash', 'qim_ev_sha_idx');
            $table->index('uploaded_at', 'qim_ev_uploaded_idx');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("
                DO $$
                BEGIN
                    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'bybit_signals') THEN
                        GRANT SELECT, INSERT, UPDATE (investigation_status, updated_at) ON qim_evidence_files TO bybit_signals;
                        REVOKE DELETE ON qim_evidence_files FROM bybit_signals;
                    END IF;
                END $$;
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('qim_evidence_files');
    }
};
