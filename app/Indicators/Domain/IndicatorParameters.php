<?php

declare(strict_types=1);

namespace App\Indicators\Domain;

use App\Indicators\Exceptions\InvalidSeriesException;

final readonly class IndicatorParameters
{
    public function __construct(
        public int $emaEntryPeriod,
        public int $emaFastPeriod,
        public int $emaSlowPeriod,
        public int $smaPeriod,
        public int $rsiPeriod,
        public int $macdFastPeriod,
        public int $macdSlowPeriod,
        public int $macdSignalPeriod,
        public int $atrPeriod,
        public int $bollingerPeriod,
        public string $bollingerStandardDeviations,
        public int $adxPeriod,
        public int $vwapPeriod,
        public int $volumePeriod,
        public int $supportResistanceLookback,
        public int $supportResistanceLeftBars,
        public int $supportResistanceRightBars,
        public string $supportResistanceClusterTolerance,
    ) {
        foreach ([
            $emaEntryPeriod,
            $emaFastPeriod,
            $emaSlowPeriod,
            $smaPeriod,
            $rsiPeriod,
            $macdFastPeriod,
            $macdSlowPeriod,
            $macdSignalPeriod,
            $atrPeriod,
            $bollingerPeriod,
            $adxPeriod,
            $vwapPeriod,
            $volumePeriod,
            $supportResistanceLookback,
            $supportResistanceLeftBars,
            $supportResistanceRightBars,
        ] as $period) {
            if ($period < 1) {
                throw new InvalidSeriesException('All indicator periods must be positive.');
            }
        }
        if ($emaFastPeriod >= $emaSlowPeriod || $macdFastPeriod >= $macdSlowPeriod) {
            throw new InvalidSeriesException('Fast periods must be smaller than slow periods.');
        }
    }

    /** @param array<string, mixed> $config */
    public static function fromConfig(array $config): self
    {
        return new self(
            emaEntryPeriod: (int) $config['ema']['entry_period'],
            emaFastPeriod: (int) $config['ema']['fast_period'],
            emaSlowPeriod: (int) $config['ema']['slow_period'],
            smaPeriod: (int) $config['sma_period'],
            rsiPeriod: (int) $config['rsi_period'],
            macdFastPeriod: (int) $config['macd']['fast_period'],
            macdSlowPeriod: (int) $config['macd']['slow_period'],
            macdSignalPeriod: (int) $config['macd']['signal_period'],
            atrPeriod: (int) $config['atr_period'],
            bollingerPeriod: (int) $config['bollinger']['period'],
            bollingerStandardDeviations: (string) $config['bollinger']['standard_deviations'],
            adxPeriod: (int) $config['adx_period'],
            vwapPeriod: (int) $config['vwap_period'],
            volumePeriod: (int) $config['volume_period'],
            supportResistanceLookback: (int) $config['support_resistance']['lookback'],
            supportResistanceLeftBars: (int) $config['support_resistance']['left_bars'],
            supportResistanceRightBars: (int) $config['support_resistance']['right_bars'],
            supportResistanceClusterTolerance: (string) $config['support_resistance']['cluster_tolerance'],
        );
    }
}
