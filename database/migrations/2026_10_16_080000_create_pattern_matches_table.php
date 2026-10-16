<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_pattern_matches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('pattern_id');
            $table->string('symbol', 32);
            $table->string('direction', 8);
            $table->timestampTz('matched_at', precision: 3);
            $table->json('feature_snapshot');
            $table->string('status', 24)->default('PENDING');
            $table->string('outcome', 24)->nullable();
            $table->decimal('net_r', 20, 10)->nullable();
            $table->timestampTz('outcome_at', precision: 3)->nullable();
            $table->timestampsTz(precision: 3);
            $table->foreign('pattern_id')->references('id')->on('research_patterns')->cascadeOnDelete();
            $table->unique(['pattern_id', 'symbol', 'matched_at'], 'research_pattern_match_identity');
            $table->index(['symbol', 'status', 'matched_at'], 'research_pattern_match_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_pattern_matches');
    }
};
