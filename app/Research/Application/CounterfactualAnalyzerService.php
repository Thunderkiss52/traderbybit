<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\Research\Infrastructure\Persistence\CounterfactualEvaluationRecord;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use Brick\Math\BigDecimal;
use Illuminate\Support\Str;

final class CounterfactualAnalyzerService
{
    /**
     * Create or resolve counterfactual evaluations for SKIP signals.
     *
     * @return array{analyzed_count: int, resolved_count: int}
     */
    public function analyzeAndResolve(int $limit = 50): array
    {
        $observations = StrategyObservationRecord::query()
            ->where('side', 'SKIP')
            ->where('decision_time', '<=', now('UTC')->subHours(4))
            ->whereNotIn('id', CounterfactualEvaluationRecord::query()->select('id'))
            ->latest('decision_time')
            ->limit($limit)
            ->get();

        $analyzed = 0;
        $resolved = 0;

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
                } else {
                    $filterFamily = 'FILTER';
                }
                break;
            }
        }

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
        // Default hypothetical direction based on fast EMA / close or default BUY
        $direction = str_contains(implode(' ', $reasons), 'bearish') ? 'SELL' : 'BUY';

        // 1% risk distance, 2:1 reward:risk hypothetical trade
        if ($direction === 'BUY') {
            $entry = $price;
            $sl = $price->multipliedBy('0.985');
            $tp = $price->multipliedBy('1.030');
        } else {
            $entry = $price;
            $sl = $price->multipliedBy('1.015');
            $tp = $price->multipliedBy('0.970');
        }

        return CounterfactualEvaluationRecord::query()->create([
            'id' => (string) Str::uuid(),
            'signal_id' => null,
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
            'status' => 'PENDING_MATURITY',
            'decision_snapshot' => [
                'reasons' => $reasons,
                'analysis' => $obs->analysis,
                'candle_close' => $candle->close,
            ],
        ]);
    }

    /**
     * Resolve forward outcome using matured closed candles.
     */
    public function resolveHypotheticalOutcome(CounterfactualEvaluationRecord $record): bool
    {
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
        $status = 'RESOLVED_TIMEOUT';
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

        $riskDistance = $entry->minus($sl)->abs();
        $rMultiple = match ($status) {
            'RESOLVED_TP' => '2.00000000',
            'RESOLVED_SL' => '-1.00000000',
            default => '0.00000000',
        };
        $netReturn = match ($status) {
            'RESOLVED_TP' => '3.00000000',
            'RESOLVED_SL' => '-1.50000000',
            default => '0.00000000',
        };

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
