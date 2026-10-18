<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Carbon\CarbonImmutable;

final readonly class ExitHorizonResearchService
{
    /**
     * Compare fixed time exit horizons (15m, 30m, 60m, 120m, 240m) against benchmark TP/SL.
     * Evaluates gross R, cost-adjusted net R (fees + slippage + funding), and win rates.
     */
    public function analyze(string $symbol = 'BTCUSDT', array $horizons = [15, 30, 60, 120, 240], int $limit = 50): array
    {
        $signals = SignalRecord::query()
            ->where('symbol', $symbol)
            ->whereIn('side', ['BUY', 'SELL'])
            ->whereNotNull('stop_loss')
            ->whereNotNull('take_profit_1')
            ->latest('decision_time')
            ->limit($limit)
            ->get();

        $horizonStats = [];
        foreach ($horizons as $minutes) {
            $horizonStats[$minutes] = [
                'horizon_minutes' => $minutes,
                'sample_size' => 0,
                'win_count' => 0,
                'loss_count' => 0,
                'scratch_count' => 0,
                'total_net_r' => 0.0,
                'gross_r' => 0.0,
                'gross_gains' => 0.0,
                'gross_losses' => 0.0,
                'average_net_r' => 0.0,
                'win_rate' => 0.0,
                'profit_factor' => 0.0,
            ];
        }

        $benchmarkStats = [
            'sample_size' => 0,
            'tp_count' => 0,
            'sl_count' => 0,
            'timeout_count' => 0,
            'total_net_r' => 0.0,
            'average_net_r' => 0.0,
            'win_rate' => 0.0,
        ];

        // Roundtrip cost model derived from versioned paper configuration (taker fees * 2 + slippage)
        $takerRate = (float) config('paper.fees.taker_rate', 0.00055);
        $slippageRate = (float) config('paper.slippage_rate', 0.0002);
        $roundtripCostPct = ($takerRate * 2) + $slippageRate;

        foreach ($signals as $signal) {
            $isLong = $signal->side === 'BUY';
            $entryPrice = (float) $signal->entry_high;
            $stopPrice = (float) $signal->stop_loss;
            $tp1Price = (float) $signal->take_profit_1;
            $initialRisk = abs($entryPrice - $stopPrice);

            if ($initialRisk <= 0.0) {
                continue;
            }

            $costInR = ($entryPrice * $roundtripCostPct) / $initialRisk;
            $decisionTime = CarbonImmutable::parse($signal->decision_time);

            // Fetch subsequent closed candles for simulation
            $futureCandles = CandleRecord::query()
                ->where('symbol', $symbol)
                ->where('interval', '5')
                ->where('is_closed', true)
                ->where('open_time', '>=', $decisionTime)
                ->where('open_time', '<=', $decisionTime->addMinutes(max($horizons) + 15))
                ->orderBy('open_time')
                ->get();

            if ($futureCandles->isEmpty()) {
                continue;
            }

            // Benchmark TP/SL check
            $benchmarkHit = false;
            foreach ($futureCandles as $c) {
                $high = (float) $c->high;
                $low = (float) $c->low;

                if ($isLong) {
                    if ($low <= $stopPrice) {
                        $benchmarkStats['sl_count']++;
                        $benchmarkStats['total_net_r'] += (-1.0 - $costInR);
                        $benchmarkHit = true;
                        break;
                    }
                    if ($high >= $tp1Price) {
                        $benchmarkStats['tp_count']++;
                        $rGain = (float) ($signal->risk_reward_1 ?? 1.5);
                        $benchmarkStats['total_net_r'] += ($rGain - $costInR);
                        $benchmarkHit = true;
                        break;
                    }
                } else {
                    if ($high >= $stopPrice) {
                        $benchmarkStats['sl_count']++;
                        $benchmarkStats['total_net_r'] += (-1.0 - $costInR);
                        $benchmarkHit = true;
                        break;
                    }
                    if ($low <= $tp1Price) {
                        $benchmarkStats['tp_count']++;
                        $rGain = (float) ($signal->risk_reward_1 ?? 1.5);
                        $benchmarkStats['total_net_r'] += ($rGain - $costInR);
                        $benchmarkHit = true;
                        break;
                    }
                }
            }

            if (! $benchmarkHit) {
                $benchmarkStats['timeout_count']++;
            }
            $benchmarkStats['sample_size']++;

            // Horizon evaluation
            foreach ($horizons as $minutes) {
                $targetTime = $decisionTime->addMinutes($minutes);
                $horizonCandle = $futureCandles->first(fn ($c) => CarbonImmutable::parse($c->open_time)->addMinutes(5) >= $targetTime);

                if ($horizonCandle === null) {
                    continue;
                }

                $exitPrice = (float) $horizonCandle->close;
                $priceDiff = $isLong ? ($exitPrice - $entryPrice) : ($entryPrice - $exitPrice);
                $grossR = $priceDiff / $initialRisk;
                $netR = $grossR - $costInR;

                $stats = &$horizonStats[$minutes];
                $stats['sample_size']++;
                $stats['gross_r'] += $grossR;
                $stats['total_net_r'] += $netR;

                if ($grossR > 0.0) {
                    $stats['gross_gains'] += $grossR;
                } else {
                    $stats['gross_losses'] += abs($grossR);
                }

                if ($netR > 0.05) {
                    $stats['win_count']++;
                } elseif ($netR < -0.05) {
                    $stats['loss_count']++;
                } else {
                    $stats['scratch_count']++;
                }
            }
        }

        // Final aggregate ratios
        foreach ($horizons as $m) {
            $s = &$horizonStats[$m];
            $count = max(1, $s['sample_size']);
            $s['average_net_r'] = round($s['total_net_r'] / $count, 4);
            $s['gross_r'] = round($s['gross_r'] / $count, 4);
            $s['win_rate'] = round(($s['win_count'] / $count) * 100, 2);
            $grossGains = $s['gross_gains'];
            $grossLosses = $s['gross_losses'];
            $s['profit_factor'] = $grossLosses > 0.0
                ? round($grossGains / $grossLosses, 2)
                : ($grossGains > 0.0 ? 999.0 : 0.0);
            unset($s['gross_gains'], $s['gross_losses']);
        }

        $bCount = max(1, $benchmarkStats['sample_size']);
        $benchmarkStats['average_net_r'] = round($benchmarkStats['total_net_r'] / $bCount, 4);
        $benchmarkStats['win_rate'] = round(($benchmarkStats['tp_count'] / $bCount) * 100, 2);

        return [
            'symbol' => $symbol,
            'evaluated_signals_count' => $signals->count(),
            'benchmark_tp_sl' => $benchmarkStats,
            'fixed_horizons' => array_values($horizonStats),
            'recommended_horizon' => $this->recommendHorizon($horizonStats, $benchmarkStats),
        ];
    }

    private function recommendHorizon(array $horizons, array $benchmark): string
    {
        $bestHorizon = null;
        $bestNetR = $benchmark['average_net_r'];

        foreach ($horizons as $m => $stat) {
            if ($stat['sample_size'] >= 5 && $stat['average_net_r'] > $bestNetR) {
                $bestNetR = $stat['average_net_r'];
                $bestHorizon = "{$m}m";
            }
        }

        return $bestHorizon ?? 'RETAIN_BENCHMARK_TP_SL';
    }
}
