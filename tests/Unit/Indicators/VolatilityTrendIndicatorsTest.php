<?php

declare(strict_types=1);

namespace Tests\Unit\Indicators;

use App\Indicators\Calculators\AdxCalculator;
use App\Indicators\Calculators\AtrCalculator;
use App\Indicators\Calculators\BollingerBandsCalculator;
use App\Indicators\Math\Decimal;
use App\Indicators\Math\SeriesValidator;
use App\MarketData\Domain\Candle;
use App\MarketData\Domain\Interval;
use PHPUnit\Framework\TestCase;

final class VolatilityTrendIndicatorsTest extends TestCase
{
    private Decimal $decimal;

    private SeriesValidator $validator;

    protected function setUp(): void
    {
        $this->decimal = new Decimal;
        $this->validator = new SeriesValidator;
    }

    public function test_atr_uses_true_range_and_wilders_smoothing(): void
    {
        $candles = [
            $this->candle(0, '10', '8', '9'),
            $this->candle(1, '11', '9', '10'),
            $this->candle(2, '13', '10', '12'),
            $this->candle(3, '14', '12', '13'),
            $this->candle(4, '15', '11', '12'),
        ];

        $result = (new AtrCalculator($this->decimal, $this->validator))->calculate($candles, 3);

        self::assertSame([null, null, '2.33333333', '2.22222222', '2.81481481'], $result);
    }

    public function test_bollinger_bands_use_population_standard_deviation(): void
    {
        $result = (new BollingerBandsCalculator($this->decimal, $this->validator))->calculate(['1', '2', '3'], 3, '2');

        self::assertSame('2.00000000', $result->middle[2]);
        self::assertSame('0.81649658', $result->standardDeviation[2]);
        self::assertSame('3.63299316', $result->upper[2]);
        self::assertSame('0.36700684', $result->lower[2]);
    }

    public function test_adx_warmup_and_directional_values_on_a_strong_monotonic_trend(): void
    {
        $candles = [];
        for ($index = 0; $index < 8; $index++) {
            $candles[] = $this->candle($index, (string) (10 + $index), (string) (8 + $index), (string) (9 + $index));
        }

        $result = (new AdxCalculator($this->decimal, $this->validator))->calculate($candles, 3);

        self::assertNull($result->adx[4]);
        self::assertSame('100.00000000', $result->adx[5]);
        self::assertSame('50.00000000', $result->plusDi[5]);
        self::assertSame('0.00000000', $result->minusDi[5]);
        self::assertSame('100.00000000', $result->dx[5]);
    }

    private function candle(int $index, string $high, string $low, string $close): Candle
    {
        return new Candle(
            'bybit', 'linear', 'BTCUSDT', Interval::FiveMinutes, $index * 300_000,
            $close, $high, $low, $close, '1', $close, true,
        );
    }
}
