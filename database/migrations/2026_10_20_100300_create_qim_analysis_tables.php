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
        Schema::create('qim_comparisons', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('comparison_id', 36);
            $table->unsignedInteger('version')->default(1);
            $table->string('provider', 32)->default('bybit');
            $table->string('category', 32)->default('linear');
            $table->string('symbol', 32);
            $table->string('interval', 16);
            $table->timestampTz('candle_open_time', 3);
            $table->timestampTz('candle_close_time', 3);
            $table->unsignedBigInteger('rest_observation_id')->nullable();
            $table->unsignedBigInteger('ws_observation_id')->nullable();
            $table->string('classification', 32);
            $table->decimal('price_diff_abs', 20, 8)->nullable();
            $table->decimal('price_diff_rel', 12, 6)->nullable();
            $table->decimal('volume_diff_abs', 24, 8)->nullable();
            $table->decimal('volume_diff_rel', 12, 6)->nullable();
            $table->bigInteger('timestamp_skew_ms')->nullable();
            $table->string('rule_version', 16)->default('1.0.0');
            $table->text('explanation')->nullable();
            $table->boolean('is_comparable')->default(true);
            $table->timestampTz('compared_at', 3)->useCurrent();
            $table->timestampTz('created_at', 3)->useCurrent();

            $table->index(['category', 'symbol', 'interval', 'candle_open_time'], 'qim_comp_candle_idx');
            $table->index('classification', 'qim_comp_classification_idx');
            $table->index('compared_at', 'qim_comp_compared_at_idx');
        });

        Schema::create('qim_incidents', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('incident_key', 64)->unique();
            $table->string('status', 24)->default('NEW');
            $table->string('severity', 16)->default('MEDIUM');
            $table->string('priority', 8)->default('P3');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('provider', 32)->default('bybit');
            $table->string('category', 32)->nullable();
            $table->string('symbol', 32)->nullable();
            $table->timestampTz('first_detected_at', 3);
            $table->timestampTz('last_detected_at', 3);
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->text('resolution_notes')->nullable();
            $table->timestampTz('created_at', 3)->useCurrent();
            $table->timestampTz('updated_at', 3)->useCurrent();

            $table->index('status', 'qim_inc_status_idx');
            $table->index(['symbol', 'status'], 'qim_inc_sym_status_idx');
            $table->index('first_detected_at', 'qim_inc_first_det_idx');
        });

        Schema::create('qim_incident_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('incident_id');
            $table->string('previous_status', 24)->nullable();
            $table->string('new_status', 24);
            $table->string('actor', 64);
            $table->text('explanation');
            $table->json('evidence_references')->nullable();
            $table->string('rule_version', 16)->default('1.0.0');
            $table->string('config_version', 16)->default('1.0.0');
            $table->timestampTz('created_at', 3)->useCurrent();

            $table->index('incident_id', 'qim_inc_ev_incident_idx');
            $table->index('created_at', 'qim_inc_ev_created_idx');
        });

        Schema::create('qim_alerts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('alert_key', 64)->unique();
            $table->string('rule_version', 16)->default('1.0.0');
            $table->string('rule_key', 64);
            $table->string('incident_group', 64);
            $table->timestampTz('window_start', 3);
            $table->timestampTz('window_end', 3);
            $table->string('severity', 16)->default('MEDIUM');
            $table->string('symbol', 32)->nullable();
            $table->string('title');
            $table->json('payload');
            $table->timestampTz('created_at', 3)->useCurrent();

            $table->index('rule_key', 'qim_alerts_rule_key_idx');
            $table->index('created_at', 'qim_alerts_created_idx');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("
                DO $$
                BEGIN
                    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'bybit_signals') THEN
                        GRANT SELECT, INSERT ON qim_comparisons TO bybit_signals;
                        GRANT SELECT, INSERT, UPDATE ON qim_incidents TO bybit_signals;
                        GRANT SELECT, INSERT ON qim_incident_events TO bybit_signals;
                        REVOKE UPDATE, DELETE ON qim_incident_events FROM bybit_signals;
                        GRANT SELECT, INSERT ON qim_alerts TO bybit_signals;
                    END IF;
                END $$;
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('qim_alerts');
        Schema::dropIfExists('qim_incident_events');
        Schema::dropIfExists('qim_incidents');
        Schema::dropIfExists('qim_comparisons');
    }
};
