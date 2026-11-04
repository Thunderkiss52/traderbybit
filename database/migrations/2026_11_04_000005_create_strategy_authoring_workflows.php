<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategy_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedInteger('revision')->default(1);
            $table->jsonb('definition');
            $table->text('description')->nullable();
            $table->string('checksum', 64);
            $table->string('status', 32)->default('DRAFT');
            $table->foreignId('published_definition_id')->nullable()->constrained('strategy_definitions');
            $table->timestampsTz();
        });
        Schema::create('strategy_workflow_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('draft_id')->nullable();
            $table->foreignId('definition_id')->nullable()->constrained('strategy_definitions');
            $table->string('action', 64);
            $table->string('actor', 64);
            $table->jsonb('details');
            $table->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategy_workflow_events');
        Schema::dropIfExists('strategy_drafts');
    }
};
