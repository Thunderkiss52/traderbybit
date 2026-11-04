<?php

declare(strict_types=1);

namespace App\Exchanges\Application;

use App\Exchanges\ExchangeRegistry;
use App\Exchanges\Infrastructure\Persistence\ExchangeRecord;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final readonly class ExchangeCoverageService
{
    public function __construct(
        private ExchangeRegistry $registry,
    ) {}

    /**
     * Generate an audit coverage and diagnostic report for an exchange.
     *
     * @return array<string, mixed>
     */
    public function report(string $exchangeId): array
    {
        $exchange = ExchangeRecord::query()->findOrFail(strtolower(trim($exchangeId)));
        $now = CarbonImmutable::now('UTC');

        // Series coverage
        $series = CandleRecord::query()
            ->where('exchange', $exchange->id)
            ->selectRaw('symbol, interval, count(*) as count, min(open_time) as earliest, max(open_time) as latest')
            ->groupBy('symbol', 'interval')
            ->orderBy('symbol')
            ->orderBy('interval')
            ->get();

        $seriesData = [];
        $totalCandles = 0;
        $uniqueSymbols = [];

        foreach ($series as $row) {
            $symbol = (string) $row->symbol;
            $interval = (string) $row->interval;
            $count = (int) $row->count;
            $totalCandles += $count;
            $uniqueSymbols[$symbol] = true;

            $earliestDt = $row->earliest ? new DateTimeImmutable((string) $row->earliest) : null;
            $latestDt = $row->latest ? new DateTimeImmutable((string) $row->latest) : null;

            $spanSeconds = ($earliestDt !== null && $latestDt !== null)
                ? max(0, $latestDt->getTimestamp() - $earliestDt->getTimestamp())
                : 0;

            $intervalSec = $this->intervalToSeconds($interval);
            $expected = $intervalSec > 0 && $spanSeconds > 0
                ? (int) floor($spanSeconds / $intervalSec) + 1
                : $count;

            $completeness = $expected > 0 ? round(min(100.0, ($count / $expected) * 100), 2) : 100.0;
            $lagSeconds = $latestDt !== null ? max(0, $now->getTimestamp() - $latestDt->getTimestamp() - $intervalSec) : null;

            $seriesData[] = [
                'symbol' => $symbol,
                'interval' => $interval,
                'count' => $count,
                'earliest' => $earliestDt?->format(DATE_ATOM),
                'latest' => $latestDt?->format(DATE_ATOM),
                'span_hours' => round($spanSeconds / 3600, 1),
                'expected_bars' => $expected,
                'completeness_percent' => $completeness,
                'lag_seconds' => $lagSeconds,
                'is_fresh' => $lagSeconds !== null && $lagSeconds <= ($intervalSec * 3),
            ];
        }

        // Candle conflicts
        $unresolvedConflicts = DB::table('market_candle_conflicts')
            ->where('exchange', $exchange->id)
            ->where('status', 'UNRESOLVED')
            ->count();

        // Dataset pins
        $pinsCount = DB::table('historical_dataset_pins')
            ->where('exchange', $exchange->id)
            ->count();

        // Funding events
        $fundingCount = Schema::hasTable('market_funding_events')
            ? DB::table('market_funding_events')->where('exchange', $exchange->id)->count()
            : 0;

        // Open interest events
        $oiCount = Schema::hasTable('market_open_interest_events')
            ? DB::table('market_open_interest_events')->where('exchange', $exchange->id)->count()
            : 0;

        $hasLiveAdapter = $this->registry->hasAdapter($exchange->id);

        return [
            'exchange_id' => $exchange->id,
            'name' => $exchange->name,
            'lifecycle_status' => $exchange->lifecycle_status,
            'total_candles' => $totalCandles,
            'unique_symbols_count' => count($uniqueSymbols),
            'series_count' => count($seriesData),
            'series' => $seriesData,
            'unresolved_conflicts' => $unresolvedConflicts,
            'dataset_pins_count' => $pinsCount,
            'funding_events_count' => $fundingCount,
            'open_interest_events_count' => $oiCount,
            'diagnostics' => [
                'has_live_adapter' => $hasLiveAdapter,
                'adapter_status' => $hasLiveAdapter ? 'CONNECTED' : ($totalCandles > 0 ? 'OFFLINE_DATA_SOURCE' : 'UNINITIALIZED'),
                'public_data_fresh' => ! empty($seriesData) && (collect($seriesData)->contains('is_fresh', true)),
                'paper_approved' => $exchange->paper_approved,
                'paper_approved_at' => $exchange->paper_approved_at?->format(DATE_ATOM),
            ],
            'generated_at' => $now->toISOString(),
        ];
    }

    private function intervalToSeconds(string $interval): int
    {
        return match (strtoupper($interval)) {
            '1' => 60,
            '3' => 180,
            '5' => 300,
            '15' => 900,
            '30' => 1800,
            '60', '1H' => 3600,
            '120', '2H' => 7200,
            '240', '4H' => 14400,
            'D', '1D' => 86400,
            'W', '1W' => 604800,
            default => (int) $interval * 60,
        };
    }
}
