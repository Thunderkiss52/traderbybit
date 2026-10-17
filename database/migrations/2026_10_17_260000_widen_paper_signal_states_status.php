<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paper_signal_states', function (Blueprint $table): void {
            $table->string('status', 32)->change();
        });
    }

    public function down(): void
    {
        Schema::table('paper_signal_states', function (Blueprint $table): void {
            $table->string('status', 24)->change();
        });
    }
};
