<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROFIT-1 / P-5 — research-owned storage for overlap clusters (§10.2, §12.2).
 *
 * The tables are append-only research output. They never reference or mutate
 * the paper ledger; membership is stored as plain position identifiers so the
 * cluster can be reconstructed and traced back to source position ids.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_concentration_clusters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('generation_id');
            $table->string('view_code', 32);
            $table->string('scope_code', 32);
            $table->uuid('paper_account_id')->nullable();
            $table->string('symbol', 32);
            $table->string('side_family', 8)->nullable();
            $table->string('cluster_id', 40);
            $table->unsignedInteger('member_count');
            $table->decimal('net_pnl', 36, 12);
            $table->timestampTz('cluster_start_at', precision: 3);
            $table->timestampTz('cluster_end_at', precision: 3);
            $table->string('cluster_definition_version', 40);
            $table->timestampsTz(precision: 3);

            $table->index(['generation_id', 'view_code', 'scope_code'], 'research_concentration_cluster_view_idx');
            $table->index(['cluster_id'], 'research_concentration_cluster_id_idx');
        });

        Schema::create('research_concentration_cluster_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('cluster_row_id');
            $table->uuid('generation_id');
            $table->string('cluster_id', 40);
            $table->uuid('position_id');
            $table->uuid('paper_account_id');
            $table->string('symbol', 32);
            $table->string('side', 8);
            $table->timestampTz('opened_at', precision: 3);
            $table->timestampTz('ended_at', precision: 3);
            $table->boolean('open_at_cutoff')->default(false);
            $table->boolean('partial_close_not_ending')->default(false);
            $table->decimal('net_pnl', 36, 12)->default(0);
            $table->decimal('unrealized_pnl', 36, 12)->default(0);
            $table->decimal('entry_notional', 36, 12)->default(0);
            $table->timestampsTz(precision: 3);

            $table->index(['generation_id', 'cluster_id'], 'research_concentration_member_cluster_idx');
            $table->index(['position_id'], 'research_concentration_member_position_idx');
            $table->index(['cluster_row_id'], 'research_concentration_member_row_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_concentration_cluster_members');
        Schema::dropIfExists('research_concentration_clusters');
    }
};
