<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET lock_timeout = '5s'");
        }

        if (! Schema::hasColumn('market_funding_events', 'mark_price_source')) {
            Schema::table('market_funding_events', function (Blueprint $table): void {
                // NULL preserves unknown legacy provenance; no historical backfill.
                $table->string('mark_price_source', 64)->nullable();
            });
        }
    }

    public function down(): void
    {
        // Recorded mark provenance is immutable financial audit evidence.
        throw new LogicException('Funding mark provenance cannot be discarded by down migration.');
    }
};
