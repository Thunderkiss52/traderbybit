<?php

declare(strict_types=1);

namespace App\Indicators\Domain;

final readonly class IndicatorSeries
{
    /**
     * @param  list<?string>  $emaFast
     * @param  list<?string>  $emaSlow
     * @param  list<?string>  $sma
     * @param  list<?string>  $rsi
     * @param  list<?string>  $atr
     * @param  list<?string>  $vwap
     * @param  list<SupportResistancePoint>  $supportResistance
     */
    public function __construct(
        public WarmupStatus $warmup,
        public array $emaEntry,
        public array $emaFast,
        public array $emaSlow,
        public array $sma,
        public array $rsi,
        public MacdSeries $macd,
        public array $atr,
        public BollingerBandsSeries $bollingerBands,
        public AdxSeries $adx,
        public array $vwap,
        public VolumeSeries $volume,
        public array $supportResistance,
    ) {}

    public function isReadyForSignals(): bool
    {
        if (! $this->warmup->isReady() || $this->emaSlow === []) {
            return false;
        }

        $last = count($this->emaSlow) - 1;

        return $this->emaEntry[$last] !== null
            && $this->emaFast[$last] !== null
            && $this->emaSlow[$last] !== null
            && $this->sma[$last] !== null
            && $this->rsi[$last] !== null
            && $this->macd->signal[$last] !== null
            && $this->macd->histogram[$last] !== null
            && $this->atr[$last] !== null
            && $this->bollingerBands->upper[$last] !== null
            && $this->adx->adx[$last] !== null
            && $this->vwap[$last] !== null
            && $this->volume->relativeVolume[$last] !== null;
    }
}
