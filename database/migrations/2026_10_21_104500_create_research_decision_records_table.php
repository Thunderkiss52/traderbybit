<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROFIT-1 / P-6 — research-owned storage for append-only decision records
 * (§14.2, §12.2).
 *
 * The table holds research decisions only. It never references the paper
 * ledger and it never changes a strategy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_decision_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('generation_id');
            $table->string('decision_state', 64);
            $table->string('previous_state', 64)->nullable();
            $table->string('actor_source', 128);
            $table->string('reason', 128);
            $table->string('claim_type', 64);
            $table->text('hypothesis')->nullable();
            $table->json('input_generation_ids');
            $table->string('preregistration_hash', 128)->nullable();
            $table->string('evidence_bundle_hash', 128)->nullable();
            $table->json('evidence');
            $table->timestampTz('as_of', precision: 3);
            $table->timestampsTz(precision: 3);

            $table->index(['generation_id'], 'research_decision_generation_idx');
            $table->index(['decision_state'], 'research_decision_state_idx');
            $table->index(['claim_type'], 'research_decision_claim_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_decision_records');
    }
};
