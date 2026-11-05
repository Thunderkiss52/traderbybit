<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('strategy_definitions', function (Blueprint $table): void {
            $table->string('engine_checksum', 64)->nullable();
        });
        Schema::create('strategy_parity_certificates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('definition_id')->constrained('strategy_definitions');
            $table->string('exchange', 32);
            $table->string('symbol', 24);
            $table->string('engine_checksum', 64);
            $table->string('definition_checksum', 64);
            $table->string('run_id', 64);
            $table->jsonb('proof');
            $table->timestampTz('created_at');
            $table->unique(['definition_id', 'exchange', 'symbol']);
        });
        Schema::create('strategy_definition_approvals', function (Blueprint $table): void {
            $table->foreignId('definition_id')->primary()->constrained('strategy_definitions');
            $table->string('reference', 200);
            $table->string('actor', 64);
            $table->jsonb('evidence');
            $table->timestampTz('created_at');
        });
        Schema::create('strategy_primary_registrations', function (Blueprint $table): void {
            $table->string('strategy_id', 64)->primary();
            $table->foreignId('definition_id')->constrained('strategy_definitions');
            $table->jsonb('exchanges');
            $table->boolean('enabled')->default(false);
            $table->timestampTz('active_from')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategy_primary_registrations');
        Schema::dropIfExists('strategy_definition_approvals');
        Schema::dropIfExists('strategy_parity_certificates');
        Schema::table('strategy_definitions', function (Blueprint $table): void {
            $table->dropColumn('engine_checksum');
        });
    }
};
