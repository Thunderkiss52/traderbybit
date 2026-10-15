<?php

declare(strict_types=1);

namespace App\Indicators\Calculators;

use App\Indicators\Math\Decimal;
use App\Indicators\Math\SeriesValidator;
use App\MarketData\Domain\Candle;
use Brick\Math\BigDecimal;

final readonly class VwapCalculator
{
    public function __construct(
        private Decimal $decimal,
        private SeriesValidator $validator,
    ) {}

    /** @param list<Candle> $candles @return list<?string> */
    public function calculate(array $candles, int $period): array
    {
        $this->validator->numeric([], $period);
        $this->validator->candles($candles);
        $result = array_fill(0, count($candles), null);
        $priceVolumeWindow = [];
        $volumeWindow = [];
        $priceVolumeSum = $volumeSum = $this->decimal->zero();

        foreach ($candles as $index => $candle) {
            $typicalPrice = $this->decimal->divide(
                $this->decimal->sum([
                    $this->decimal->of($candle->high),
                    $this->decimal->of($candle->low),
                    $this->decimal->of($candle->close),
                ]),
                3,
            );
            $volume = $this->decimal->of($candle->volume);
            $priceVolume = $this->decimal->multiply($typicalPrice, $volume);
            $priceVolumeWindow[] = $priceVolume;
            $volumeWindow[] = $volume;
            $priceVolumeSum = $this->decimal->add($priceVolumeSum, $priceVolume);
            $volumeSum = $this->decimal->add($volumeSum, $volume);

            if (count($volumeWindow) > $period) {
                /** @var BigDecimal $removedPriceVolume */
                $removedPriceVolume = array_shift($priceVolumeWindow);
                /** @var BigDecimal $removedVolume */
                $removedVolume = array_shift($volumeWindow);
                $priceVolumeSum = $this->decimal->subtract($priceVolumeSum, $removedPriceVolume);
                $volumeSum = $this->decimal->subtract($volumeSum, $removedVolume);
            }

            if (count($volumeWindow) === $period && ! $volumeSum->isZero()) {
                $result[$index] = $this->decimal->output($this->decimal->divide($priceVolumeSum, $volumeSum));
            }
        }

        return $result;
    }
}
