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
        Schema::create('qim_observations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('provider', 32);
            $table->string('channel', 16); // REST, WS
            $table->string('category', 32);
            $table->string('symbol', 32);
            $table->string('interval', 16);
            $table->timestampTz('candle_open_time', 3);
            $table->timestampTz('candle_close_time', 3);
            $table->decimal('open', 20, 8);
            $table->decimal('high', 20, 8);
            $table->decimal('low', 20, 8);
            $table->decimal('close', 20, 8);
            $table->decimal('volume', 24, 8);
            $table->decimal('turnover', 24, 8)->nullable();
            $table->boolean('is_closed')->default(true);
            $table->timestampTz('request_sent_at', 3)->nullable();
            $table->timestampTz('response_received_at', 3)->nullable();
            $table->timestampTz('provider_event_time', 3)->nullable();
            $table->timestampTz('local_received_at', 3);
            $table->string('clock_sync_state', 32)->default('CLOCK_SYNC_UNKNOWN');
            $table->integer('clock_offset_estimate_ms')->nullable();
            $table->string('logical_key', 36);
            $table->string('capture_attempt_id', 36);
            $table->string('source_event_id')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->string('payload_sha256', 64);
            $table->longText('raw_payload');
            $table->string('quality_state', 32)->default('OK');
            $table->string('duplicate_classification', 32)->nullable();
            $table->string('normalization_rule_version', 16)->default('1.0.0');
            $table->timestampTz('created_at', 3)->useCurrent();

            $table->index(['category', 'symbol', 'interval', 'candle_open_time'], 'qim_obs_candle_idx');
            $table->index('logical_key', 'qim_obs_logical_key_idx');
            $table->index(['channel', 'local_received_at'], 'qim_obs_channel_rec_idx');
            $table->index('created_at', 'qim_obs_created_at_idx');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("
                DO $$
                BEGIN
                    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'bybit_signals') THEN
                        GRANT SELECT, INSERT ON qim_observations TO bybit_signals;
                        REVOKE UPDATE, DELETE ON qim_observations FROM bybit_signals;
                    END IF;
                END $$;
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('qim_observations');
    }
};
