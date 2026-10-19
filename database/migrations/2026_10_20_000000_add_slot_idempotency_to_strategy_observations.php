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
        // Fail-safe check: never run against production
        $connection = DB::connection();
        $databaseName = $connection->getDatabaseName();
        if (str_contains(strtolower($databaseName), 'prod') || app()->environment('production')) {
            throw new RuntimeException("SAFETY ABORT: Cannot execute schema migration against production database: {$databaseName}");
        }

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
                ->orderBy('created_at', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            if ($records->count() > 1) {
                $canonicalId = $records->first()->id;
                $supersededIds = $records->slice(1)->pluck('id')->all();

                DB::table('strategy_observations')
                    ->whereIn('id', $supersededIds)
                    ->update(['superseded_by' => $canonicalId]);
            }
        }

        // 3. Drop legacy constraint that included configuration_hash and allowed duplicate slots
        if ($connection->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE strategy_observations DROP CONSTRAINT IF EXISTS strategy_observations_identity_unique');
        }
        DB::statement('DROP INDEX IF EXISTS strategy_observations_identity_unique');

        // 4. Create new partial unique index enforcing slot idempotency for canonical observations
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS strategy_observations_slot_unique ON strategy_observations (exchange, category, symbol, strategy_id, strategy_version, decision_time) WHERE superseded_by IS NULL');
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
