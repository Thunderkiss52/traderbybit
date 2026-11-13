<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('paper_accounts', 'execution_block_origin')) {
            Schema::table('paper_accounts', static function (Blueprint $table): void {
                // NULL means legacy/unknown: cannot be automatically unblocked.
                $table->string('execution_block_origin', 48)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('paper_accounts', 'execution_block_origin')) {
            Schema::table('paper_accounts', static function (Blueprint $table): void {
                $table->dropColumn('execution_block_origin');
            });
        }
    }
};
