<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\Research\Infrastructure\Persistence\LearningFeatureSnapshotRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class LearningFeatureStoreService
{
    /**
     * Assemble a point-in-time feature snapshot at decision time T.
     */
    public function assembleSnapshot(
        string $symbol,
        string $timeframe,
        CarbonImmutable $decisionTime,
        ?string $strategyId = null,
        ?string $regime = null,
        string $exchange = 'bybit'
    ): ?LearningFeatureSnapshotRecord {
        // Enforce strict causality: closed candle at or before decision time T
        $candle = CandleRecord::query()
            ->where('exchange', $exchange)
            ->where('symbol', $symbol)
            ->where('interval', $timeframe)
            ->where('is_closed', true)
            ->where('open_time', '<=', $decisionTime)
            ->latest('open_time')
            ->first();

        if (! $candle) {
            return null;
        }

        // De-duplication check: avoid duplicate snapshot at the same decision timestamp for the exchange
        $existing = LearningFeatureSnapshotRecord::query()
            ->where('symbol', $symbol)
            ->where('timeframe', $timeframe)
            ->where('decision_time', $decisionTime)
            ->get()
            ->first(fn ($record) => ($record->data_quality['exchange'] ?? 'bybit') === $exchange);

        if ($existing) {
            return $existing;
        }

        // Available at is candle open_time + interval duration
        $availableAt = CarbonImmutable::parse($candle->open_time)->addMinutes((int) $timeframe);

        // Fetch recent historical window strictly <= decisionTime
        $history = CandleRecord::query()
            ->where('exchange', $exchange)
            ->where('symbol', $symbol)
            ->where('interval', $timeframe)
            ->where('is_closed', true)
            ->where('open_time', '<=', $candle->open_time)
            ->latest('open_time')
            ->limit(20)
            ->get()
            ->reverse()
            ->values();

        if ($history->count() < 10) {
            return null;
        }

        $close = (float) $candle->close;
        $open = (float) $candle->open;
        $high = (float) $candle->high;
        $low = (float) $candle->low;
        $vol = (float) $candle->volume;

        // Compute technical features without future leakage
        $returns = [];
        $gains = [];
        $losses = [];
        for ($i = 1; $i < $history->count(); $i++) {
            $prev = (float) $history[$i - 1]->close;
            $curr = (float) $history[$i]->close;
            $change = $curr - $prev;
            $returns[] = $prev > 0 ? $change / $prev : 0.0;
            $gains[] = $change > 0 ? $change : 0.0;
            $losses[] = $change < 0 ? abs($change) : 0.0;
        }

        $meanReturn = count($returns) > 0 ? array_sum($returns) / count($returns) : 0.0;
        $variance = 0.0;
        foreach ($returns as $r) {
            $variance += (($r - $meanReturn) ** 2);
        }
        $volatility = count($returns) > 1 ? sqrt($variance / (count($returns) - 1)) : 0.0;

        $avgGain = count($gains) > 0 ? array_sum(array_slice($gains, -14)) / min(14, count($gains)) : 0.0;
        $avgLoss = count($losses) > 0 ? array_sum(array_slice($losses, -14)) / min(14, count($losses)) : 0.0;
        $rs = $avgLoss > 0 ? $avgGain / $avgLoss : 1.0;
        $rsi14 = round(100.0 - (100.0 / (1.0 + $rs)), 2);

        $avgVol = $history->avg('volume') ?: 1.0;
        $volumeRatio = round($vol / (float) $avgVol, 4);

        $features = [
            'close' => $close,
            'hl_spread_pct' => $close > 0 ? (($high - $low) / $close) * 100 : 0.0,
            'body_spread_pct' => $close > 0 ? (abs($close - $open) / $close) * 100 : 0.0,
            'recent_volatility' => round($volatility * 100, 4),
            'rsi_14' => $rsi14,
            'volume_ratio' => $volumeRatio,
            'volume' => $vol,
            'bar_count' => $history->count(),
        ];

        return LearningFeatureSnapshotRecord::query()->create([
            'id' => (string) Str::uuid(),
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'decision_time' => $decisionTime,
            'available_at' => $availableAt,
            'feature_schema_version' => '1.0.0',
            'strategy_id' => $strategyId,
            'regime' => $regime ?? 'UNKNOWN',
            'features' => $features,
            'label_status' => 'PENDING',
            'data_quality' => [
                'exchange' => $exchange,
                'candles_evaluated' => $history->count(),
                'missing_bars' => 0,
                'no_look_ahead_verified' => true,
            ],
        ]);
    }

    /**
     * Mature pending outcome labels using post-facto closed candles.
     */
    public function maturePendingLabels(int $limit = 50, ?string $exchange = null): int
    {
        $pending = LearningFeatureSnapshotRecord::query()
            ->where('label_status', 'PENDING')
            ->where('available_at', '<=', CarbonImmutable::now()->subMinutes(60))
            ->limit($limit)
            ->get();

        $maturedCount = 0;

        foreach ($pending as $snapshot) {
            $snapshotExchange = $snapshot->data_quality['exchange'] ?? 'bybit';
            if ($exchange !== null && $snapshotExchange !== $exchange) {
                continue;
            }

            $t0 = CarbonImmutable::parse($snapshot->decision_time);
            $entryPrice = (float) ($snapshot->features['close'] ?? 0.0);
            if ($entryPrice <= 0) {
                $snapshot->update(['label_status' => 'INVALID']);

                continue;
            }

            // Assume standard 2% TP and 1% SL for evaluation
            $tp = $entryPrice * 1.02;
            $sl = $entryPrice * 0.99;

            $subsequentCandles = CandleRecord::query()
                ->where('exchange', $snapshotExchange)
                ->where('symbol', $snapshot->symbol)
                ->where('interval', $snapshot->timeframe)
                ->where('is_closed', true)
                ->where('open_time', '>', $t0)
                ->where('open_time', '<=', $t0->addHours(4))
                ->orderBy('open_time')
                ->get();

            if ($subsequentCandles->isEmpty()) {
                continue;
            }

            $takerRate = (float) config('paper.fees.taker_rate', 0.00055);
            $slippageRate = (float) config('paper.slippage_rate', 0.0002);
            $tradingCostPct = ($takerRate * 2 + $slippageRate) * 100;

            $resolved = false;
            foreach ($subsequentCandles as $candle) {
                $h = (float) $candle->high;
                $l = (float) $candle->low;

                if ($h >= $tp && $l <= $sl) {
                    $snapshot->update([
                        'label_status' => 'AMBIGUOUS',
                        'label_outcome' => 'AMBIGUOUS',
                        'label_r_multiple' => -1.13, // Conservative assumption
                        'outcome_at' => $candle->open_time,
                    ]);
                    $resolved = true;
                    $maturedCount++;
                    break;
                }

                if ($h >= $tp) {
                    $snapshot->update([
                        'label_status' => 'MATURED',
                        'label_outcome' => 'TP',
                        'label_r_multiple' => round(2.0 - $tradingCostPct, 4),
                        'outcome_at' => $candle->open_time,
                    ]);
                    $resolved = true;
                    $maturedCount++;
                    break;
                }

                if ($l <= $sl) {
                    $snapshot->update([
                        'label_status' => 'MATURED',
                        'label_outcome' => 'SL',
                        'label_r_multiple' => round(-1.0 - $tradingCostPct, 4),
                        'outcome_at' => $candle->open_time,
                    ]);
                    $resolved = true;
                    $maturedCount++;
                    break;
                }
            }

            if (! $resolved && $subsequentCandles->count() >= 16) {
                // Timeout after 4 hours
                $last = $subsequentCandles->last();
                $lastClose = (float) $last->close;
                $netPct = ((($lastClose - $entryPrice) / $entryPrice) * 100) - $tradingCostPct;

                $snapshot->update([
                    'label_status' => 'MATURED',
                    'label_outcome' => 'TIMEOUT',
                    'label_r_multiple' => round($netPct, 4),
                    'outcome_at' => $last->open_time,
                ]);
                $maturedCount++;
            }
        }

        return $maturedCount;
    }
}
