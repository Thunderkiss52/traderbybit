<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candlestick_pattern_occurrences', function (Blueprint $table): void {
            $table->string('pattern_type', 24)->default('CANDLESTICK')->after('pattern_name');
            $table->index(['pattern_type', 'symbol', 'detected_at'], 'pattern_occurrence_type_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('candlestick_pattern_occurrences', function (Blueprint $table): void {
            $table->dropIndex('pattern_occurrence_type_lookup');
            $table->dropColumn('pattern_type');
        });
    }
};
