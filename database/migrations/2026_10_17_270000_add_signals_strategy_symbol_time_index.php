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
            $table->index(['strategy_id', 'symbol', 'decision_time'], 'signals_strategy_symbol_time_index');
        });
    }

    public function down(): void
    {
        Schema::table('signals', function (Blueprint $table): void {
            $table->dropIndex('signals_strategy_symbol_time_index');
        });
    }
};
