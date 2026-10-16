<?php

declare(strict_types=1);

namespace App\PatternDiscovery\Application;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Closed 5m bars, conservative same-bar stop precedence. All returns in risk units. */
final class BarrierOutcomeLabeler
{
    /** @param list<array{open_time:int,open:string,high:string,low:string,close:string}> $bars */
    public function label(string $direction, string $entry, string $atr, int $decisionTimestamp, array $bars, array $config): ?array
    {
        if (! in_array($direction, ['BUY', 'SELL'], true)) {
            return null;
        }
        $entryPrice = BigDecimal::of($entry);
        $distance = BigDecimal::of($atr)->multipliedBy((string) $config['stop_atr']);
        if ($distance->isLessThanOrEqualTo(BigDecimal::zero()) || $entryPrice->isLessThanOrEqualTo(BigDecimal::zero())) {
            return null;
        }
        $targetDistance = BigDecimal::of($atr)->multipliedBy((string) $config['target_atr']);
        $long = $direction === 'BUY';
        $stop = $long ? $entryPrice->minus($distance) : $entryPrice->plus($distance);
        $target = $long ? $entryPrice->plus($targetDistance) : $entryPrice->minus($targetDistance);
        if ($stop->isLessThanOrEqualTo(BigDecimal::zero()) || $target->isLessThanOrEqualTo(BigDecimal::zero())) {
            return null;
        }
        $expected = $decisionTimestamp;
        $last = null;
        $exitPrice = null;
        $outcome = 'TIMEOUT';
        $ambiguous = false;
        $mfe = BigDecimal::zero();
        $mae = BigDecimal::zero();
        foreach ($bars as $bar) {
            // Each candle must start at the previous decision/close boundary.
            if ($bar['open_time'] !== $expected) {
                return null;
            }
            $expected += 300;
            $hi = BigDecimal::of($bar['high']);
            $lo = BigDecimal::of($bar['low']);
            $open = BigDecimal::of($bar['open']);
            $favorable = $long ? $hi->minus($entryPrice) : $entryPrice->minus($lo);
            $adverse = $long ? $lo->minus($entryPrice) : $entryPrice->minus($hi);
            if ($favorable->isGreaterThan($mfe)) {
                $mfe = $favorable;
            }
            if ($adverse->isLessThan($mae)) {
                $mae = $adverse;
            }
            $gappedThroughStop = $long ? $open->isLessThanOrEqualTo($stop) : $open->isGreaterThanOrEqualTo($stop);
            if ($gappedThroughStop) {
                // A stop-market order cannot be assumed to fill at the stop after an adverse gap.
                $outcome = 'SL';
                $ambiguous = true;
                $exitPrice = $open;
                break;
            }
            $hitStop = $long ? $lo->isLessThanOrEqualTo($stop) : $hi->isGreaterThanOrEqualTo($stop);
            $hitTarget = $long ? $hi->isGreaterThanOrEqualTo($target) : $lo->isLessThanOrEqualTo($target);
            if ($hitStop || $hitTarget) {
                $ambiguous = $hitStop && $hitTarget;
                $outcome = $hitStop ? 'SL' : 'TP';
                $exitPrice = $hitStop ? $stop : $target;
                break;
            }
            $last = $bar;
        }
        if ($exitPrice === null) {
            if (count($bars) !== (int) $config['holding_bars_5m'] || $last === null) {
                return null;
            }
            $exitPrice = BigDecimal::of($last['close']);
        }
        $gross = ($long ? $exitPrice->minus($entryPrice) : $entryPrice->minus($exitPrice));
        $roundTripRate = BigDecimal::of((string) $config['taker_fee_rate'])->plus((string) $config['slippage_rate'])->multipliedBy(2);
        $cost = $entryPrice->plus($exitPrice)->dividedBy(2, 18, RoundingMode::HalfEven)->multipliedBy($roundTripRate);
        $netR = $gross->minus($cost)->dividedBy($distance, 12, RoundingMode::HalfEven);

        return ['outcome' => $outcome, 'won' => $netR->isGreaterThan(BigDecimal::zero()),
            'net_r' => (float) (string) $netR, 'ambiguous' => $ambiguous,
            'mfe_r' => (float) (string) $mfe->dividedBy($distance, 12, RoundingMode::HalfEven),
            'mae_r' => (float) (string) $mae->dividedBy($distance, 12, RoundingMode::HalfEven),
            'outcome_timestamp' => $expected];
    }
}
