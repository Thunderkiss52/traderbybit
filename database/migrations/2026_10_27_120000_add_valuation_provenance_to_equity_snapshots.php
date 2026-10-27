<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('equity_snapshots')) {
            return;
        }

        Schema::table('equity_snapshots', function (Blueprint $table): void {
            if (! Schema::hasColumn('equity_snapshots', 'valued_through')) {
                $table->timestampTz('valued_through', precision: 3)->nullable();
            }
            if (! Schema::hasColumn('equity_snapshots', 'valuation_fresh')) {
                $table->boolean('valuation_fresh')->nullable()->default(null);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('equity_snapshots')) {
            return;
        }

        Schema::table('equity_snapshots', function (Blueprint $table): void {
            $table->dropColumn(['valued_through', 'valuation_fresh']);
        });
    }
};
