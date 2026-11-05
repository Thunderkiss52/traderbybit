<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historical_acquisition_pins', function (Blueprint $t): void {
            $t->string('id', 64)->primary();
            $t->uuid('batch_id');
            $t->uuid('canonical_pin_id');
            $t->jsonb('scope');
            $t->jsonb('provenance');
            $t->string('supplemental_sha256', 64);
            $t->jsonb('license');
            $t->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historical_acquisition_pins');
    }
};
