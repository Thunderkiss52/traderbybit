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
        Schema::table('counterfactual_evaluations', function (Blueprint $table): void {
            if (! Schema::hasColumn('counterfactual_evaluations', 'superseded_by')) {
                $table->uuid('superseded_by')->nullable()->index();
            }
        });

        // Resolve any existing duplicate slot evaluations deterministically before applying unique constraint
        $duplicates = DB::table('counterfactual_evaluations')
            ->select('strategy_id', 'strategy_version', 'symbol', 'timeframe', 'decision_time')
            ->whereNull('superseded_by')
            ->groupBy('strategy_id', 'strategy_version', 'symbol', 'timeframe', 'decision_time')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $dup) {
            $records = DB::table('counterfactual_evaluations')
                ->where('strategy_id', $dup->strategy_id)
                ->where('strategy_version', $dup->strategy_version)
                ->where('symbol', $dup->symbol)
                ->where('timeframe', $dup->timeframe)
                ->where('decision_time', $dup->decision_time)
                ->whereNull('superseded_by')
                ->orderBy('created_at', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            if ($records->count() > 1) {
                $canonicalId = $records->first()->id;
                $supersededIds = $records->slice(1)->pluck('id')->all();

                DB::table('counterfactual_evaluations')
                    ->whereIn('id', $supersededIds)
                    ->update(['superseded_by' => $canonicalId]);
            }
        }

        // Also resolve any duplicate non-null signal_id associations
        $signalDuplicates = DB::table('counterfactual_evaluations')
            ->select('signal_id')
            ->whereNotNull('signal_id')
            ->whereNull('superseded_by')
            ->groupBy('signal_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($signalDuplicates as $dup) {
            $records = DB::table('counterfactual_evaluations')
                ->where('signal_id', $dup->signal_id)
                ->whereNull('superseded_by')
                ->orderBy('created_at', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            if ($records->count() > 1) {
                $canonicalId = $records->first()->id;
                $supersededIds = $records->slice(1)->pluck('id')->all();

                DB::table('counterfactual_evaluations')
                    ->whereIn('id', $supersededIds)
                    ->update(['superseded_by' => $canonicalId]);
            }
        }

        // Create partial unique indexes enforcing exactly one canonical evaluation per slot and per signal
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS counterfactual_evaluations_slot_unique ON counterfactual_evaluations (strategy_id, strategy_version, symbol, timeframe, decision_time) WHERE superseded_by IS NULL');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS counterfactual_evaluations_signal_unique ON counterfactual_evaluations (signal_id) WHERE signal_id IS NOT NULL AND superseded_by IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS counterfactual_evaluations_signal_unique');
        DB::statement('DROP INDEX IF EXISTS counterfactual_evaluations_slot_unique');

        Schema::table('counterfactual_evaluations', function (Blueprint $table): void {
            if (Schema::hasColumn('counterfactual_evaluations', 'superseded_by')) {
                $table->dropColumn('superseded_by');
            }
        });
    }
};
