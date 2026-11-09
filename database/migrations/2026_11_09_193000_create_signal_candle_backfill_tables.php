<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('signal_candle_backfill_checkpoints')) {
            Schema::create('signal_candle_backfill_checkpoints', function (Blueprint $table): void {
                $table->string('scope', 64)->primary();
                $table->timestampTz('last_decision_time', precision: 3)->nullable();
                $table->uuid('last_signal_id')->nullable();
                $table->unsignedInteger('processed_count')->default(0);
                $table->unsignedInteger('matched_count')->default(0);
                $table->unsignedInteger('unmatched_count')->default(0);
                $table->unsignedInteger('ambiguous_count')->default(0);
                $table->unsignedInteger('unclosed_count')->default(0);
                $table->timestampTz('last_run_at', precision: 3)->nullable();
                $table->timestampsTz(precision: 3);
            });
        }

        if (! Schema::hasTable('signal_candle_backfill_items')) {
            Schema::create('signal_candle_backfill_items', function (Blueprint $table): void {
                $table->uuid('signal_id')->primary();
                $table->string('status', 32);
                $table->unsignedBigInteger('candle_id')->nullable();
                $table->timestampTz('checked_at', precision: 3);
                $table->timestampTz('retry_after', precision: 3)->nullable();
                $table->string('reason', 255)->nullable();
                $table->timestampsTz(precision: 3);

                $table->foreign('signal_id')
                    ->references('id')
                    ->on('signals')
                    ->cascadeOnDelete();

                $table->index(['status', 'retry_after'], 'idx_backfill_items_status_retry');
                $table->index(['checked_at'], 'idx_backfill_items_checked_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_candle_backfill_items');
        Schema::dropIfExists('signal_candle_backfill_checkpoints');
    }
};
