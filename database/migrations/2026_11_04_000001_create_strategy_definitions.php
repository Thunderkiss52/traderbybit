<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategy_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('strategy_id', 64);
            $table->unsignedInteger('version');
            $table->jsonb('definition');
            $table->string('checksum', 64);
            $table->string('status', 32)->default('VALIDATED');
            $table->timestampTz('created_at');
            $table->unique(['strategy_id', 'version']);
        });
        Schema::create('strategy_definition_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('definition_id')->constrained('strategy_definitions');
            $table->string('action', 64);
            $table->string('actor', 64);
            $table->jsonb('details');
            $table->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategy_definition_events');
        Schema::dropIfExists('strategy_definitions');
    }
};
