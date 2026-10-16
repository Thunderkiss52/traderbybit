<?php

declare(strict_types=1);

namespace App\MarketData\Jobs;

use App\MarketData\Domain\Interval;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Keep enough contiguous *closed* history for the strategy's indicator warm-up.
 * The regular six-hour gap recovery job cannot bootstrap EMA 200 on the 1h chart.
 */
final class EnsureStrategyHistoryJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function middleware(): array
    {
        return [(new WithoutOverlapping('market-strategy-history'))->expireAfter(180)->dontRelease()];
    }

    public function handle(): void
    {
        $nowMs = now('UTC')->getTimestampMs();
        $strategyHistoryLimit = max(1, (int) config('strategy.history_limit', 220));
        $researchDays = (bool) config('research.automatic.enabled', true)
            ? max(1, (int) config('research.automatic.evaluation_days', 14))
            : 0;
        $researchWarmupBars = max(1, (int) config('backtest.warmup_bars', 220));
        // Allow late closed-candle ingestion and avoid requesting a still-open bar.
        $graceMs = 120_000;

        foreach ((array) config('market_data.symbols', ['BTCUSDT', 'ETHUSDT']) as $symbol) {
            foreach ((array) config('market_data.intervals', ['5', '15', '60']) as $value) {
                $interval = Interval::parse((string) $value);
                $intervalMs = $interval->milliseconds();
                $researchHistoryLimit = $researchDays === 0
                    ? 0
                    : $researchWarmupBars + (int) ceil(($researchDays * 86_400_000) / $intervalMs);
                $historyLimit = max($strategyHistoryLimit, $researchHistoryLimit);
                $lastClosedOpenMs = intdiv($nowMs - $graceMs, $intervalMs) * $intervalMs - $intervalMs;
                $recent = CandleRecord::query()
                    ->where('exchange', 'bybit')->where('category', 'linear')
                    ->where('symbol', (string) $symbol)->where('interval', $interval->value)
                    ->where('is_closed', true)
                    ->where('open_time', '<=', CarbonImmutable::createFromTimestampMsUTC($lastClosedOpenMs))
                    ->latest('open_time')->limit($historyLimit)->pluck('open_time');

                $expected = $lastClosedOpenMs;
                $complete = $recent->count() === $historyLimit;
                foreach ($recent as $openTime) {
                    if ($openTime->getTimestamp() * 1000 !== $expected) {
                        $complete = false;
                        break;
                    }
                    $expected -= $intervalMs;
                }
                if ($complete) {
                    continue;
                }

                // Extra history absorbs API latency and helps keep the next evaluation ready.
                $fromMs = $lastClosedOpenMs - ($historyLimit + 24) * $intervalMs;
                SyncCandlesJob::dispatch((string) $symbol, $interval->value, $fromMs, $nowMs);
            }
        }
    }
}
