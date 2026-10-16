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
            $table->string('outcome', 24)->nullable()->after('detected_at');
            $table->timestampTz('outcome_at', precision: 3)->nullable()->after('outcome');
            $table->decimal('forward_return', 20, 10)->nullable()->after('outcome_at');
            $table->decimal('mfe_return', 20, 10)->nullable()->after('forward_return');
            $table->decimal('mae_return', 20, 10)->nullable()->after('mfe_return');
            $table->index(['pattern_name', 'direction', 'outcome'], 'pattern_occurrence_outcome_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('candlestick_pattern_occurrences', function (Blueprint $table): void {
            $table->dropIndex('pattern_occurrence_outcome_lookup');
            $table->dropColumn(['outcome', 'outcome_at', 'forward_return', 'mfe_return', 'mae_return']);
        });
    }
};
