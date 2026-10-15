<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_candles', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('exchange', 24);
            $table->string('category', 24);
            $table->string('symbol', 32);
            $table->string('interval', 8);
            $table->timestampTz('open_time', precision: 3);
            $table->decimal('open', 30, 12);
            $table->decimal('high', 30, 12);
            $table->decimal('low', 30, 12);
            $table->decimal('close', 30, 12);
            $table->decimal('volume', 36, 12);
            $table->decimal('turnover', 36, 12);
            $table->boolean('is_closed')->default(false);
            $table->timestampsTz(precision: 3);

            $table->unique(
                ['exchange', 'category', 'symbol', 'interval', 'open_time'],
                'market_candles_identity_unique',
            );
            $table->index(['symbol', 'interval', 'open_time'], 'market_candles_series_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_candles');
    }
};
