<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategy_definition_runs', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->foreignId('definition_id')->constrained('strategy_definitions');
            $table->jsonb('metadata');
            $table->jsonb('result');
            $table->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategy_definition_runs');
    }
};
