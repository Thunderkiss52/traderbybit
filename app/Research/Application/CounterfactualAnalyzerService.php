<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\Research\Infrastructure\Persistence\CounterfactualEvaluationRecord;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class CounterfactualAnalyzerService
{
    /**
     * Create or resolve counterfactual evaluations for SKIP signals.
     *
     * @return array{analyzed_count: int, resolved_count: int}
     */
    public function analyzeAndResolve(int $limit = 50): array
    {
        $analyzed = 0;
        $resolved = 0;

        // 1. Resolve existing pending evaluations whose candles have matured
        $pending = CounterfactualEvaluationRecord::query()
            ->where('status', 'PENDING_MATURITY')
            ->oldest('decision_time')
            ->limit($limit)
            ->get();

        foreach ($pending as $record) {
            if ($this->resolveHypotheticalOutcome($record)) {
                $resolved++;
            }
        }

        // 2. Ingest un-analyzed mature SKIP observations
        $observations = StrategyObservationRecord::query()
            ->where('side', 'SKIP')
            ->where('decision_time', '<=', now('UTC')->subHours(4))
            ->whereNotIn('id', CounterfactualEvaluationRecord::query()->select('id'))
            ->latest('decision_time')
            ->limit($limit)
            ->get();

        foreach ($observations as $obs) {
            $record = $this->createFromObservation($obs);
            if ($record) {
                $analyzed++;
                if ($this->resolveHypotheticalOutcome($record)) {
                    $resolved++;
                }
            }
        }

        return [
            'analyzed_count' => $analyzed,
            'resolved_count' => $resolved,
        ];
    }

    /**
     * Create a deterministic counterfactual evaluation from a SKIP observation.
     */
    public function createFromObservation(StrategyObservationRecord $obs): ?CounterfactualEvaluationRecord
    {
        $reasons = (array) ($obs->reasons ?? []);
        $skipReason = 'UNKNOWN_SKIP';
        $filterFamily = 'OTHER';

        foreach ($reasons as $r) {
            if (is_string($r) && ! str_starts_with($r, 'regime_')) {
                $skipReason = $r;
                if (str_contains($r, 'adx')) {
                    $filterFamily = 'ADX';
                } elseif (str_contains($r, 'atr') || str_contains($r, 'volatility')) {
                    $filterFamily = 'VOLATILITY';
                } elseif (str_contains($r, 'ema') || str_contains($r, 'trend')) {
                    $filterFamily = 'TREND';
                } elseif (str_contains($r, 'regime')) {
                    $filterFamily = 'REGIME';
                } elseif (str_contains($r, 'rsi') || str_contains($r, 'macd') || str_contains($r, 'momentum')) {
                    $filterFamily = 'MOMENTUM';
                } elseif (str_contains($r, 'volume') || str_contains($r, 'turnover') || str_contains($r, 'liquidity')) {
                    $filterFamily = 'LIQUIDITY';
                } else {
                    $filterFamily = 'FILTER';
                }
                break;
            }
        }

        $nonEvaluableReasons = [
            'insufficient_warmup',
            'missing_timeframe_data',
            'future_candle_rejected',
            'atr_unavailable_or_zero',
            'tick_size_unavailable',
            'market_context_unavailable',
            'non_contiguous_trend_history',
            'non_contiguous_setup_history',
            'non_contiguous_entry_history',
            'non_contiguous_confirmation_history',
        ];
        $isEvaluable = array_intersect($reasons, $nonEvaluableReasons) === [];

        // Determine reference price from the last closed candle at decision time
        $candle = CandleRecord::query()
            ->where('symbol', $obs->symbol)
            ->where('interval', '15')
            ->where('is_closed', true)
            ->where('open_time', '<=', $obs->decision_time)
            ->latest('open_time')
            ->first();

        if (! $candle) {
            return null;
        }

        $price = BigDecimal::of($candle->close);
        $reasonsStr = strtolower(implode(' ', array_filter($reasons, 'is_string')));
        $isBearish = str_contains($reasonsStr, 'bearish')
            || str_contains($reasonsStr, 'short')
            || str_contains($reasonsStr, 'sell')
            || str_contains($reasonsStr, 'upper_band')
            || str_contains($reasonsStr, 'rsi_overbought')
            || (($obs->analysis['level_interaction']['selected_side'] ?? null) === 'SELL');

        $direction = $isBearish ? 'SELL' : 'BUY';

        // 1.5% risk distance, 2:1 reward:risk hypothetical trade or candidate plan
        if (isset($obs->analysis['candidate_plan']) && is_array($obs->analysis['candidate_plan'])) {
            $candidatePlan = $obs->analysis['candidate_plan'];
            $entry = BigDecimal::of($candidatePlan['entry_low'] ?? $price);
            $sl = BigDecimal::of($candidatePlan['stop_loss'] ?? ($direction === 'BUY' ? $price->multipliedBy('0.985') : $price->multipliedBy('1.015')));
            $tp = BigDecimal::of($candidatePlan['take_profit_1'] ?? ($direction === 'BUY' ? $price->multipliedBy('1.030') : $price->multipliedBy('0.970')));
        } elseif ($direction === 'BUY') {
            $entry = $price;
            $sl = $price->multipliedBy('0.985');
            $tp = $price->multipliedBy('1.030');
        } else {
            $entry = $price;
            $sl = $price->multipliedBy('1.015');
            $tp = $price->multipliedBy('0.970');
        }

        return CounterfactualEvaluationRecord::query()->create([
            'id' => (string) $obs->id,
            'signal_id' => (string) $obs->id,
            'strategy_id' => $obs->strategy_id,
            'strategy_version' => $obs->strategy_version,
            'symbol' => $obs->symbol,
            'timeframe' => '15',
            'decision_time' => $obs->decision_time,
            'skip_reason' => $skipReason,
            'filter_family' => $filterFamily,
            'candidate_direction' => $direction,
            'hypothetical_entry' => (string) $entry,
            'hypothetical_sl' => (string) $sl,
            'hypothetical_tp' => (string) $tp,
            'status' => $isEvaluable ? 'PENDING_MATURITY' : 'NON_EVALUABLE',
            'decision_snapshot' => [
                'reasons' => $reasons,
                'analysis' => $obs->analysis,
                'candle_close' => $candle->close,
                'is_evaluable' => $isEvaluable,
            ],
        ]);
    }

    /**
     * Resolve forward outcome using matured closed candles.
     */
    public function resolveHypotheticalOutcome(CounterfactualEvaluationRecord $record): bool
    {
        if ($record->status === 'NON_EVALUABLE') {
            return false;
        }

        $horizonMinutes = 240; // 4 hours = 16 15m candles
        $candles = CandleRecord::query()
            ->where('symbol', $record->symbol)
            ->where('interval', $record->timeframe)
            ->where('is_closed', true)
            ->where('open_time', '>', $record->decision_time)
            ->where('open_time', '<=', $record->decision_time->addMinutes($horizonMinutes))
            ->oldest('open_time')
            ->get(['open_time', 'high', 'low', 'close']);

        if ($candles->isEmpty()) {
            return false;
        }

        $direction = $record->candidate_direction;
        $entry = BigDecimal::of($record->hypothetical_entry);
        $sl = BigDecimal::of($record->hypothetical_sl);
        $tp = BigDecimal::of($record->hypothetical_tp);

        $mfe = BigDecimal::zero();
        $mae = BigDecimal::zero();
        $status = null;
        $resolvedAt = null;

        foreach ($candles as $candle) {
            $high = BigDecimal::of($candle->high);
            $low = BigDecimal::of($candle->low);

            if ($direction === 'BUY') {
                $favorable = $high->minus($entry);
                if ($favorable->isPositive() && $favorable->isGreaterThan($mfe)) {
                    $mfe = $favorable;
                }
                $adverse = $entry->minus($low);
                if ($adverse->isPositive() && $adverse->isGreaterThan($mae)) {
                    $mae = $adverse;
                }

                $hitTp = $high->isGreaterThanOrEqualTo($tp);
                $hitSl = $low->isLessThanOrEqualTo($sl);

                if ($hitTp && $hitSl) {
                    $status = 'AMBIGUOUS';
                    $resolvedAt = $candle->open_time;
                    break;
                } elseif ($hitTp) {
                    $status = 'RESOLVED_TP';
                    $resolvedAt = $candle->open_time;
                    break;
                } elseif ($hitSl) {
                    $status = 'RESOLVED_SL';
                    $resolvedAt = $candle->open_time;
                    break;
                }
            } else { // SELL
                $favorable = $entry->minus($low);
                if ($favorable->isPositive() && $favorable->isGreaterThan($mfe)) {
                    $mfe = $favorable;
                }
                $adverse = $high->minus($entry);
                if ($adverse->isPositive() && $adverse->isGreaterThan($mae)) {
                    $mae = $adverse;
                }

                $hitTp = $low->isLessThanOrEqualTo($tp);
                $hitSl = $high->isGreaterThanOrEqualTo($sl);

                if ($hitTp && $hitSl) {
                    $status = 'AMBIGUOUS';
                    $resolvedAt = $candle->open_time;
                    break;
                } elseif ($hitTp) {
                    $status = 'RESOLVED_TP';
                    $resolvedAt = $candle->open_time;
                    break;
                } elseif ($hitSl) {
                    $status = 'RESOLVED_SL';
                    $resolvedAt = $candle->open_time;
                    break;
                }
            }
        }

        // If neither TP nor SL was hit in evaluated candles:
        if ($status === null) {
            $latestSymbolCandle = CandleRecord::query()
                ->where('symbol', $record->symbol)
                ->where('interval', $record->timeframe)
                ->where('is_closed', true)
                ->latest('open_time')
                ->first(['open_time']);

            $intervalMinutes = (int) $record->timeframe;
            $horizonCandleCutoff = $record->decision_time->addMinutes($horizonMinutes - $intervalMinutes);

            $isMatured = ($candles->last()->open_time >= $horizonCandleCutoff)
                || ($latestSymbolCandle !== null && $latestSymbolCandle->open_time >= $horizonCandleCutoff);

            if (! $isMatured) {
                // Candles stream has not yet reached the horizon. Keep PENDING_MATURITY with updated excursions.
                $record->update([
                    'mfe' => (string) $mfe,
                    'mae' => (string) $mae,
                ]);

                return false;
            }

            $status = 'RESOLVED_TIMEOUT';
            $resolvedAt = $candles->last()->open_time;
        }

        // Calculate actual risk distance and percentage from saved entry and stop-loss
        $riskDistance = $direction === 'BUY' ? $entry->minus($sl) : $sl->minus($entry);
        if ($riskDistance->isLessThanOrEqualTo(BigDecimal::zero())) {
            $riskDistance = $entry->multipliedBy('0.015'); // 1.5% fallback if sl was inverted
        }
        $riskPct = $riskDistance->dividedBy($entry, 8, RoundingMode::HalfEven)->multipliedBy(100);

        // Applicable round-trip execution costs derived from versioned configuration
        $takerRate = BigDecimal::of((string) config('paper.fees.taker_rate', '0.00055'));
        $slippageRate = BigDecimal::of((string) config('paper.slippage_rate', '0.0002'));
        $totalCostPercent = $takerRate->multipliedBy(2)->plus($slippageRate)->multipliedBy(100);

        $exitPrice = match ($status) {
            'RESOLVED_TP' => $tp,
            'RESOLVED_SL', 'AMBIGUOUS' => $sl,
            'RESOLVED_TIMEOUT' => BigDecimal::of($candles->last()->close),
            default => $entry,
        };

        $priceDelta = $direction === 'BUY'
            ? $exitPrice->minus($entry)
            : $entry->minus($exitPrice);

        $grossReturnPercent = $priceDelta->dividedBy($entry, 8, RoundingMode::HalfEven)->multipliedBy(100);
        $netReturn = $grossReturnPercent->minus($totalCostPercent);
        $rMultiple = $netReturn->dividedBy($riskPct, 8, RoundingMode::HalfEven);

        $record->update([
            'status' => $status,
            'mfe' => (string) $mfe,
            'mae' => (string) $mae,
            'r_multiple' => $rMultiple,
            'net_return_percent' => $netReturn,
            'resolved_at' => $resolvedAt ?? $candles->last()->open_time,
        ]);

        return true;
    }

    /**
     * Get aggregate breakdown of filter effectiveness.
     *
     * @return array<string, mixed>
     */
    public function filterEffectivenessReport(?string $strategyId = null): array
    {
        $query = CounterfactualEvaluationRecord::query();
        if ($strategyId) {
            $query->where('strategy_id', $strategyId);
        }

        $records = $query->get();
        $byFilter = [];

        foreach ($records as $r) {
            $f = $r->filter_family;
            if (! isset($byFilter[$f])) {
                $byFilter[$f] = [
                    'filter_family' => $f,
                    'total_skips' => 0,
                    'tp_hit' => 0,
                    'sl_hit' => 0,
                    'timeout' => 0,
                    'ambiguous' => 0,
                    'protective_skips' => 0, // avoided loss
                    'restrictive_skips' => 0, // missed win
                ];
            }
            $byFilter[$f]['total_skips']++;
            if ($r->status === 'RESOLVED_TP') {
                $byFilter[$f]['tp_hit']++;
                $byFilter[$f]['restrictive_skips']++;
            } elseif ($r->status === 'RESOLVED_SL') {
                $byFilter[$f]['sl_hit']++;
                $byFilter[$f]['protective_skips']++;
            } elseif ($r->status === 'RESOLVED_TIMEOUT') {
                $byFilter[$f]['timeout']++;
            } elseif ($r->status === 'AMBIGUOUS') {
                $byFilter[$f]['ambiguous']++;
            }
        }

        return [
            'total_evaluated' => $records->count(),
            'filters' => array_values($byFilter),
            'generated_at' => now('UTC')->toIso8601String(),
        ];
    }
}
