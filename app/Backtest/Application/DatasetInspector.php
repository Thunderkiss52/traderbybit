<?php

declare(strict_types=1);

namespace App\Backtest\Application;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Infrastructure\Persistence\MarketFundingEventRecord;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use DateTimeImmutable;
use RuntimeException;

final class DatasetInspector
{
    public function inspect(array $symbols, DateTimeImmutable $from, DateTimeImmutable $to, int $warmupBars): array
    {
        $hash = hash_init('sha256');
        $warnings = [];
        foreach ($symbols as $symbol) {
            foreach (['5' => 300, '15' => 900, '60' => 3600] as $interval => $seconds) {
                $warmupFrom = $from->modify('-'.($warmupBars * $seconds).' seconds');
                $rows = CandleRecord::query()->where(['exchange' => 'bybit', 'category' => 'linear', 'symbol' => $symbol, 'interval' => $interval, 'is_closed' => true])
                    ->where('open_time', '>=', $warmupFrom)->where('open_time', '<', $to)->orderBy('open_time')->get();
                if ($rows->isEmpty()) {
                    throw new RuntimeException("No closed {$interval}m history for {$symbol}.");
                }
                $firstRequested = $rows->first(fn ($row): bool => $row->open_time >= $from);
                $last = $rows->last();
                if ($firstRequested === null || $firstRequested->open_time->getTimestamp() > $from->getTimestamp() || $last->open_time->getTimestamp() + $seconds < $to->getTimestamp()) {
                    throw new RuntimeException("Incomplete requested range for {$symbol} {$interval}m.");
                }
                $previous = null;
                foreach ($rows as $row) {
                    $timestamp = $row->open_time->getTimestamp();
                    if ($previous !== null && $timestamp !== $previous + $seconds) {
                        throw new RuntimeException("History gap for {$symbol} {$interval}m at ".gmdate('c', $previous + $seconds).'.');
                    }
                    $previous = $timestamp;
                    hash_update($hash, implode('|', [$symbol, $interval, $timestamp, $row->open, $row->high, $row->low, $row->close, $row->volume, $row->turnover])."\n");
                }
                $before = $rows->filter(fn ($row): bool => $row->open_time < $from)->count();
                if ($before < $warmupBars) {
                    $warnings[] = "insufficient_warmup:{$symbol}:{$interval}:{$before}/{$warmupBars}";
                }
            }
            MarketContextRecord::query()->where(['exchange' => 'bybit', 'category' => 'linear', 'symbol' => $symbol])->whereBetween('observed_at', [$from, $to])->orderBy('observed_at')->each(function ($row) use ($hash): void {
                hash_update($hash, implode('|', ['context', $row->symbol, $row->observed_at->getTimestamp(), $row->bid, $row->ask, $row->turnover_24h, $row->tick_size, $row->funding_rate, $row->open_interest, $row->open_interest_change, $row->qty_step, $row->min_order_qty, $row->max_order_qty, $row->min_notional_value])."\n");
            });
            MarketFundingEventRecord::query()->where(['exchange' => 'bybit', 'category' => 'linear', 'symbol' => $symbol])->whereBetween('funding_at', [$from, $to])->orderBy('funding_at')->each(function ($row) use ($hash): void {
                hash_update($hash, implode('|', ['funding', $row->symbol, $row->funding_at->getTimestamp(), $row->rate, $row->mark_price])."\n");
            });
        }

        return ['checksum' => hash_final($hash), 'warnings' => array_values(array_unique($warnings))];
    }
}
