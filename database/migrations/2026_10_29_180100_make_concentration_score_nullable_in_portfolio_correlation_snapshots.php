<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_correlation_snapshots', function (Blueprint $table) {
            $table->decimal('concentration_score', 8, 4)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_correlation_snapshots', function (Blueprint $table) {
            $table->decimal('concentration_score', 8, 4)->default(0)->nullable(false)->change();
        });
    }
};
