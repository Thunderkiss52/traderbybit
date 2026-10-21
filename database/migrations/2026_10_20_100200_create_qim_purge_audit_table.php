<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qim_purge_audit', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('purged_by', 64);
            $table->string('retention_rule', 64);
            $table->string('rule_version', 16)->default('1.0.0');
            $table->string('reason');
            $table->timestampTz('purged_at', 3);
            $table->unsignedInteger('records_purged');
            $table->json('details')->nullable();
            $table->timestampTz('created_at', 3)->useCurrent();

            $table->index('purged_at', 'qim_purge_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qim_purge_audit');
    }
};
