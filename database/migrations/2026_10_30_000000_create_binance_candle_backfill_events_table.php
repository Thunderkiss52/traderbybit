<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('binance_candle_backfill_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('exchange', 32)->default('binance');
            $table->string('symbol', 32);
            $table->string('interval', 16);
            $table->bigInteger('from_ms')->index();
            $table->bigInteger('to_ms')->index();
            $table->unsignedInteger('requested_count')->default(0);
            $table->unsignedInteger('inserted_count')->default(0);
            $table->unsignedInteger('conflict_count')->default(0);
            $table->unsignedInteger('remaining_gap_count')->default(0);
            $table->string('status', 32)->default('COMPLETED'); // COMPLETED, PARTIAL, NO_PROGRESS, QUARANTINED
            $table->string('attempt_token', 64)->index();
            $table->timestampTz('completed_at', precision: 3);
            $table->json('report')->nullable();
            $table->timestampsTz(precision: 3);

            $table->unique(['exchange', 'symbol', 'interval', 'from_ms', 'to_ms', 'attempt_token'], 'binance_backfill_idempotency_unique');
            $table->index(['exchange', 'symbol', 'interval', 'status', 'completed_at'], 'binance_backfill_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('binance_candle_backfill_events');
    }
};