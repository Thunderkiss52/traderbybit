<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A2 — the promotion control storage: the immutable approval content, the
 * rollback triggers and the kill switch.
 *
 * Additive only. The table is append-only and research-owned: it never
 * references the paper ledger and it never carries an order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('research_promotion_decisions', function (Blueprint $table): void {
            $table->json('approval_content')->nullable();
            $table->json('rollback_triggers')->nullable();
            $table->boolean('kill_switch_active')->default(false);
            $table->boolean('reversible')->default(true);
            $table->boolean('rollback_applied')->default(false);
        });

        Schema::create('research_promotion_controls', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('sequence')->default(0);
            $table->uuid('promotion_decision_id')->nullable();
            $table->string('action', 48);
            $table->string('reason', 160);
            $table->string('actor', 128)->nullable();
            $table->json('payload')->nullable();
            $table->timestampTz('created_at', precision: 3);

            $table->index(['action'], 'research_promotion_control_action_idx');
            $table->index(['promotion_decision_id'], 'research_promotion_control_decision_idx');
            $table->index(['sequence'], 'research_promotion_control_sequence_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_promotion_controls');

        Schema::table('research_promotion_decisions', function (Blueprint $table): void {
            $table->dropColumn([
                'approval_content',
                'rollback_triggers',
                'kill_switch_active',
                'reversible',
                'rollback_applied',
            ]);
        });
    }
};
