<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signals', function (Blueprint $table): void {
            $table->dropUnique('signals_strategy_identity_unique');
            $table->unique(
                ['exchange', 'category', 'symbol', 'timeframe', 'candle_close_time', 'strategy_id', 'strategy_version'],
                'signals_strategy_identity_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('signals', function (Blueprint $table): void {
            $table->dropUnique('signals_strategy_identity_unique');
            $table->unique(
                ['exchange', 'category', 'symbol', 'timeframe', 'candle_close_time', 'strategy_version'],
                'signals_strategy_identity_unique',
            );
        });
    }
};
