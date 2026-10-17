<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Opposite directions may coexist on one confirmed bar.
        Schema::table('candlestick_pattern_occurrences', function (Blueprint $table): void {
            $table->dropUnique('candlestick_occurrence_identity');
            $table->unique(['exchange', 'category', 'symbol', 'timeframe', 'pattern_name', 'direction', 'version', 'detected_at'], 'candlestick_occurrence_identity_direction');
        });
        Schema::table('trading_journal_events', function (Blueprint $table): void {
            $table->index(['occurred_at', 'sequence'], 'journal_time_sequence_index');
        });
        Schema::table('level_interaction_forecasts', function (Blueprint $table): void {
            $table->index(['symbol', 'event', 'outcome_at'], 'level_forecast_matured_outcome_index');
        });
    }

    public function down(): void
    {
        Schema::table('level_interaction_forecasts', function (Blueprint $table): void {
            $table->dropIndex('level_forecast_matured_outcome_index');
        });
        Schema::table('trading_journal_events', function (Blueprint $table): void {
            $table->dropIndex('journal_time_sequence_index');
        });
        Schema::table('candlestick_pattern_occurrences', function (Blueprint $table): void {
            $table->dropUnique('candlestick_occurrence_identity_direction');
            $table->unique(['exchange', 'category', 'symbol', 'timeframe', 'pattern_name', 'version', 'detected_at'], 'candlestick_occurrence_identity');
        });
    }
};
