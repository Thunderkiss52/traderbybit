<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROFIT-1 / P-4 — research-owned storage for MFE/MAE observations and cost
 * attribution (§9, §12.2).
 *
 * The tables are append-only research output and never reference or mutate the
 * paper ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_mfe_mae_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('generation_id');
            $table->uuid('position_id');
            $table->uuid('paper_account_id');
            $table->string('symbol', 32);
            $table->string('side', 8);
            $table->string('status', 32);
            $table->timestampTz('holding_start_at', precision: 3);
            $table->timestampTz('holding_end_at', precision: 3);
            $table->boolean('open_at_cutoff')->default(false);
            $table->unsignedInteger('candle_count')->default(0);
            $table->decimal('mfe_price', 36, 12)->nullable();
            $table->decimal('mae_price', 36, 12)->nullable();
            $table->decimal('mfe_usdt', 36, 12)->nullable();
            $table->decimal('mae_usdt', 36, 12)->nullable();
            $table->decimal('mfe_r', 24, 12)->nullable();
            $table->decimal('mae_r', 24, 12)->nullable();
            $table->string('mfe_candle_id', 64)->nullable();
            $table->timestampTz('mfe_candle_close_time', precision: 3)->nullable();
            $table->string('mae_candle_id', 64)->nullable();
            $table->timestampTz('mae_candle_close_time', precision: 3)->nullable();
            $table->boolean('ambiguous_path')->default(false);
            $table->string('ambiguity_reason', 128)->nullable();
            $table->string('risk_reason', 64)->nullable();
            $table->json('source_candle_ids')->nullable();
            $table->json('source_candle_close_times')->nullable();
            $table->string('limitations', 128)->nullable();
            $table->timestampTz('as_of', precision: 3);
            $table->timestampsTz(precision: 3);

            $table->index(['generation_id', 'symbol'], 'research_mfe_mae_generation_symbol_idx');
            $table->index(['position_id'], 'research_mfe_mae_position_idx');
        });

        Schema::create('research_cost_attribution_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('generation_id');
            $table->string('dimension', 32);
            $table->string('group_key', 128);
            $table->json('metrics');
            $table->timestampsTz(precision: 3);

            $table->index(['generation_id', 'dimension'], 'research_cost_attribution_generation_dimension_idx');
            $table->unique(['generation_id', 'dimension', 'group_key'], 'research_cost_attribution_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_cost_attribution_records');
        Schema::dropIfExists('research_mfe_mae_records');
    }
};
