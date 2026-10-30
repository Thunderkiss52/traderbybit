<?php

declare(strict_types=1);

namespace App\Exchanges\Binance;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/** Deterministic Binance historical candle backfill. Paper-only, never places orders. */
final class BinanceCandleBackfillService
{
    private const MAX_CHUNK_SIZE = 500;
    private const MAX_PASSES = 3;

    public function __construct(
        private BinanceCandleIngestion $ingestion,
    ) {}

    public function backfill(string $symbol, string $interval, ?int $fromMs = null, ?int $toMs = null): array
    {
        $this->assertScope($symbol, $interval);
        $dbInterval = $this->dbInterval($interval);
        $intervalObj = Interval::parse($dbInterval);
        $stepMs = $intervalObj->milliseconds();
        $nowMs = (int) floor(microtime(true) * 1000);
        $lastClosedOpenMs = intdiv($nowMs - 120_000, $stepMs) * $stepMs - $stepMs;
        $effectiveToMs = $toMs ?? $lastClosedOpenMs;
        $effectiveFromMs = $fromMs ?? max(0, $effectiveToMs - (int) config('market_data.sync.backfill_history_ms', 7 * 86_400_000));
        $attemptToken = bin2hex(random_bytes(16));

        $previous = \App\MarketData\Infrastructure\Persistence\BinanceCandleBackfillEventRecord::query()
            ->where('exchange','binance')->where('symbol',$symbol)->where('interval',$dbInterval)
            ->where('from_ms','<=',$effectiveFromMs)->where('to_ms','>=',$effectiveToMs)
            ->where('status','COMPLETED')->latest('completed_at')->first();
        if ($previous !== null) {
            return array_merge($previous->report ?? [], ['status' => 'COMPLETED', 'replayed' => true, 'previous_completed_at' => $previous->completed_at?->toIso8601String()]);
        }

        $report = $this->runRange($symbol, $dbInterval, $effectiveFromMs, $effectiveToMs, $attemptToken);
        \App\MarketData\Infrastructure\Persistence\BinanceCandleBackfillEventRecord::query()->insertOrIgnore(array_merge([
            'exchange' => 'binance', 'symbol' => $symbol, 'interval' => $dbInterval,
            'from_ms' => $effectiveFromMs, 'to_ms' => $effectiveToMs,
            'attempt_token' => $attemptToken, 'completed_at' => CarbonImmutable::now('UTC'),
        ], $report));
        return $report;
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

    private function dbInterval(string $interval): string
    {
        return ['5m' => '5', '15m' => '15', '30m' => '30', '1h' => '60', '4h' => '240'][$interval];
    }

    private function apiInterval(string $dbInterval): string
    {
        return ['5' => '5m', '15' => '15m', '30' => '30m', '60' => '1h', '240' => '4h'][$dbInterval];
    }

    private function runRange(string $symbol, string $dbInterval, int $fromMs, int $toMs, string $attemptToken): array
    {
        $stepMs = Interval::parse($dbInterval)->milliseconds();
        $chunks = [];
        $pos = $fromMs;
        while ($pos < $toMs) {
            $end = min(self::MAX_CHUNK_SIZE * $stepMs, $toMs);
            if ($end <= $pos) { break; }
            $chunks[] = [$pos, $end];
            $pos = $end;
        }
        $requested = 0;
        $inserted = 0;
        $conflicts = 0;
        $integrityFailure = null;
        $remaining = [];
        $maxPasses = self::MAX_PASSES;

        for ($pass = 0; $pass < $maxPasses; $pass++) {
            $progress = false;
            foreach ($chunks as $i => [$chunkFrom, $chunkTo]) {
                $requested++;
                $progress = true;
                $rows = $this->ingestion->ingestRange($symbol, $this->apiInterval($dbInterval), $chunkFrom, $chunkTo, true);
                $inserted += (int) ($rows['new_closed_candles'] ?? 0);
                $conflicts += (int) ($rows['conflicts_count'] ?? 0);
                if ($rows['status'] === 'INTEGRITY_UNAVAILABLE') {
                    $integrityFailure ??= $rows['execution_status'];
                }
                $missing = $this->missingInRange($symbol, $dbInterval, $chunkFrom, $chunkTo);
                if ($missing === []) {
                    unset($chunks[$i]);
                }
            }
            if (empty($chunks)) {
                break;
            }
            if (! $progress) {
                break;
            }
        }

        foreach ($chunks as [$cf, $ct]) {
            $remaining[] = $cf;
        }

        $status = empty($chunks) ? 'COMPLETED' : ($remaining === [] ? 'COMPLETED' : 'PARTIAL');
        if ($integrityFailure !== null) {
            $status = 'QUARANTINED';
        }

        $report = [
            'exchange_id' => 'binance',
            'symbol' => $symbol,
            'interval' => $dbInterval,
            'from_ms' => $effectiveFromMs,
            'to_ms' => $effectiveToMs,
            'attempt_token' => $attemptToken,
            'requested_count' => $requested,
            'inserted_count' => $inserted,
            'conflict_count' => $conflicts,
            'remaining_gap_count' => count($remaining),
            'remaining_from_ms' => $remaining,
            'status' => $status,
            'execution_status' => 'PAPER_EXECUTION_BLOCKED_UNTIL_VERIFIED',
        ];
        if ($integrityFailure !== null) {
            $report['integrity_failure'] = $integrityFailure;
        }
        return $report;
    }

    private function missingInRange(string $symbol, string $dbInterval, int $fromMs, int $toMs): array
    {
        $stepMs = Interval::parse($dbInterval)->milliseconds();
        $expected = [];
        $pos = $fromMs;
        while ($pos < $toMs) {
            $expected[] = $pos;
            $pos += $stepMs;
        }
        if ($expected === []) {
            return [];
        }
        $rows = CandleRecord::query()
            ->where('exchange', 'binance')->where('category', 'linear')
            ->where('symbol', $symbol)->where('interval', $dbInterval)
            ->where('is_closed', true)
            ->where('open_time', '>=', CarbonImmutable::createFromTimestampMsUTC($fromMs))
            ->where('open_time', '<', CarbonImmutable::createFromTimestampMsUTC($toMs))
            ->pluck('open_time')
            ->map(function ($t): int { return $t->getTimestamp() * 1000; })
            ->all();
        $present = array_flip($rows);
        $missing = [];
        foreach ($expected as $openMs) {
            if (! isset($present[$openMs])) {
                $missing[] = $openMs;
            }
        }
        return $missing;
    }
}
