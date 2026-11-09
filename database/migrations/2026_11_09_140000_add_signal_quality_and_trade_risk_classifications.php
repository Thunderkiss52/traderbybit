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
            'risk_decisions',
            'virtual_positions',
            'strategy_observations',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                Schema::table($table, static function (Blueprint $t) use ($table): void {
                    if (! Schema::hasColumn($table, 'trade_risk')) {
                        $t->string('trade_risk', 20)->nullable()->after('quality_class');
                    }
                    if (! Schema::hasColumn($table, 'signal_quality')) {
                        $t->string('signal_quality', 20)->nullable()->after('quality_class');
                    }
                });
            }
        }

        // Add composite index for quality and risk filtering
        if (Schema::hasTable('signals') && Schema::hasColumn('signals', 'trade_risk')) {
            Schema::table('signals', static function (Blueprint $t): void {
                $t->index(['exchange', 'signal_quality', 'trade_risk'], 'idx_signals_exchange_quality_risk');
            });
        }

        if (Schema::hasTable('risk_decisions') && Schema::hasColumn('risk_decisions', 'trade_risk')) {
            Schema::table('risk_decisions', static function (Blueprint $t): void {
                $t->index(['paper_account_id', 'signal_quality', 'trade_risk'], 'idx_risk_decisions_quality_risk');
            });
        }

        if (Schema::hasTable('virtual_positions') && Schema::hasColumn('virtual_positions', 'trade_risk')) {
            Schema::table('virtual_positions', static function (Blueprint $t): void {
                $t->index(['paper_account_id', 'side', 'signal_quality', 'trade_risk'], 'idx_positions_side_quality_risk');
            });
        }
    }

    public function down(): void
    {
        foreach (['signals', 'risk_decisions', 'virtual_positions', 'strategy_observations'] as $table) {
            if (Schema::hasTable($table)) {
                Schema::table($table, static function (Blueprint $t) use ($table): void {
                    if (Schema::hasColumn($table, 'trade_risk')) {
                        $t->dropColumn('trade_risk');
                    }
                    if (Schema::hasColumn($table, 'signal_quality')) {
                        $t->dropColumn('signal_quality');
                    }
                });
            }
        }
    }
};
