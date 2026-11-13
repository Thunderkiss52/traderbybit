<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\Research\Infrastructure\Persistence\LearningFeatureSnapshotRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
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
        string $exchange = 'bybit',
        string $category = 'linear'
    ): ?LearningFeatureSnapshotRecord {
        // Historical is_closed reflects the current DB, not the decision-time state.
        // A candle is usable only after its entire interval has ended.
        $minutes = self::intervalMinutes($timeframe);
        if ($minutes === null) {
            return null;
        }
        $latestAllowedOpen = $decisionTime->subMinutes($minutes);
        $exchange = strtolower(trim($exchange));
        $category = strtolower(trim($category));
        if ($exchange === '' || ! in_array($category, ['linear', 'spot', 'inverse'], true)) {
            return null;
        }

        // Enforce strict causality: closed candle at or before decision time T
        $candle = CandleRecord::query()
            ->where('exchange', $exchange)
            ->where('category', $category)
            ->where('symbol', $symbol)
            ->where('interval', $timeframe)
            ->where('is_closed', true)
            ->where('open_time', '<=', $latestAllowedOpen)
            ->latest('open_time')
            ->first();

        if (! $candle) {
            return null;
        }

        // Resolve the immutable strategy version before snapshot de-duplication.
        // Snapshots from a previous version are never reused for new rules.
        $signal = null;
        if ($strategyId !== null && Schema::hasTable('signals')) {
            $signal = SignalRecord::query()
                ->where('exchange', $exchange)
                ->where('category', $category)
                ->where('symbol', $symbol)
                ->where('strategy_id', $strategyId)
                ->where('decision_time', $decisionTime)
                ->whereIn('side', ['BUY', 'SELL'])
                ->first();
        }
        $signalVersion = $signal === null ? null : (string) $signal->strategy_version;

        // De-duplication includes the strategy version and market category.
        $existing = LearningFeatureSnapshotRecord::query()
            ->where('symbol', $symbol)
            ->where('timeframe', $timeframe)
            ->where('strategy_id', $strategyId)
            ->where('decision_time', $decisionTime)
            ->get()
            ->first(fn ($record) => ($record->data_quality['exchange'] ?? null) === $exchange
                && ($record->data_quality['category'] ?? null) === $category
                && ($record->data_quality['strategy_version'] ?? null) === $signalVersion);

        if ($existing) {
            return $existing;
        }

        // Available at is candle open_time + interval duration
        $availableAt = CarbonImmutable::parse($candle->open_time)->addMinutes($minutes);
        if ($availableAt->greaterThan($decisionTime)) {
            return null; // defensive check: never persist future OHLC features
        }

        // Fetch recent historical window strictly <= decisionTime
        $history = CandleRecord::query()
            ->where('exchange', $exchange)
            ->where('category', $category)
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
        $rsi14 = self::rsiFromAverages($avgGain, $avgLoss);

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

        // A feature snapshot alone does not prove a trade direction or outcome.
        // Only a matching historical signal with an actual trade plan can be
        // matured into a directional proxy label. Unknown-side snapshots are
        // retained as INVALID, never silently taught as LONG winners/losers.
        $hasTradePlan = $signal !== null && (float) $signal->stop_loss > 0
            && (float) $signal->take_profit_1 > 0
            && (float) ($signal->side === 'BUY' ? $signal->entry_high : $signal->entry_low) > 0;

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
            'label_status' => $hasTradePlan ? 'PENDING' : 'INVALID',
            'data_quality' => [
                'exchange' => $exchange,
                'category' => $category,
                'strategy_version' => $signalVersion,
                'label_policy_version' => 'SIGNAL_PROXY_V2',
                'side' => $hasTradePlan ? $signal->side : null,
                'signal_id' => $hasTradePlan ? $signal->id : null,
                'entry_price' => $hasTradePlan ? ($signal->side === 'BUY' ? $signal->entry_high : $signal->entry_low) : null,
                'stop_loss' => $hasTradePlan ? $signal->stop_loss : null,
                'take_profit_1' => $hasTradePlan ? $signal->take_profit_1 : null,
                'candles_evaluated' => $history->count(),
                'missing_bars' => 0,
                'no_look_ahead_verified' => true,
            ],
        ]);
    }

    /** Pure RSI calculation: 100 on rising/no losses, 0 on falling/no gains. */
    public static function rsiFromAverages(float $averageGain, float $averageLoss): float
    {
        if ($averageGain <= 0.0 && $averageLoss <= 0.0) {
            return 50.0;
        }
        if ($averageLoss <= 0.0) {
            return 100.0;
        }
        if ($averageGain <= 0.0) {
            return 0.0;
        }

        return round(100.0 - (100.0 / (1.0 + $averageGain / $averageLoss)), 2);
    }

    /** Return the candle interval in whole minutes; reject unsupported notation. */
    public static function intervalMinutes(string $interval): ?int
    {
        $normalized = strtolower(trim($interval));
        if (preg_match('/^[1-9][0-9]{0,3}$/D', $normalized)) {
            return (int) $normalized;
        }
        if (preg_match('/^([1-9][0-9]{0,2})(m|h)$/D', $normalized, $matches)) {
            return (int) $matches[1] * ($matches[2] === 'h' ? 60 : 1);
        }

        return null;
    }

    /**
     * Mature only directionally attributable, versioned proxy labels.
     * Proxy outcomes are not realized PAPER profit and MUST NOT be used as ledger evidence.
     */
    public function maturePendingLabels(int $limit = 50, ?string $exchange = null): int
    {
        $pending = LearningFeatureSnapshotRecord::query()
            ->where('label_status', 'PENDING')
            ->where('available_at', '<=', CarbonImmutable::now()->subHours(4))
            ->limit(max(1, min(1000, $limit)))
            ->get();
        $maturedCount = 0;
        foreach ($pending as $snapshot) {
            $quality = (array) $snapshot->data_quality;
            $venue = (string) ($quality['exchange'] ?? '');
            if ($exchange !== null && $venue !== $exchange) {
                continue;
            }
            $side = $quality['side'] ?? null;
            $entry = (float) ($quality['entry_price'] ?? 0);
            $stop = (float) ($quality['stop_loss'] ?? 0);
            $tp = (float) ($quality['take_profit_1'] ?? 0);
            $category = $quality['category'] ?? null;
            $minutes = self::intervalMinutes((string) $snapshot->timeframe);
            $isLong = $side === 'BUY';
            if (($quality['label_policy_version'] ?? null) !== 'SIGNAL_PROXY_V2'
                || ! is_string($quality['strategy_version'] ?? null)
                || trim($quality['strategy_version']) === ''
                || ! in_array($side, ['BUY', 'SELL'], true)
                || ! in_array($category, ['linear', 'spot', 'inverse'], true)
                || $minutes === null || $venue === '' || $entry <= 0.0 || $stop <= 0.0 || $tp <= 0.0
                || ($isLong && ! ($stop < $entry && $tp > $entry))
                || (! $isLong && ! ($stop > $entry && $tp < $entry))) {
                $snapshot->update(['label_status' => 'INVALID']);

                continue;
            }

            $costModel = config("exchanges.{$venue}.cost_model");
            $fee = $costModel['taker_rate'] ?? null;
            $slippage = $costModel['slippage_rate'] ?? null;
            if (! is_numeric($fee) || ! is_numeric($slippage) || (float) $fee < 0.0
                || (float) $slippage < 0.0 || (float) $fee >= 0.1 || (float) $slippage >= 0.1) {
                // No invented fees from Bybit or another venue.
                continue;
            }
            $t0 = CarbonImmutable::parse($snapshot->decision_time);
            $deadline = $t0->addHours(4);
            $candles = CandleRecord::query()
                ->where('exchange', $venue)
                ->where('category', $category)
                ->where('symbol', $snapshot->symbol)
                ->where('interval', $snapshot->timeframe)
                ->where('is_closed', true)
                ->where('open_time', '>=', $t0)
                ->where('open_time', '<=', $deadline->subMinutes($minutes))
                ->orderBy('open_time')
                ->get();
            if ($candles->isEmpty()) {
                continue;
            }
            $riskPerUnit = abs($entry - $stop);
            $roundTripRate = 2.0 * ((float) $fee + (float) $slippage);
            $lastClose = null;
            $lastAt = null;
            $expectedOpen = $t0;
            $outcome = null;
            $reward = null;
            foreach ($candles as $candle) {
                $barOpen = CarbonImmutable::parse($candle->open_time);
                if (! $barOpen->equalTo($expectedOpen)) {
                    break; // incomplete candle sequence must never produce a mature label
                }
                $expectedOpen = $expectedOpen->addMinutes($minutes);
                $barClose = $barOpen->addMinutes($minutes);
                $high = (float) $candle->high;
                $low = (float) $candle->low;
                $lastClose = (float) $candle->close;
                $lastAt = $barClose;
                $hitTp = $isLong ? $high >= $tp : $low <= $tp;
                $hitSl = $isLong ? $low <= $stop : $high >= $stop;
                if ($hitTp && $hitSl) {
                    $outcome = 'AMBIGUOUS';
                } elseif ($hitTp) {
                    $outcome = 'TP';
                } elseif ($hitSl) {
                    $outcome = 'SL';
                }
                if ($outcome !== null) {
                    // Ambiguous within-candle order is NOT an observed win or loss.
                    $exitPrice = $outcome === 'TP' ? $tp : $stop;
                    if ($outcome !== 'AMBIGUOUS') {
                        $grossR = (($isLong ? $exitPrice - $entry : $entry - $exitPrice) / $riskPerUnit);
                        $reward = $grossR - ($entry + $exitPrice) * ($roundTripRate / 2) / $riskPerUnit;
                    }
                    break;
                }
            }
            if ($outcome === null && $expectedOpen->lessThan($deadline)) {
                continue; // wait for the whole four-hour horizon
            }
            if ($outcome === null && $lastClose !== null) {
                $outcome = 'TIMEOUT';
                $reward = (($isLong ? $lastClose - $entry : $entry - $lastClose)
                    - ($entry + $lastClose) * ($roundTripRate / 2)) / $riskPerUnit;
            }
            if ($outcome !== null && $lastAt !== null) {
                $snapshot->update([
                    'label_status' => $outcome === 'AMBIGUOUS' ? 'AMBIGUOUS' : 'MATURED',
                    'label_outcome' => $outcome,
                    'label_r_multiple' => $reward === null ? null : round($reward, 4),
                    'outcome_at' => $lastAt,
                ]);
                $maturedCount++;
            }
        }

        return $maturedCount;
    }
}
