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
        Schema::create('signal_outcome_evaluations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('evaluation_key', 64)->unique();
            $table->foreignUuid('signal_id')->constrained('signals')->restrictOnDelete();
            $table->foreignUuid('research_job_id')->references('job_id')->on('research_jobs')->restrictOnDelete();
            $table->string('exchange', 32);
            $table->string('category', 16);
            $table->string('symbol', 32);
            $table->string('strategy_id', 64);
            $table->string('strategy_version', 64);
            $table->unsignedSmallInteger('source_timeframe_minutes');
            $table->unsignedSmallInteger('horizon_minutes');
            $table->string('direction', 8);
            $table->string('regime', 32);
            $table->string('data_source', 32);
            $table->string('policy_version', 64);
            $table->string('configuration_hash', 64);
            $table->string('dataset_hash', 64);
            $table->string('code_revision', 64);
            $table->string('input_hash', 64);
            $table->string('execution_hash', 64);
            $table->string('status', 16);
            $table->string('reason', 128);
            $table->string('horizon_anchor', 32);
            $table->timestampTz('decision_at', 3);
            $table->timestampTz('available_at', 3);
            $table->timestampTz('entry_at', 3);
            $table->timestampTz('horizon_end_at', 3);
            $table->timestampTz('evaluated_as_of', 3);
            $table->decimal('configured_notional', 24, 12);
            foreach (['gross_pnl', 'fees', 'funding', 'net_pnl', 'net_return'] as $column) {
                $table->decimal($column, 24, 12)->nullable();
            }
            $table->jsonb('result');
            $table->timestampTz('created_at', 3);
            $table->index(['exchange', 'strategy_id', 'source_timeframe_minutes', 'horizon_minutes', 'created_at'], 'signal_outcome_cohort_lookup');
            $table->unique(['signal_id', 'horizon_minutes', 'policy_version', 'input_hash'], 'signal_outcome_identity');
        });
        Schema::create('signal_quality_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('profile_key', 64)->unique();
            $table->foreignUuid('research_job_id')->references('job_id')->on('research_jobs')->restrictOnDelete();
            $table->string('scope_hash', 64)->index();
            $table->jsonb('scope');
            $table->string('policy_version', 64);
            $table->string('policy_hash', 64);
            $table->string('classification', 32)->index();
            $table->string('input_hash', 64);
            $table->jsonb('result');
            $table->timestampTz('as_of', 3);
            $table->timestampTz('created_at', 3);
        });
        DB::statement("ALTER TABLE signal_outcome_evaluations ADD CONSTRAINT signal_outcome_research_only CHECK (data_source = 'HYPOTHETICAL')");
        DB::statement("ALTER TABLE signal_quality_profiles ADD CONSTRAINT signal_profile_research_only CHECK (COALESCE(scope->>'data_source', '') = 'HYPOTHETICAL')");
        DB::statement("ALTER TABLE signal_quality_profiles ADD CONSTRAINT signal_profile_classification CHECK (classification IN ('PROFITABLE_CONFIRMED','PROMISING','NEUTRAL','INSUFFICIENT_DATA','UNPROFITABLE_CONFIRMED','DRIFT_DETECTED','INVALID_DATA'))");
        DB::statement("ALTER TABLE signal_outcome_evaluations ADD CONSTRAINT signal_outcome_status CHECK (status IN ('MATURED','PENDING','INVALID_DATA','UNFILLABLE'))");
        DB::statement("ALTER TABLE signal_outcome_evaluations ADD CONSTRAINT signal_outcome_money CHECK ((status = 'MATURED' AND gross_pnl IS NOT NULL AND fees IS NOT NULL AND funding IS NOT NULL AND net_pnl IS NOT NULL AND net_pnl = gross_pnl - fees + funding AND net_return IS NOT NULL) OR (status <> 'MATURED' AND gross_pnl IS NULL AND fees IS NULL AND funding IS NULL AND net_pnl IS NULL AND net_return IS NULL))");
        DB::statement('ALTER TABLE signal_outcome_evaluations ADD CONSTRAINT signal_outcome_horizons CHECK (source_timeframe_minutes IN (1,3,5,15,30,60) AND horizon_minutes IN (1,3,5,15,30,60) AND configured_notional > 0 AND horizon_end_at > entry_at AND entry_at >= available_at AND available_at >= decision_at)');
        DB::statement("CREATE INDEX signal_intelligence_pending_import_idx ON research_jobs (id) WHERE kind = 'SIGNAL_MARKOUT' AND status = 'SUCCEEDED' AND runtime_stats->>'signal_intelligence_import' IS NULL");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION reject_signal_intelligence_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Signal intelligence evidence is immutable';
END;
$$;
CREATE TRIGGER signal_outcome_evidence_immutable BEFORE UPDATE OR DELETE ON signal_outcome_evaluations FOR EACH ROW EXECUTE FUNCTION reject_signal_intelligence_mutation();
CREATE TRIGGER signal_quality_evidence_immutable BEFORE UPDATE OR DELETE ON signal_quality_profiles FOR EACH ROW EXECUTE FUNCTION reject_signal_intelligence_mutation();
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS signal_intelligence_pending_import_idx');
        Schema::dropIfExists('signal_quality_profiles');
        Schema::dropIfExists('signal_outcome_evaluations');
        DB::statement('DROP FUNCTION IF EXISTS reject_signal_intelligence_mutation()');
    }
};
