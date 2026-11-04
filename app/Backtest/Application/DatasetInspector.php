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
    public function inspect(array $symbols, DateTimeImmutable $from, DateTimeImmutable $to, int $warmupBars, string $exchange = 'bybit'): array
    {
        if ($from >= $to || $warmupBars < 0 || $warmupBars > 10000 || $symbols === []
            || $from->format('u') !== '000000' || $to->format('u') !== '000000'
            || $from->getTimestamp() % 300 !== 0 || $to->getTimestamp() % 300 !== 0) {
            throw new \InvalidArgumentException('Invalid historical dataset scope.');
        }
        if (! in_array($exchange, ['bybit', 'binance'], true)) {
            throw new \InvalidArgumentException('Unsupported backtest exchange.');
        }
        $hash = hash_init('sha256');
        $warnings = [];
        foreach ($symbols as $symbol) {
            // These are the four inputs consumed by StrategyInputFactory, including
            // the 30m setup frame. A row is available only after its close.
            foreach (['5' => 300, '15' => 900, '30' => 1800, '60' => 3600] as $interval => $seconds) {
                $firstExpectedOpen = $this->utcOpen(intdiv($from->getTimestamp(), $seconds) * $seconds);
                $lastExpectedOpen = $this->utcOpen(intdiv($to->getTimestamp() - $seconds, $seconds) * $seconds);
                // Even with a zero configured warm-up, the first decision can
                // consume the preceding closed bar of a higher timeframe.
                $warmupFrom = $firstExpectedOpen->modify('-'.(max($warmupBars, 1) * $seconds).' seconds');
                $rows = CandleRecord::query()->where(['exchange' => $exchange, 'category' => 'linear', 'symbol' => $symbol, 'interval' => $interval, 'is_closed' => true])
                    ->where('open_time', '>=', $this->timestamp($warmupFrom))->where('open_time', '<=', $this->timestamp($lastExpectedOpen))
                    ->orderBy('open_time')->cursor();
                $previous = null;
                $firstRequested = null;
                $before = 0;
                foreach ($rows as $row) {
                    $open = $row->open_time->toDateTimeImmutable();
                    if ($previous !== null && $open != $previous->modify('+'.$seconds.' seconds')) {
                        throw new RuntimeException("History gap for {$symbol} {$interval}m at ".$previous->modify('+'.$seconds.' seconds')->format(DATE_ATOM).'.');
                    }
                    if ($open < $firstExpectedOpen) {
                        $before++;
                    } elseif ($firstRequested === null) {
                        $firstRequested = $open;
                    }
                    $previous = $open;
                    hash_update($hash, implode('|', [$symbol, $interval, $open->format('U.u'), $row->open, $row->high, $row->low, $row->close, $row->volume, $row->turnover])."\n");
                }
                if ($previous === null) {
                    throw new RuntimeException("No closed {$interval}m history for {$symbol}.");
                }
                if ($firstRequested === null || $firstRequested != $firstExpectedOpen || $previous != $lastExpectedOpen) {
                    throw new RuntimeException("Incomplete requested range for {$symbol} {$interval}m.");
                }
                if ($before < $warmupBars) {
                    $warnings[] = "insufficient_warmup:{$symbol}:{$interval}:{$before}/{$warmupBars}";
                }
            }
            $contextScope = ['exchange' => $exchange, 'category' => 'linear', 'symbol' => $symbol];
            // The most recent pre-window observation can be reused by an as-of
            // lookup at the first simulated close.
            $priorContext = MarketContextRecord::query()->where($contextScope)->where('observed_at', '<', $this->timestamp($from))
                ->orderByDesc('observed_at')->orderByDesc('id')->first();
            if ($priorContext !== null) {
                $this->hashContext($hash, $priorContext);
            }
            MarketContextRecord::query()->where($contextScope)->where('observed_at', '>=', $this->timestamp($from))
                ->where('observed_at', '<=', $this->timestamp($to))->orderBy('observed_at')->orderBy('id')->each(function ($row) use ($hash): void {
                    $this->hashContext($hash, $row);
                });
            MarketFundingEventRecord::query()->where($contextScope)->where('funding_at', '>=', $this->timestamp($from))
                ->where('funding_at', '<=', $this->timestamp($to))->orderBy('funding_at')->orderBy('id')->each(function ($row) use ($hash): void {
                    hash_update($hash, implode('|', ['funding', $row->symbol, $row->funding_at->format('U.u'), $row->rate, $row->mark_price])."\n");
                });
        }

        return ['checksum' => hash_final($hash), 'warnings' => array_values(array_unique($warnings))];
    }

    private function timestamp(DateTimeImmutable $at): string
    {
        return $at->format('Y-m-d H:i:s.uP');
    }

    private function utcOpen(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable('@'.$timestamp))->setTimezone(new \DateTimeZone('UTC'));
    }

    private function hashContext(\HashContext $hash, MarketContextRecord $row): void
    {
        hash_update($hash, implode('|', ['context', $row->symbol, $row->observed_at->format('U.u'), $row->bid, $row->ask, $row->turnover_24h, $row->tick_size, $row->funding_rate, $row->open_interest, $row->open_interest_change, $row->qty_step, $row->min_order_qty, $row->max_order_qty, $row->min_notional_value])."\n");
    }
}
