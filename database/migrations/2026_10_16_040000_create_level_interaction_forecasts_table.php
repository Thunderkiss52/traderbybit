<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('level_interaction_forecasts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('exchange', 24);
            $table->string('category', 24);
            $table->string('symbol', 32);
            $table->string('timeframe', 8);
            $table->string('event', 48);
            $table->string('side', 8);
            $table->timestampTz('triggered_at', precision: 3);
            $table->timestampTz('horizon_ends_at', precision: 3);
            $table->string('model_version', 32);
            $table->string('configuration_hash', 64);
            $table->unsignedInteger('sample_size')->default(0);
            $table->decimal('success_probability', 12, 8)->nullable();
            $table->decimal('failure_probability', 12, 8)->nullable();
            $table->decimal('unresolved_probability', 12, 8)->nullable();
            $table->decimal('confidence_low', 12, 8)->nullable();
            $table->decimal('confidence_high', 12, 8)->nullable();
            $table->string('validation_status', 32);
            $table->string('outcome', 24)->nullable();
            $table->timestampTz('outcome_at', precision: 3)->nullable();
            $table->decimal('brier_score', 12, 8)->nullable();
            $table->json('features');
            $table->timestampsTz(precision: 3);
            $table->unique(['exchange', 'category', 'symbol', 'timeframe', 'event', 'model_version', 'triggered_at'], 'level_forecast_identity');
            $table->index(['symbol', 'event', 'outcome', 'triggered_at'], 'level_forecast_research_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('level_interaction_forecasts');
    }
};
