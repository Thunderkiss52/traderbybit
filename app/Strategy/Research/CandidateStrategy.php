<?php

declare(strict_types=1);

namespace App\Strategy\Research;

use App\Strategy\Domain\BarState;
use App\Strategy\Domain\StrategyInput;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

abstract readonly class CandidateStrategy extends ResearchStrategy
{
    protected function decisionBars(StrategyInput $input): array
    {
        return $input->entry->bars;
    }

    protected function minimumBars(): int
    {
        return (int) ($this->config['min_bars'] ?? 60);
    }

    protected function warm(StrategyInput $input): bool
    {
        return $this->preflight($input) === null && count($this->decisionBars($input)) > $this->minimumBars();
    }

    /**
     * @param  list<BarState>  $bars
     * @return array{0: BigDecimal, 1: BigDecimal}|null
     */
    protected function priorExtremes(array $bars, int $start, int $end): ?array
    {
        if ($start < 0 || $end <= $start || ! isset($bars[$start])) {
            return null;
        }
        $low = BigDecimal::of($bars[$start]->low);
        $high = BigDecimal::of($bars[$start]->high);
        for ($index = $start + 1; $index < $end; $index++) {
            $barLow = BigDecimal::of($bars[$index]->low);
            $barHigh = BigDecimal::of($bars[$index]->high);
            if ($barLow->isLessThan($low)) {
                $low = $barLow;
            }
            if ($barHigh->isGreaterThan($high)) {
                $high = $barHigh;
            }
        }

        return [$low, $high];
    }

    /**
     * @param  list<BarState>  $bars
     */
    protected function meanTrueRange(array $bars, int $start, int $end): ?BigDecimal
    {
        if ($start < 1 || $end <= $start || ! isset($bars[$start - 1], $bars[$end - 1])) {
            return null;
        }
        $sum = BigDecimal::zero();
        for ($index = $start; $index < $end; $index++) {
            $sum = $sum->plus($this->trueRange($bars[$index], $bars[$index - 1]));
        }

        return $sum->dividedBy($end - $start, 12, RoundingMode::HalfEven);
    }

    protected function trueRange(BarState $bar, BarState $previous): BigDecimal
    {
        $high = BigDecimal::of($bar->high);
        $low = BigDecimal::of($bar->low);
        $previousClose = BigDecimal::of($previous->close);
        $range = $high->minus($low);
        $up = $high->minus($previousClose)->abs();
        $down = $low->minus($previousClose)->abs();

        return BigDecimal::max($range, $up, $down);
    }

    /**
     * @param  list<BarState>  $bars
     * @return list<BigDecimal|null>
     */
    protected function ema(array $bars, int $period): array
    {
        if ($period < 1) {
            return [];
        }
        $result = [];
        $seed = BigDecimal::zero();
        $multiplier = BigDecimal::of('2')->dividedBy($period + 1, 24, RoundingMode::HalfEven);
        $previous = null;
        foreach ($bars as $index => $bar) {
            $close = BigDecimal::of($bar->close);
            if ($index < $period - 1) {
                $seed = $seed->plus($close);
                $result[] = null;

                continue;
            }
            if ($index === $period - 1) {
                $previous = $seed->plus($close)->dividedBy($period, 24, RoundingMode::HalfEven);
            } else {
                $previous = $close->minus($previous)->multipliedBy($multiplier)->plus($previous);
            }
            $result[] = $previous;
        }

        return $result;
    }

    protected function relativeMove(BigDecimal $current, BigDecimal $base): ?BigDecimal
    {
        if ($base->isZero()) {
            return null;
        }

        return $current->minus($base)->dividedBy($base, 12, RoundingMode::HalfEven);
    }

    protected function decimal(?string $value): ?BigDecimal
    {
        return $value === null ? null : BigDecimal::of($value);
    }
}
