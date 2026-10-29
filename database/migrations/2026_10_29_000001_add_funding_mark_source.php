<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_funding_events', function (Blueprint $table): void {
            // NULL preserves unknown legacy provenance; no historical backfill.
            $table->string('mark_price_source', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('market_funding_events', function (Blueprint $table): void {
            $table->dropColumn('mark_price_source');
        });
    }
};
