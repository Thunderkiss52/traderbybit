<?php

declare(strict_types=1);

namespace App\HistoricalData;

use App\Exchanges\Binance\BinanceCandleIngestion;
use App\Strategy\Application\StrategyRegistry;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class HistoricalWriter
{
    public function __construct(private BinanceCandleIngestion $candles) {}

    public function write(object $entry, array $rows, string $revision): array
    {
        if ($entry->family === 'klines') {
            $input = array_map(fn ($r) => ['exchange_id' => 'binance', 'symbol' => $entry->symbol, 'interval' => $entry->interval, 'open_time_utc' => CarbonImmutable::createFromTimestampMs($r['time'], 'UTC')->toISOString(), 'is_closed' => true, 'open' => $r['open'], 'high' => $r['high'], 'low' => $r['low'], 'close' => $r['close'], 'volume' => $r['volume'], 'turnover' => $r['turnover']], $rows);
            $result = $this->candles->persistHistoricalBatch($input, $entry->symbol, $entry->interval);

            return ['inserted' => $result['new_closed_candles'], 'conflicts' => $result['conflicts_count'], 'duplicates' => count($rows) - $result['new_closed_candles'] - $result['conflicts_count']];
        }
        $funding = in_array($entry->family, ['fundingRate', 'fundingApi'], true);
        $table = $funding ? 'historical_funding_observations' : 'historical_mark_candles';
        $times = array_map(fn ($r) => CarbonImmutable::createFromTimestampMs($r['time'], 'UTC')->toISOString(), $rows);
        $known = DB::table($table)->where('symbol', $entry->symbol)->whereIn($funding ? 'funding_at' : 'open_time', $times)->when(! $funding, fn ($q) => $q->where('interval', $entry->interval))->get()->groupBy(fn ($r) => CarbonImmutable::parse($funding ? $r->funding_at : $r->open_time)->getTimestampMs());
        $inserted = 0;
        $duplicates = 0;
        $conflicts = 0;
        $pending = [];
        foreach ($rows as $row) {
            $at = CarbonImmutable::createFromTimestampMs($row['time'], 'UTC')->toISOString();
            $funding = in_array($entry->family, ['fundingRate', 'fundingApi'], true);
            $table = $funding ? 'historical_funding_observations' : 'historical_mark_candles';
            $identity = ['batch_id' => $entry->batch_id, 'source_revision' => $revision, 'symbol' => $entry->symbol, $funding ? 'funding_at' : 'open_time' => $at];
            $values = $funding ? ['exchange' => 'binance', 'category' => 'linear', 'rate' => $row['rate'], 'interval_hours' => $row['hours'] ?? null, 'mark_price' => $row['mark'] ?? null, 'mark_source' => isset($row['mark']) ? 'BINANCE_FUNDING_SETTLEMENT_API' : null] : ['interval' => $entry->interval, 'open' => $row['open'], 'high' => $row['high'], 'low' => $row['low'], 'close' => $row['close']];
            if (! $funding) {
                $identity['interval'] = $entry->interval;
            }
            $compared = $known->get($row['time'], collect());
            $bad = false;
            foreach ($compared as $stored) {
                foreach ($funding ? ['rate', 'mark_price'] : ['open', 'high', 'low', 'close'] as $field) {
                    if ($values[$field] !== null && $stored->{$field} !== null && ! BigDecimal::of($values[$field])->isEqualTo($stored->{$field})) {
                        $bad = true;
                        $this->conflict($entry, $at, (array) $stored, $values);
                        break 2;
                    }
                }
            }
            if ($bad) {
                $conflicts++;

                continue;
            }
            $pending[] = $identity + $values + ['created_at' => now('UTC')];
        }
        foreach (array_chunk($pending, 500) as $chunk) {
            $inserted += DB::table($table)->insertOrIgnore($chunk);
        }
        $duplicates = count($pending) - $inserted;

        return compact('inserted', 'duplicates', 'conflicts');
    }

    private function conflict(object $entry, string $at, array $stored, array $incoming): void
    {
        $key = StrategyRegistry::hash([$entry->id, $at, $stored, $incoming]);
        DB::table('historical_data_conflicts')->insertOrIgnore(['batch_id' => $entry->batch_id, 'entry_id' => $entry->id, 'family' => $entry->family, 'symbol' => $entry->symbol, 'at' => $at, 'stored' => json_encode($stored), 'incoming' => json_encode($incoming), 'fingerprint' => $key, 'status' => 'UNRESOLVED', 'created_at' => now('UTC')]);
    }
}
