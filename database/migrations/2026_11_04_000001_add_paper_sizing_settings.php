<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paper_accounts', static function (Blueprint $table): void {
            $table->json('sizing_settings')->nullable();
        });
    }

    public function down(): void
    {
        // Historic sizing provenance is immutable, even if the app rolls back.
        throw new LogicException('PAPER sizing provenance cannot be discarded automatically.');
    }
};
