<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_pattern_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('status', 24)->index();
            $table->timestampTz('from_at');
            $table->timestampTz('to_at');
            $table->string('symbol', 32);
            $table->string('configuration_hash', 64);
            $table->json('parameters');
            $table->json('data_quality')->nullable();
            $table->unsignedInteger('examined_decisions')->default(0);
            $table->unsignedInteger('labeled_observations')->default(0);
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->index(['created_at', 'id']);
        });
        Schema::create('research_patterns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('pattern_run_id')->constrained('research_pattern_runs')->restrictOnDelete();
            $table->string('direction', 8);
            $table->string('status', 24);
            $table->json('terms');
            $table->json('metrics');
            $table->string('rule_hash', 64);
            $table->string('version', 32);
            $table->timestampsTz();
            $table->unique(['pattern_run_id', 'rule_hash']);
            $table->index(['status', 'created_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_patterns');
        Schema::dropIfExists('research_pattern_runs');
    }
};
