<?php

declare(strict_types=1);

namespace App\Exchanges\Binance;

use App\MarketData\Domain\Interval;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Repairs closed Binance candle history only. Persisted candles are the recovery checkpoint. */
final class BinanceCandleBackfillService
{
    private const MAX_CHUNK_SIZE = 500;

    private const MAX_PASSES = 3;

    public function __construct(private BinanceCandleIngestion $ingestion) {}

    public function backfill(string $symbol, string $interval, ?int $fromMs = null, ?int $toMs = null): array
    {
        $this->assertScope($symbol, $interval);
        $dbInterval = ['5m' => '5', '15m' => '15', '30m' => '30', '1h' => '60', '4h' => '240'][$interval];
        $stepMs = Interval::parse($dbInterval)->milliseconds();
        $nowMs = (int) floor(microtime(true) * 1000);
        $lastClosedExclusive = intdiv($nowMs - 120_000, $stepMs) * $stepMs;
        $requestedToMs = $toMs ?? $lastClosedExclusive;
        $requestedFromMs = $fromMs ?? max(0, $requestedToMs - (int) config('market_data.sync.backfill_history_ms', 7 * 86_400_000));
        if ($requestedFromMs < 0 || $requestedFromMs >= $requestedToMs) {
            throw new InvalidArgumentException('Candle range start must precede end.');
        }

        // The API range is half-open. Never request a forming candle, even when
        // an operator supplies an end time in the future.
        $startMs = intdiv($requestedFromMs + $stepMs - 1, $stepMs) * $stepMs;
        $endMs = min(intdiv($requestedToMs + $stepMs - 1, $stepMs) * $stepMs, $lastClosedExclusive);
        if ($startMs >= $endMs) {
            throw new InvalidArgumentException('No closed candles fall inside the requested range.');
        }

        $remaining = $this->missingInRange($symbol, $dbInterval, $startMs, $endMs);
        $windows = $this->windows($startMs, $endMs, $stepMs);
        $requested = 0;
        $inserted = 0;
        $conflicts = 0;

        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            foreach ($windows as [$windowFrom, $windowTo]) {
                $report = $this->ingestion->ingestRange($symbol, $interval, $windowFrom, $windowTo, true);
                $requested++;
                $inserted += (int) $report['new_closed_candles'];
                $conflicts += (int) $report['conflicts_count'];
            }

            $nextMissing = $this->missingInRange($symbol, $dbInterval, $startMs, $endMs);
            if ($nextMissing === [] || count($nextMissing) >= count($remaining)) {
                $remaining = $nextMissing;
                break;
            }
            $remaining = $nextMissing;
            $windows = $this->missingWindows($remaining, $stepMs);
        }

        return [
            'exchange_id' => 'binance',
            'symbol' => $symbol,
            'interval' => $dbInterval,
            'from_ms' => $startMs,
            'to_ms' => $endMs,
            'requested_count' => $requested,
            'inserted_count' => $inserted,
            'conflict_count' => $conflicts,
            'remaining_gap_count' => count($remaining),
            'remaining_from_ms' => $remaining,
            'status' => $conflicts > 0 ? 'QUARANTINED' : ($remaining === [] ? 'COMPLETED' : 'PARTIAL'),
            'execution_status' => 'PAPER_EXECUTION_BLOCKED_UNTIL_VERIFIED',
        ];
    }

    private function assertScope(string $symbol, string $interval): void
    {
        if (! in_array($symbol, (array) config('market_data.symbols', ['BTCUSDT', 'ETHUSDT']), true)) {
            throw new InvalidArgumentException('Market symbol not configured.');
        }
        if (! isset(['5m' => 1, '15m' => 1, '30m' => 1, '1h' => 1, '4h' => 1][$interval])) {
            throw new InvalidArgumentException('Interval not enabled for Binance research.');
        }
    }

    private function windows(int $fromMs, int $toMs, int $stepMs): array
    {
        $windows = [];
        for ($start = $fromMs; $start < $toMs; $start += self::MAX_CHUNK_SIZE * $stepMs) {
            $windows[] = [$start, min($start + self::MAX_CHUNK_SIZE * $stepMs, $toMs)];
        }

        return $windows;
    }

    private function missingWindows(array $missing, int $stepMs): array
    {
        $windows = [];
        $start = $previous = $missing[0];
        $count = 1;
        foreach (array_slice($missing, 1) as $openMs) {
            if ($openMs !== $previous + $stepMs || $count === self::MAX_CHUNK_SIZE) {
                $windows[] = [$start, $previous + $stepMs];
                $start = $openMs;
                $count = 0;
            }
            $previous = $openMs;
            $count++;
        }
        $windows[] = [$start, $previous + $stepMs];

        return $windows;
    }

    private function missingInRange(string $symbol, string $dbInterval, int $fromMs, int $toMs): array
    {
        $stepMs = Interval::parse($dbInterval)->milliseconds();
        $present = CandleRecord::query()
            ->where('exchange', 'binance')->where('category', 'linear')
            ->where('symbol', $symbol)->where('interval', $dbInterval)
            ->where('is_closed', true)
            ->where('open_time', '>=', CarbonImmutable::createFromTimestampMsUTC($fromMs))
            ->where('open_time', '<', CarbonImmutable::createFromTimestampMsUTC($toMs))
            ->pluck('open_time')
            ->mapWithKeys(static fn ($time): array => [$time->getTimestamp() * 1000 => true]);
        $missing = [];
        for ($openMs = $fromMs; $openMs < $toMs; $openMs += $stepMs) {
            if (! $present->has($openMs)) {
                $missing[] = $openMs;
            }
        }

        return $missing;
    }
}
