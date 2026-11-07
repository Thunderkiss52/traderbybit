<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('autonomy_settings', static function (Blueprint $table): void {
            $table->decimal('paper_initial_capital', 24, 12)->default('0');
        });
    }

    public function down(): void
    {
        // Preserve the approved capital policy and its audit history.
    }
};
