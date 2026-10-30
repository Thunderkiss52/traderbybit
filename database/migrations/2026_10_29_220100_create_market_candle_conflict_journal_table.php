<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_candle_conflict_journal', function (Blueprint $table): void {
            $table->string('event_id', 64)->primary();
            $table->string('evidence_hash', 64);
            $table->jsonb('evidence');
            $table->string('source_entry', 80);
            $table->string('exchange', 32);
            $table->string('category', 32);
            $table->string('symbol', 32);
            $table->string('interval', 16);
            $table->timestampTz('open_time');
            $table->string('state', 16)->default('PENDING');
            $table->unsignedInteger('attempts')->default(0);
            $table->boolean('retryable')->default(true);
            $table->string('claim_token', 64)->nullable();
            $table->timestampTz('claim_until')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->foreignId('conflict_id')->nullable()->constrained('market_candle_conflicts')->restrictOnDelete();
            $table->timestampTz('recovered_at')->nullable();
            $table->timestampsTz();
            $table->index(['exchange', 'symbol', 'interval', 'state'], 'idx_conflict_journal_scope');
        });
        DB::statement("ALTER TABLE market_candle_conflict_journal ADD CONSTRAINT candle_journal_state CHECK (state IN ('PENDING','IN_PROGRESS','RECOVERED','FAILED'))");
        DB::statement("ALTER TABLE market_candle_conflict_journal ADD CONSTRAINT candle_journal_claim CHECK ((state = 'IN_PROGRESS') = (claim_token IS NOT NULL AND claim_until IS NOT NULL))");
        DB::statement("ALTER TABLE market_candle_conflict_journal ADD CONSTRAINT candle_journal_receipt CHECK ((state = 'RECOVERED') = (recovered_at IS NOT NULL AND conflict_id IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('market_candle_conflict_journal');
    }
};
