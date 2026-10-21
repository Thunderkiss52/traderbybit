<?php

declare(strict_types=1);

namespace App\Research\Domain\Concentration;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * PROFIT-1 / P-5 — synchronized cross-symbol exposure view (§10.2).
 *
 * Gross exposure per symbol is reported separately and together WITHOUT
 * netting: opposing BUY/SELL positions of the same symbol and the notionals of
 * different symbols are never offset. The ledger is never modified.
 *
 * The synchronization grid is the exact union of position interval boundaries,
 * which is deterministic and captures every exposure change without
 * interpolation.
 */
final class CrossSymbolExposure
{
    public const GRID_RULE = 'INTERVAL_BOUNDARY_UNION';

    public const SCALE = 12;

    /**
     * @param  list<PositionInterval>  $intervals
     * @return array<string, mixed>
     */
    public function compute(array $intervals, int $asOfTimestamp): array
    {
        $boundaries = [];
        foreach ($intervals as $interval) {
            $boundaries[$interval->openedAt->getTimestamp()] = true;
            $boundaries[min($interval->endAt->getTimestamp(), $asOfTimestamp)] = true;
        }
        $grid = array_map('intval', array_keys($boundaries));
        sort($grid);

        $points = [];
        $perSymbol = [];
        $peakCombined = BigDecimal::zero()->toScale(self::SCALE, RoundingMode::HalfEven);
        $combinedSum = BigDecimal::zero()->toScale(self::SCALE, RoundingMode::HalfEven);
        $combinedPoints = 0;

        foreach ($grid as $ts) {
            $bySymbol = [];
            $combined = BigDecimal::zero()->toScale(self::SCALE, RoundingMode::HalfEven);
            foreach ($intervals as $interval) {
                if (! $interval->openedAt->lessThanOrEqualTo(CarbonImmutable::createFromTimestampUTC($ts))) {
                    continue;
                }
                if ($interval->endAt->getTimestamp() <= $ts) {
                    continue;
                }
                $symbol = $interval->symbol;
                if (! isset($bySymbol[$symbol])) {
                    $bySymbol[$symbol] = [
                        'gross_entry_notional' => BigDecimal::zero()->toScale(self::SCALE, RoundingMode::HalfEven),
                        'position_count' => 0,
                    ];
                }
                $bySymbol[$symbol]['gross_entry_notional'] = $bySymbol[$symbol]['gross_entry_notional']->plus($interval->entryNotional);
                $bySymbol[$symbol]['position_count']++;
                $combined = $combined->plus($interval->entryNotional);
            }
            ksort($bySymbol);

            $symbolRows = [];
            foreach ($bySymbol as $symbol => $row) {
                $symbolRows[$symbol] = [
                    'gross_entry_notional' => $row['gross_entry_notional']->toScale(self::SCALE, RoundingMode::HalfEven)->__toString(),
                    'position_count' => $row['position_count'],
                ];
                if (! isset($perSymbol[$symbol])) {
                    $perSymbol[$symbol] = [
                        'peak_gross_entry_notional' => $row['gross_entry_notional']->toScale(self::SCALE, RoundingMode::HalfEven)->__toString(),
                        'max_position_count' => $row['position_count'],
                        'points_with_exposure' => 0,
                        '_sum' => BigDecimal::zero()->toScale(self::SCALE, RoundingMode::HalfEven),
                    ];
                }
                $entry = &$perSymbol[$symbol];
                if ($row['gross_entry_notional']->isGreaterThan($entry['peak_gross_entry_notional'])) {
                    $entry['peak_gross_entry_notional'] = $row['gross_entry_notional']->toScale(self::SCALE, RoundingMode::HalfEven)->__toString();
                }
                $entry['max_position_count'] = max($entry['max_position_count'], $row['position_count']);
                $entry['points_with_exposure']++;
                $entry['_sum'] = $entry['_sum']->plus($row['gross_entry_notional']);
                unset($entry);
            }

            $combinedStr = $combined->toScale(self::SCALE, RoundingMode::HalfEven)->__toString();
            if ($combined->isGreaterThan($peakCombined)) {
                $peakCombined = $combined;
            }
            $combinedSum = $combinedSum->plus($combined);
            $combinedPoints++;

            $points[] = [
                'at' => CarbonImmutable::createFromTimestampUTC($ts)->toIso8601String(),
                'by_symbol' => $symbolRows,
                'combined_gross_entry_notional' => $combinedStr,
            ];
        }

        $perSymbolRows = [];
        foreach ($perSymbol as $symbol => $row) {
            $mean = $row['points_with_exposure'] > 0
                ? $row['_sum']->dividedBy(BigDecimal::of($row['points_with_exposure']), self::SCALE, RoundingMode::HalfEven)->toScale(self::SCALE, RoundingMode::HalfEven)->__toString()
                : null;
            $perSymbolRows[$symbol] = [
                'peak_gross_entry_notional' => $row['peak_gross_entry_notional'],
                'max_position_count' => $row['max_position_count'],
                'points_with_exposure' => $row['points_with_exposure'],
                'mean_gross_entry_notional_when_exposed' => $mean,
            ];
        }
        ksort($perSymbolRows);

        $meanCombined = $combinedPoints > 0
            ? $combinedSum->dividedBy(BigDecimal::of($combinedPoints), self::SCALE, RoundingMode::HalfEven)->toScale(self::SCALE, RoundingMode::HalfEven)->__toString()
            : null;

        return [
            'grid_rule' => self::GRID_RULE,
            'grid_points' => count($grid),
            'symbols' => array_keys($perSymbolRows),
            'per_symbol' => $perSymbolRows,
            'peak_combined_gross_entry_notional' => $peakCombined->toScale(self::SCALE, RoundingMode::HalfEven)->__toString(),
            'mean_combined_gross_entry_notional' => $meanCombined,
            'netting_applied' => false,
            'netting_note' => 'Gross entry notional only; opposite-side positions and cross-symbol notionals are never netted.',
            'points' => $points,
        ];
    }
}
