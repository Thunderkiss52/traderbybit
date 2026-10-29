<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('research_workers', function (Blueprint $table) {
            if (! Schema::hasColumn('research_workers', 'generation_token')) {
                $table->bigInteger('generation_token')->default(1);
            }
        });
    }

    public function down(): void
    {
        Schema::table('research_workers', function (Blueprint $table) {
            if (Schema::hasColumn('research_workers', 'generation_token')) {
                $table->dropColumn('generation_token');
            }
        });
    }
};
