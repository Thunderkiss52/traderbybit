<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'signals',
            'virtual_positions',
            'strategy_observations',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                Schema::table($table, static function (Blueprint $t) use ($table): void {
                    if (! Schema::hasColumn($table, 'retrospective_outcome')) {
                        $t->string('retrospective_outcome', 20)->default('UNPROVEN')->after('trade_risk');
                    }
                });
            }
        }

        if (Schema::hasTable('signals') && Schema::hasColumn('signals', 'retrospective_outcome')) {
            Schema::table('signals', static function (Blueprint $t): void {
                $t->index(['exchange', 'symbol', 'timeframe', 'retrospective_outcome'], 'idx_signals_ex_sym_tf_outcome');
            });
        }

        if (Schema::hasTable('virtual_positions') && Schema::hasColumn('virtual_positions', 'retrospective_outcome')) {
            Schema::table('virtual_positions', static function (Blueprint $t): void {
                $t->index(['exchange_id', 'symbol', 'retrospective_outcome'], 'idx_positions_ex_sym_outcome');
            });
        }
    }

    public function down(): void
    {
        foreach (['signals', 'virtual_positions', 'strategy_observations'] as $table) {
            if (Schema::hasTable($table)) {
                Schema::table($table, static function (Blueprint $t) use ($table): void {
                    if (Schema::hasColumn($table, 'retrospective_outcome')) {
                        $t->dropColumn('retrospective_outcome');
                    }
                });
            }
        }
    }
};
