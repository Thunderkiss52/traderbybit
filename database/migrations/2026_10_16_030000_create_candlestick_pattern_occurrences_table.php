<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candlestick_pattern_occurrences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('exchange', 24);
            $table->string('category', 24);
            $table->string('symbol', 32);
            $table->string('timeframe', 8);
            $table->string('pattern_name', 48);
            $table->string('direction', 12);
            $table->string('version', 32);
            $table->unsignedTinyInteger('bar_count');
            $table->timestampTz('formation_time', precision: 3);
            $table->timestampTz('detected_at', precision: 3);
            $table->json('evidence');
            $table->timestampsTz(precision: 3);
            $table->unique(['exchange', 'category', 'symbol', 'timeframe', 'pattern_name', 'version', 'detected_at'], 'candlestick_occurrence_identity');
            $table->index(['symbol', 'timeframe', 'detected_at'], 'candlestick_occurrence_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candlestick_pattern_occurrences');
    }
};
