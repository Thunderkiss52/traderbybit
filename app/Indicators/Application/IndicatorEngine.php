<?php

declare(strict_types=1);

namespace App\Indicators\Application;

use App\Indicators\Calculators\AdxCalculator;
use App\Indicators\Calculators\AtrCalculator;
use App\Indicators\Calculators\BollingerBandsCalculator;
use App\Indicators\Calculators\EmaCalculator;
use App\Indicators\Calculators\MacdCalculator;
use App\Indicators\Calculators\RsiCalculator;
use App\Indicators\Calculators\SmaCalculator;
use App\Indicators\Calculators\SupportResistanceCalculator;
use App\Indicators\Calculators\VolumeCalculator;
use App\Indicators\Calculators\VwapCalculator;
use App\Indicators\Domain\IndicatorParameters;
use App\Indicators\Domain\IndicatorSeries;
use App\Indicators\Math\SeriesValidator;
use App\MarketData\Domain\Candle;

final readonly class IndicatorEngine
{
    public function __construct(
        private IndicatorParameters $parameters,
        private WarmupPolicy $warmup,
        private SeriesValidator $validator,
        private SmaCalculator $sma,
        private EmaCalculator $ema,
        private RsiCalculator $rsi,
        private MacdCalculator $macd,
        private AtrCalculator $atr,
        private BollingerBandsCalculator $bollingerBands,
        private AdxCalculator $adx,
        private VwapCalculator $vwap,
        private VolumeCalculator $volume,
        private SupportResistanceCalculator $supportResistance,
    ) {}

    /** @param list<Candle> $candles */
    public function calculate(array $candles): IndicatorSeries
    {
        $this->validator->candles($candles);
        $closes = array_map(static fn (Candle $candle): string => $candle->close, $candles);

        return new IndicatorSeries(
            warmup: $this->warmup->status(count($candles)),
            emaEntry: $this->ema->calculate($closes, $this->parameters->emaEntryPeriod),
            emaFast: $this->ema->calculate($closes, $this->parameters->emaFastPeriod),
            emaSlow: $this->ema->calculate($closes, $this->parameters->emaSlowPeriod),
            sma: $this->sma->calculate($closes, $this->parameters->smaPeriod),
            rsi: $this->rsi->calculate($closes, $this->parameters->rsiPeriod),
            macd: $this->macd->calculate(
                $closes,
                $this->parameters->macdFastPeriod,
                $this->parameters->macdSlowPeriod,
                $this->parameters->macdSignalPeriod,
            ),
            atr: $this->atr->calculate($candles, $this->parameters->atrPeriod),
            bollingerBands: $this->bollingerBands->calculate(
                $closes,
                $this->parameters->bollingerPeriod,
                $this->parameters->bollingerStandardDeviations,
            ),
            adx: $this->adx->calculate($candles, $this->parameters->adxPeriod),
            vwap: $this->vwap->calculate($candles, $this->parameters->vwapPeriod),
            volume: $this->volume->calculate($candles, $this->parameters->volumePeriod),
            supportResistance: $this->supportResistance->calculate(
                $candles,
                $this->parameters->supportResistanceLookback,
                $this->parameters->supportResistanceLeftBars,
                $this->parameters->supportResistanceRightBars,
                $this->parameters->supportResistanceClusterTolerance,
            ),
        );
    }
}
