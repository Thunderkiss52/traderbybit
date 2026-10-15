<?php

declare(strict_types=1);

namespace App\Indicators\Calculators;

use App\Indicators\Domain\VolumeSeries;
use App\Indicators\Math\Decimal;
use App\Indicators\Math\SeriesValidator;
use App\MarketData\Domain\Candle;

final readonly class VolumeCalculator
{
    public function __construct(
        private Decimal $decimal,
        private SeriesValidator $validator,
        private SmaCalculator $sma,
    ) {}

    /** @param list<Candle> $candles */
    public function calculate(array $candles, int $period): VolumeSeries
    {
        $this->validator->numeric([], $period);
        $this->validator->candles($candles);
        $volumes = array_map(static fn (Candle $candle): string => $candle->volume, $candles);
        $turnovers = array_map(static fn (Candle $candle): string => $candle->turnover, $candles);
        $volumeSmaDecimals = $this->sma->calculateDecimal($volumes, $period);
        $turnoverSmaDecimals = $this->sma->calculateDecimal($turnovers, $period);
        $volumeSma = array_map(
            fn ($value): ?string => $value === null ? null : $this->decimal->output($value),
            $volumeSmaDecimals,
        );
        $turnoverSma = array_map(
            fn ($value): ?string => $value === null ? null : $this->decimal->output($value),
            $turnoverSmaDecimals,
        );
        $relativeVolume = array_fill(0, count($candles), null);

        foreach ($volumes as $index => $volume) {
            if ($volumeSmaDecimals[$index] === null || $volumeSmaDecimals[$index]->isZero()) {
                continue;
            }
            $relativeVolume[$index] = $this->decimal->output($this->decimal->divide(
                $this->decimal->of($volume),
                $volumeSmaDecimals[$index],
            ));
        }

        return new VolumeSeries($volumes, $volumeSma, $relativeVolume, $turnoverSma);
    }
}
