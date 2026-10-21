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
        Schema::create('qim_gap_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('detection_id', 36);
            $table->string('event_type', 24); // DETECTED, RECOVERED, RECOVERY_FAILED
            $table->string('provider', 32)->default('bybit');
            $table->string('channel', 16)->default('WS');
            $table->string('category', 32)->default('linear');
            $table->string('symbol', 32);
            $table->string('interval', 16);
            $table->timestampTz('gap_from', 3);
            $table->timestampTz('gap_to', 3);
            $table->timestampTz('detected_at', 3);
            $table->timestampTz('resolved_at', 3)->nullable();
            $table->string('outcome', 32)->nullable();
            $table->unsignedInteger('remaining_missing_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestampTz('created_at', 3)->useCurrent();

            $table->index('detection_id', 'qim_gap_detection_id_idx');
            $table->index(['symbol', 'interval', 'detected_at'], 'qim_gap_sym_int_det_idx');
            $table->index('event_type', 'qim_gap_event_type_idx');
            $table->index('created_at', 'qim_gap_created_at_idx');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("
                DO $$
                BEGIN
                    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'bybit_signals') THEN
                        GRANT SELECT, INSERT ON qim_gap_events TO bybit_signals;
                        REVOKE UPDATE, DELETE ON qim_gap_events FROM bybit_signals;
                    END IF;
                END $$;
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('qim_gap_events');
    }
};
