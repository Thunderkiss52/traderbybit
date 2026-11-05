<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historical_provider_windows', function (Blueprint $t): void {
            $t->string('host', 80)->primary();
            $t->timestampTz('next_at', 3);
        });
        Schema::create('historical_funding_observations', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->uuid('batch_id');
            $t->string('source_revision', 64);
            $t->string('exchange', 24)->default('binance');
            $t->string('category', 24)->default('linear');
            $t->string('symbol', 24);
            $t->timestampTz('funding_at', 3);
            $t->decimal('rate', 20, 12);
            $t->unsignedInteger('interval_hours')->nullable();
            $t->decimal('mark_price', 30, 12)->nullable();
            $t->string('mark_source', 64)->nullable();
            $t->string('status', 32)->default('VERIFIED_OBSERVATION');
            $t->timestampTz('created_at');
            $t->unique(['batch_id', 'source_revision', 'symbol', 'funding_at'], 'hist_funding_identity');
            $t->index(['symbol', 'funding_at']);
        });
        Schema::create('historical_mark_candles', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->uuid('batch_id');
            $t->string('source_revision', 64);
            $t->string('symbol', 24);
            $t->string('interval', 8);
            $t->timestampTz('open_time', 3);
            foreach (['open', 'high', 'low', 'close'] as $field) {
                $t->decimal($field, 30, 12);
            }$t->timestampTz('created_at');
            $t->unique(['batch_id', 'source_revision', 'symbol', 'interval', 'open_time'], 'hist_marks_identity');
        });
        Schema::create('historical_data_conflicts', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->uuid('batch_id');
            $t->uuid('entry_id');
            $t->string('family', 32);
            $t->string('symbol', 24);
            $t->timestampTz('at', 3);
            $t->jsonb('stored');
            $t->jsonb('incoming');
            $t->string('fingerprint', 64)->unique();
            $t->string('status', 32)->default('UNRESOLVED');
            $t->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        foreach (['historical_provider_windows', 'historical_data_conflicts', 'historical_mark_candles', 'historical_funding_observations'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
