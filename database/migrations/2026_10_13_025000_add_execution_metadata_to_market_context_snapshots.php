<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_context_snapshots', function (Blueprint $table): void {
            $table->decimal('qty_step', 30, 12)->nullable();
            $table->decimal('min_order_qty', 30, 12)->nullable();
            $table->decimal('max_order_qty', 30, 12)->nullable();
            $table->decimal('min_notional_value', 30, 12)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('market_context_snapshots', function (Blueprint $table): void {
            $table->dropColumn(['qty_step', 'min_order_qty', 'max_order_qty', 'min_notional_value']);
        });
    }
};
