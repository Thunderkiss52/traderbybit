<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('exchanges')) {
            return;
        }

        $now = now('UTC');
        $record = DB::table('exchanges')->where('id', 'binance')->first();
        if ($record === null) {
            return;
        }

        $currentCost = is_string($record->cost_model) ? json_decode($record->cost_model, true) : (array) ($record->cost_model ?? []);
        $configCost = config('exchanges.binance.cost_model', []);

        $mergedCost = array_merge($currentCost ?: [], $configCost ?: []);
        $mergedCost['maker_rate'] = $mergedCost['maker_rate'] ?? '0.0002';
        $mergedCost['taker_rate'] = $mergedCost['taker_rate'] ?? $mergedCost['reference_taker_rate'] ?? '0.0005';
        $mergedCost['slippage_rate'] = $mergedCost['slippage_rate'] ?? $mergedCost['reference_slippage_rate'] ?? '0.0002';

        $currentAssumptions = is_string($record->paper_execution_assumptions)
            ? json_decode($record->paper_execution_assumptions, true)
            : (array) ($record->paper_execution_assumptions ?? []);

        $assumptions = array_merge([
            'execution_model' => 'NEXT_CANDLE_OPEN',
            'ambiguous_execution_policy' => 'FLAG_AND_HOLD',
        ], $currentAssumptions ?: []);

        $updates = [
            'cost_model' => json_encode($mergedCost, JSON_THROW_ON_ERROR),
            'paper_execution_assumptions' => json_encode($assumptions, JSON_THROW_ON_ERROR),
            'paper_eligible' => true,
            'updated_at' => $now,
        ];

        if ((bool) config('exchanges.binance.paper_execution_approved', false) || (bool) config('exchanges.auto_approve_eligible', false)) {
            $updates['paper_approved'] = true;
            $updates['paper_approved_at'] = $record->paper_approved_at ?? $now;
            $updates['lifecycle_status'] = 'ACTIVE_PAPER';
        }

        DB::table('exchanges')->where('id', 'binance')->update($updates);
    }

    public function down(): void
    {
        if (! Schema::hasTable('exchanges')) {
            return;
        }

        $record = DB::table('exchanges')->where('id', 'binance')->first();
        if ($record === null) {
            return;
        }

        $currentCost = is_string($record->cost_model) ? json_decode($record->cost_model, true) : (array) ($record->cost_model ?? []);
        unset($currentCost['maker_rate'], $currentCost['taker_rate'], $currentCost['slippage_rate']);

        DB::table('exchanges')->where('id', 'binance')->update([
            'cost_model' => json_encode($currentCost, JSON_THROW_ON_ERROR),
            'paper_execution_assumptions' => null,
            'paper_eligible' => false,
            'paper_approved' => false,
            'paper_approved_at' => null,
            'lifecycle_status' => 'PAPER_ELIGIBLE',
            'updated_at' => now('UTC'),
        ]);
    }
};
