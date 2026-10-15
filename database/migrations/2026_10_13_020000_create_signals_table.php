<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('exchange', 24);
            $table->string('category', 24);
            $table->string('symbol', 32);
            $table->string('timeframe', 8);
            $table->string('side', 8);
            $table->string('quality_class', 1)->nullable();
            $table->unsignedSmallInteger('score');
            $table->timestampTz('decision_time', precision: 3);
            $table->timestampTz('candle_close_time', precision: 3);
            $table->decimal('entry_low', 30, 12)->nullable();
            $table->decimal('entry_high', 30, 12)->nullable();
            $table->decimal('stop_loss', 30, 12)->nullable();
            $table->decimal('take_profit_1', 30, 12)->nullable();
            $table->decimal('take_profit_2', 30, 12)->nullable();
            $table->decimal('risk_reward_1', 12, 8)->nullable();
            $table->decimal('risk_reward_2', 12, 8)->nullable();
            $table->decimal('model_risk_percent', 8, 4)->nullable();
            $table->timestampTz('expires_at', precision: 3);
            $table->unsignedInteger('maximum_holding_minutes');
            $table->json('reasons');
            $table->json('risks');
            $table->json('indicator_snapshot');
            $table->json('contract');
            $table->string('strategy_version', 32);
            $table->string('schema_version', 32);
            $table->timestampsTz(precision: 3);
            $table->unique(['exchange', 'category', 'symbol', 'timeframe', 'candle_close_time', 'strategy_version'], 'signals_strategy_identity_unique');
            $table->index(['symbol', 'side', 'decision_time'], 'signals_research_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signals');
    }
};
