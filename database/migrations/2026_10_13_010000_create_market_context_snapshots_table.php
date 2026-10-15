<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_context_snapshots', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('exchange', 24);
            $table->string('category', 24);
            $table->string('symbol', 32);
            $table->timestampTz('observed_at', precision: 3);
            $table->decimal('bid', 30, 12);
            $table->decimal('ask', 30, 12);
            $table->decimal('turnover_24h', 36, 12);
            $table->decimal('tick_size', 30, 12);
            $table->decimal('funding_rate', 20, 12)->nullable();
            $table->decimal('open_interest', 36, 12)->nullable();
            $table->decimal('open_interest_change', 20, 12)->nullable();
            $table->timestampsTz(precision: 3);
            $table->unique(['exchange', 'category', 'symbol', 'observed_at'], 'market_context_identity_unique');
            $table->index(['exchange', 'category', 'symbol', 'observed_at'], 'market_context_historical_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_context_snapshots');
    }
};
