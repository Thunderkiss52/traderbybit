<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('virtual_positions') && ! Schema::hasColumn('virtual_positions', 'sizing_snapshot')) {
            Schema::table('virtual_positions', static function (Blueprint $t): void {
                $t->jsonb('sizing_snapshot')->nullable()->after('trade_risk');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('virtual_positions') && Schema::hasColumn('virtual_positions', 'sizing_snapshot')) {
            Schema::table('virtual_positions', static function (Blueprint $t): void {
                $t->dropColumn('sizing_snapshot');
            });
        }
    }
};
