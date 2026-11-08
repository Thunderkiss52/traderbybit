<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signals', function (Blueprint $table): void {
            $table->unsignedBigInteger('candle_id')->nullable()->after('symbol');
            $table->index(['candle_id'], 'signals_candle_id_index');
            $table->foreign('candle_id', 'signals_candle_id_foreign')
                ->references('id')
                ->on('market_candles')
                ->nullOnDelete();
        });

        DB::statement("
            UPDATE signals s
            SET candle_id = mc.id
            FROM market_candles mc
            WHERE s.candle_id IS NULL
              AND s.exchange = mc.exchange
              AND s.category = mc.category
              AND s.symbol = mc.symbol
              AND (
                (s.timeframe IN ('15m', '15') AND mc.interval = '15' AND mc.open_time = s.candle_close_time - interval '15 minutes')
                OR (s.timeframe IN ('5m', '5') AND mc.interval = '5' AND mc.open_time = s.candle_close_time - interval '5 minutes')
                OR (s.timeframe IN ('1h', '60') AND mc.interval = '60' AND mc.open_time = s.candle_close_time - interval '1 hour')
                OR (s.timeframe IN ('4h', '240') AND mc.interval = '240' AND mc.open_time = s.candle_close_time - interval '4 hours')
                OR (s.timeframe IN ('1d', 'D') AND mc.interval = 'D' AND mc.open_time = s.candle_close_time - interval '1 day')
              )
        ");
    }

    public function down(): void
    {
        Schema::table('signals', function (Blueprint $table): void {
            $table->dropForeign('signals_candle_id_foreign');
            $table->dropIndex('signals_candle_id_index');
            $table->dropColumn('candle_id');
        });
    }
};
