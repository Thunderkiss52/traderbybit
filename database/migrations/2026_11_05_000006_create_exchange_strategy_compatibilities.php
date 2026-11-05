<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('exchange_strategy_compatibilities')) {
            Schema::create('exchange_strategy_compatibilities', static function (Blueprint $table): void {
                $table->id();
                $table->string('exchange_id', 32);
                $table->string('strategy_id', 64);
                $table->string('strategy_version', 32)->default('1.0.0');
                $table->string('status', 32)->default('PENDING')->index();
                $table->boolean('research_eligible')->default(false)->index();
                $table->boolean('shadow_eligible')->default(false)->index();
                $table->boolean('paper_eligible')->default(false)->index();
                $table->boolean('market_type_compatible')->default(false);
                $table->boolean('instruments_compatible')->default(false);
                $table->boolean('timeframes_compatible')->default(false);
                $table->boolean('history_compatible')->default(false);
                $table->boolean('features_compatible')->default(false);
                $table->boolean('execution_model_compatible')->default(false);
                $table->json('diagnostics');
                $table->json('capability_snapshot')->nullable();
                $table->json('dataset_coverage')->nullable();
                $table->timestampTz('evaluated_at', 3)->nullable();
                $table->string('evaluation_version', 32)->default('v1.0');
                $table->timestampsTz(3);

                $table->unique(['exchange_id', 'strategy_id', 'strategy_version'], 'exchange_strategy_version_unique');
                $table->foreign('exchange_id')->references('id')->on('exchanges')->cascadeOnDelete();
                $table->index(['exchange_id', 'status']);
                $table->index(['strategy_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_strategy_compatibilities');
    }
};
