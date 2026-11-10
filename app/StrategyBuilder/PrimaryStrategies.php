<?php

declare(strict_types=1);

namespace App\StrategyBuilder;

use App\Strategy\Application\StrategyExchangeAssignmentService;
use App\Strategy\Application\StrategyRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Authoritative union of compiled PRIMARY identities and audited owner registrations. */
final class PrimaryStrategies
{
    public static function ids(?string $exchange = null): array
    {
        if ($exchange !== null) {
            return app(StrategyExchangeAssignmentService::class)->assignedStrategiesForExchange($exchange);
        }

        return app(StrategyRegistry::class)->ids();
    }

    public static function registrations(): array
    {
        if (! Schema::hasTable('strategy_primary_registrations')) {
            return [];
        }

        return DB::table('strategy_primary_registrations as r')->join('strategy_definitions as d', 'd.id', '=', 'r.definition_id')->join('strategy_definition_approvals as a', 'a.definition_id', '=', 'd.id')->whereColumn('r.strategy_id', 'd.strategy_id')->orderBy('r.strategy_id')->get(['r.*', 'd.definition', 'd.engine_checksum', 'd.checksum'])->all();
    }

    public static function byExchange(array $exchanges): array
    {
        $out = [];
        $assignments = app(StrategyExchangeAssignmentService::class);
        foreach ($exchanges as $exchange) {
            $out[$exchange] = $assignments->assignedStrategiesForExchange($exchange);
        }

        return $out;
    }
}
