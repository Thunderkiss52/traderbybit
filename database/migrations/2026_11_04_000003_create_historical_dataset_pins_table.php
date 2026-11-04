<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historical_dataset_pins', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope_fingerprint', 64)->unique();
            $table->string('exchange', 16);
            $table->string('category', 16);
            $table->json('symbols');
            $table->timestampTz('from_at', 3);
            $table->timestampTz('to_at', 3);
            $table->unsignedInteger('warmup_bars');
            $table->string('checksum', 64);
            $table->json('warnings')->nullable();
            $table->json('series_counts')->nullable();
            $table->timestampTz('pinned_at', 3);
            $table->timestampsTz(3);
        });
    }

    public function down(): void
    {
        throw new LogicException('Historical dataset pins cannot be removed automatically.');
    }
};
