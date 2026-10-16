<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategy_observations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('exchange', 24);
            $table->string('category', 24);
            $table->string('symbol', 32);
            $table->string('strategy_id', 64);
            $table->string('strategy_version', 32);
            $table->string('configuration_hash', 64);
            $table->string('side', 8);
            $table->string('quality_class', 1)->nullable();
            $table->unsignedSmallInteger('score');
            $table->timestampTz('decision_time', precision: 3);
            $table->json('reasons');
            $table->timestampsTz(precision: 3);
            $table->unique(['exchange', 'category', 'symbol', 'strategy_id', 'configuration_hash', 'decision_time'], 'strategy_observations_identity_unique');
            $table->index(['strategy_id', 'decision_time'], 'strategy_observations_strategy_time_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategy_observations');
    }
};
