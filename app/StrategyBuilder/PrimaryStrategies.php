<?php

declare(strict_types=1);

namespace App\StrategyBuilder;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Authoritative union of compiled PRIMARY identities and audited owner registrations. */
final class PrimaryStrategies
{
    public static function ids(?string $exchange = null): array
    {
        $ids = (array) config('paper.primary_strategies', []);
        foreach (self::registrations() as $row) {
            if ($exchange !== null && ! in_array($exchange, json_decode($row->exchanges, true), true)) {
                continue;
            }
            if (! in_array($row->strategy_id, $ids, true)) {
                $ids[] = $row->strategy_id;
            }
        }

        return $ids;
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
        $rows = self::registrations();
        foreach ($exchanges as $exchange) {
            $out[$exchange] = (array) config('paper.primary_strategies', []);
            foreach ($rows as $row) {
                if (in_array($exchange, json_decode($row->exchanges, true), true) && ! in_array($row->strategy_id, $out[$exchange], true)) {
                    $out[$exchange][] = $row->strategy_id;
                }
            }
        }

        return $out;
    }
}
