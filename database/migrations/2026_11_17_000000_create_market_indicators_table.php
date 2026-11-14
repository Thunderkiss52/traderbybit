<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('market_indicators', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('exchange');
            $table->string('category');
            $table->string('symbol');
            $table->string('interval');
            $table->timestamp('open_time');
            // Indicator columns (use numeric with adequate precision)
            $table->decimal('ema_50', 24, 12)->nullable();
            $table->decimal('ema_200', 24, 12)->nullable();
            $table->decimal('macd', 24, 12)->nullable();
            $table->decimal('macd_signal', 24, 12)->nullable();
            $table->decimal('atr', 24, 12)->nullable();
            $table->decimal('bb_upper', 24, 12)->nullable();
            $table->decimal('bb_middle', 24, 12)->nullable();
            $table->decimal('bb_lower', 24, 12)->nullable();
            $table->decimal('adx', 24, 12)->nullable();
            $table->decimal('vwap', 24, 12)->nullable();
            $table->json('support_levels')->nullable();
            $table->json('resistance_levels')->nullable();
            $table->string('ingest_source')->default('rest'); // source of the underlying candles
            $table->timestamps();
            $table->unique(['exchange', 'symbol', 'interval', 'open_time'], 'uq_market_indicators');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_indicators');
    }
};
?>