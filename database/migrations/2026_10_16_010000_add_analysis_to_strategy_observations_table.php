<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('strategy_observations', function (Blueprint $table): void {
            $table->json('analysis')->nullable()->after('reasons');
        });
    }

    public function down(): void
    {
        Schema::table('strategy_observations', function (Blueprint $table): void {
            $table->dropColumn('analysis');
        });
    }
};
