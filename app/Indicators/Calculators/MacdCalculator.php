<?php

declare(strict_types=1);

namespace App\Indicators\Calculators;

use App\Indicators\Domain\MacdSeries;
use App\Indicators\Exceptions\InvalidSeriesException;
use App\Indicators\Math\Decimal;
use App\Indicators\Math\SeriesValidator;

final readonly class MacdCalculator
{
    public function __construct(
        private Decimal $decimal,
        private SeriesValidator $validator,
        private EmaCalculator $ema,
    ) {}

    /** @param list<string> $closes */
    public function calculate(array $closes, int $fastPeriod, int $slowPeriod, int $signalPeriod): MacdSeries
    {
        $this->validator->numeric($closes, $slowPeriod);
        if ($fastPeriod >= $slowPeriod || $signalPeriod < 1) {
            throw new InvalidSeriesException('MACD requires fast period < slow period and signal period >= 1.');
        }

        $fast = $this->ema->calculateDecimal($closes, $fastPeriod);
        $slow = $this->ema->calculateDecimal($closes, $slowPeriod);
        $count = count($closes);
        $macd = $signal = $histogram = array_fill(0, $count, null);
        $compactMacd = [];
        $macdDecimals = [];
        $positions = [];

        for ($index = 0; $index < $count; $index++) {
            if ($fast[$index] === null || $slow[$index] === null) {
                continue;
            }
            $value = $this->decimal->subtract($fast[$index], $slow[$index]);
            $macd[$index] = $this->decimal->output($value);
            $macdDecimals[$index] = $value;
            $compactMacd[] = (string) $value;
            $positions[] = $index;
        }

        $compactSignal = $this->ema->calculateDecimal($compactMacd, $signalPeriod);
        foreach ($positions as $compactIndex => $originalIndex) {
            if (($compactSignal[$compactIndex] ?? null) === null) {
                continue;
            }
            $signal[$originalIndex] = $this->decimal->output($compactSignal[$compactIndex]);
            $histogram[$originalIndex] = $this->decimal->output($this->decimal->subtract(
                $macdDecimals[$originalIndex],
                $compactSignal[$compactIndex],
            ));
        }

        return new MacdSeries($macd, $signal, $histogram);
    }
}
