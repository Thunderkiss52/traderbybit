<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. research_generations table
        if (! Schema::hasTable('research_generations')) {
            Schema::create('research_generations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('package_code', 16); // e.g. 'P-1'
                $table->string('accounting_scope', 32); // 'FULL_LIFECYCLE', 'WINDOW_FLOW', etc.
                $table->timestampTz('as_of', precision: 3);
                $table->string('status', 32)->default('QUEUED'); // QUEUED, RUNNING, SUCCEEDED, FAILED, TIMEOUT
                $table->string('idempotency_key', 160)->unique();
                $table->string('request_payload_hash', 64);
                $table->string('source_hash', 64)->nullable();
                $table->string('output_hash', 64)->nullable();
                $table->jsonb('versioned_config');
                $table->jsonb('row_counts')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestampTz('heartbeat_at', precision: 3)->nullable();
                $table->unsignedInteger('stale_after_seconds')->default(300);
                $table->text('error_message')->nullable();
                $table->timestampsTz(precision: 3);

                $table->index(['package_code', 'status', 'created_at']);
            });
        }

        // 2. slippage_attribution_fills table
        if (! Schema::hasTable('slippage_attribution_fills')) {
            Schema::create('slippage_attribution_fills', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('generation_id');
                $table->uuid('execution_id');
                $table->uuid('position_id');
                $table->uuid('paper_account_id');
                $table->string('strategy_id', 64);
                $table->string('symbol', 32);
                $table->string('execution_side', 8); // BUY or SELL
                $table->string('fill_role', 24); // ENTRY, PARTIAL_EXIT, FINAL_EXIT
                $table->decimal('quantity', 30, 12);
                $table->decimal('contract_multiplier', 20, 8)->default(1);
                $table->decimal('reference_price', 30, 12);
                $table->decimal('fill_price', 30, 12);
                $table->decimal('reference_notional', 36, 12);
                $table->decimal('slippage_drag_usdt', 36, 12);
                $table->decimal('slippage_bps', 20, 8)->nullable();
                $table->string('price_source', 40);
                $table->string('provenance_status', 32)->default('RECOMPUTED_FROM_SOURCE');
                $table->boolean('ambiguous_execution')->default(false);
                $table->string('ambiguity_reason', 128)->nullable();
                $table->timestampTz('market_event_at', precision: 3);
                $table->timestampsTz(precision: 3);

                $table->unique(['generation_id', 'execution_id']);
                $table->index(['generation_id', 'position_id']);
                $table->index(['generation_id', 'strategy_id', 'symbol']);
            });
        }

        // 3. slippage_attribution table (position-level aggregates)
        if (! Schema::hasTable('slippage_attribution')) {
            Schema::create('slippage_attribution', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('generation_id');
                $table->uuid('position_id');
                $table->uuid('paper_account_id');
                $table->string('strategy_id', 64);
                $table->string('symbol', 32);
                $table->string('position_side', 8); // BUY (long) or SELL (short)
                $table->decimal('entry_slippage_usdt', 36, 12)->default(0);
                $table->decimal('partial_exit_slippage_usdt', 36, 12)->default(0);
                $table->decimal('final_exit_slippage_usdt', 36, 12)->default(0);
                $table->decimal('total_slippage_drag_usdt', 36, 12)->default(0);
                $table->decimal('total_reference_notional', 36, 12)->default(0);
                $table->decimal('weighted_slippage_bps', 20, 8)->nullable();
                $table->unsignedInteger('fill_count')->default(0);
                $table->unsignedInteger('ambiguous_fill_count')->default(0);
                $table->string('provenance_status', 32)->default('RECOMPUTED_FROM_SOURCE');
                $table->string('algorithm_version', 32);
                $table->timestampsTz(precision: 3);

                $table->unique(['generation_id', 'position_id']);
                $table->index(['generation_id', 'strategy_id', 'symbol']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('slippage_attribution');
        Schema::dropIfExists('slippage_attribution_fills');
        Schema::dropIfExists('research_generations');
    }
};
