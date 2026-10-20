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
        $connection = DB::connection();

        // 1. Add non-destructive superseded_by tracking column if missing
        Schema::table('strategy_observations', function (Blueprint $table): void {
            if (! Schema::hasColumn('strategy_observations', 'superseded_by')) {
                $table->uuid('superseded_by')->nullable()->index();
            }
        });

        // 2. Identify and safely canonicalize existing duplicate slots deterministically without deletion
        $duplicates = DB::table('strategy_observations')
            ->select('exchange', 'category', 'symbol', 'strategy_id', 'strategy_version', 'decision_time')
            ->whereNull('superseded_by')
            ->groupBy('exchange', 'category', 'symbol', 'strategy_id', 'strategy_version', 'decision_time')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $dup) {
            $records = DB::table('strategy_observations')
                ->where('exchange', $dup->exchange)
                ->where('category', $dup->category)
                ->where('symbol', $dup->symbol)
                ->where('strategy_id', $dup->strategy_id)
                ->where('strategy_version', $dup->strategy_version)
                ->where('decision_time', $dup->decision_time)
                ->whereNull('superseded_by')
                ->orderByRaw("CASE WHEN side != 'SKIP' THEN 1 ELSE 0 END DESC")
                ->orderBy('created_at', 'asc')
                ->orderBy('score', 'desc')
                ->orderBy('id', 'asc')
                ->get();

            if ($records->count() > 1) {
                $canonicalId = $records->first()->id;
                $supersededIds = $records->slice(1)->pluck('id')->all();

                DB::table('strategy_observations')
                    ->whereIn('id', $supersededIds)
                    ->update([
                        'superseded_by' => $canonicalId,
                        'updated_at' => now(),
                    ]);

                // Maintain transitive pointer integrity: repoint any existing references to superseded rows
                DB::table('strategy_observations')
                    ->whereIn('superseded_by', $supersededIds)
                    ->update([
                        'superseded_by' => $canonicalId,
                        'updated_at' => now(),
                    ]);
            }
        }

        // 3. Drop legacy constraint that included configuration_hash and allowed duplicate slots
        if ($connection->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE strategy_observations DROP CONSTRAINT IF EXISTS strategy_observations_identity_unique');
        }
        DB::statement('DROP INDEX IF EXISTS strategy_observations_identity_unique');

        // 4. Cleanly replace with partial unique index enforcing slot idempotency for canonical observations
        DB::statement('DROP INDEX IF EXISTS strategy_observations_slot_unique');
        DB::statement('CREATE UNIQUE INDEX strategy_observations_slot_unique ON strategy_observations (exchange, category, symbol, strategy_id, strategy_version, decision_time) WHERE superseded_by IS NULL');

        // 5. Catalog postcondition validation (PostgreSQL)
        if ($connection->getDriverName() === 'pgsql') {
            $indexMeta = DB::selectOne("
                SELECT ix.indisunique AS is_unique,
                       ix.indisvalid AS is_valid,
                       pg_get_expr(ix.indpred, ix.indrelid) AS index_predicate,
                       pg_get_indexdef(ix.indexrelid) AS index_definition
                FROM pg_index ix
                JOIN pg_class i ON i.oid = ix.indexrelid
                WHERE i.relname = 'strategy_observations_slot_unique'
            ");

            if (! $indexMeta || ! $indexMeta->is_unique || ! $indexMeta->is_valid) {
                throw new RuntimeException('Postcondition failed: strategy_observations_slot_unique index is missing, not unique, or invalid in PostgreSQL catalog.');
            }

            if ($indexMeta->index_predicate !== '(superseded_by IS NULL)') {
                throw new RuntimeException("Postcondition failed: unexpected index predicate {$indexMeta->index_predicate}.");
            }
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS strategy_observations_slot_unique');

        // Recreate legacy index
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS strategy_observations_identity_unique ON strategy_observations (exchange, category, symbol, strategy_id, configuration_hash, decision_time)');

        Schema::table('strategy_observations', function (Blueprint $table): void {
            if (Schema::hasColumn('strategy_observations', 'superseded_by')) {
                $table->dropColumn('superseded_by');
            }
        });
    }
};
