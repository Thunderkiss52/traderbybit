<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('market_candle_conflicts')) {
            return;
        }

        Schema::create('market_candle_conflicts', function (Blueprint $table) {
            $table->id();
            $table->string('exchange', 32);
            $table->string('category', 32)->default('linear');
            $table->string('symbol', 32);
            $table->string('interval', 16);
            $table->timestampTz('open_time');
            $table->jsonb('stored_values');
            $table->jsonb('incoming_values');
            $table->string('conflict_column', 32)->nullable();
            $table->text('error_message');
            $table->string('source_provenance', 64)->default('binance_public_rest');
            $table->string('payload_fingerprint', 64);
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->string('status', 32)->default('UNRESOLVED');
            $table->timestampTz('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestampsTz();

            $table->unique(['exchange', 'category', 'symbol', 'interval', 'open_time'], 'uq_candle_conflicts_scope');
            $table->index(['exchange', 'symbol', 'interval', 'status'], 'idx_candle_conflicts_lookup');
            $table->index(['status'], 'idx_candle_conflicts_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_candle_conflicts');
    }
};
