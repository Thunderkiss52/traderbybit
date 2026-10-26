<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trading_pipeline_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 191)->unique();
            $table->string('exchange', 32);
            $table->string('symbol', 32);
            $table->string('timeframe', 16);
            $table->string('stage', 32);
            $table->timestampTz('candle_close_at');
            $table->string('status', 32)->default('PENDING');
            $table->json('payload')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['exchange', 'symbol', 'stage', 'candle_close_at'], 'idx_pipe_events_lookup');
            $table->index(['status', 'created_at'], 'idx_pipe_events_recovery');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trading_pipeline_events');
    }
};
