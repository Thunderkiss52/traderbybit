<?php

declare(strict_types=1);

namespace Tests\Concerns;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

trait SeedsDslHistory
{
    private function seedDslHistory(string $from = '2026-01-15T00:00:00Z', string $to = '2026-01-16T00:00:00Z', string $exchange = 'bybit'): void
    {
        foreach ([5, 15, 30, 60] as $minutes) {
            $start = new DateTimeImmutable($from);
            $end = new DateTimeImmutable($to);
            $rows = [];
            for ($at = $start->modify('-'.(220 * $minutes).' minutes'); $at < $end; $at = $at->modify("+{$minutes} minutes")) {
                $price = (string) (100 + (intdiv($at->getTimestamp(), $minutes * 60) % 20));
                $rows[] = ['exchange' => $exchange, 'category' => 'linear', 'symbol' => 'BTCUSDT', 'interval' => (string) $minutes, 'open_time' => $at->format('Y-m-d H:i:sP'), 'open' => $price, 'high' => '125', 'low' => '95', 'close' => $price, 'volume' => '10', 'turnover' => '1000', 'is_closed' => true, 'created_at' => now('UTC'), 'updated_at' => now('UTC')];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('market_candles')->insertOrIgnore($chunk);
            }
        }
        $start = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($to);
        for ($at = (intdiv($start->getTimestamp(), 28800) + 1) * 28800; $at < $end->getTimestamp(); $at += 28800) {
            DB::table('market_funding_events')->insertOrIgnore(['exchange' => $exchange, 'category' => 'linear', 'symbol' => 'BTCUSDT', 'funding_at' => (new DateTimeImmutable('@'.$at))->format('Y-m-d H:i:sP'), 'rate' => '0.0001', 'mark_price' => '100', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        }
    }
}
