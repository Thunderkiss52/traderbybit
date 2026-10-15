<?php

declare(strict_types=1);

namespace Tests\Unit\Indicators;

use App\Indicators\Calculators\SmaCalculator;
use App\Indicators\Calculators\SupportResistanceCalculator;
use App\Indicators\Calculators\VolumeCalculator;
use App\Indicators\Calculators\VwapCalculator;
use App\Indicators\Math\Decimal;
use App\Indicators\Math\SeriesValidator;
use App\MarketData\Domain\Candle;
use App\MarketData\Domain\Interval;
use PHPUnit\Framework\TestCase;

final class VolumeLevelsIndicatorsTest extends TestCase
{
    private Decimal $decimal;

    private SeriesValidator $validator;

    protected function setUp(): void
    {
        $this->decimal = new Decimal;
        $this->validator = new SeriesValidator;
    }

    public function test_rolling_vwap_weights_typical_price_by_volume(): void
    {
        $candles = [
            $this->candle(0, '1', '1', '1', '1'),
            $this->candle(1, '2', '2', '2', '2'),
            $this->candle(2, '3', '3', '3', '1'),
        ];

        $result = (new VwapCalculator($this->decimal, $this->validator))->calculate($candles, 3);

        self::assertSame([null, null, '2.00000000'], $result);
    }

    public function test_volume_returns_sma_relative_volume_and_turnover_sma(): void
    {
        $candles = [
            $this->candle(0, '1', '1', '1', '1'),
            $this->candle(1, '2', '2', '2', '2'),
            $this->candle(2, '3', '3', '3', '3'),
        ];
        $sma = new SmaCalculator($this->decimal, $this->validator);

        $result = (new VolumeCalculator($this->decimal, $this->validator, $sma))->calculate($candles, 3);

        self::assertSame('2.00000000', $result->volumeSma[2]);
        self::assertSame('1.50000000', $result->relativeVolume[2]);
        self::assertSame('4.66666667', $result->turnoverSma[2]);
    }

    public function test_support_and_resistance_use_only_confirmed_pivots(): void
    {
        $rows = [
            ['10', '8', '9'], ['12', '9', '10'], ['15', '10', '14'],
            ['13', '9', '10'], ['11', '7', '8'], ['12', '8', '9'], ['14', '9', '13'],
        ];
        $candles = array_map(
            fn (array $row, int $index): Candle => $this->candle($index, $row[0], $row[1], $row[2], '1'),
            $rows,
            array_keys($rows),
        );

        $result = (new SupportResistanceCalculator($this->decimal, $this->validator))
            ->calculate($candles, 10, 2, 2, '0.002');

        self::assertNull($result[3]->resistance);
        self::assertSame('15.00000000', $result[4]->resistance);
        self::assertSame('7.00000000', $result[6]->support);
        self::assertSame(1, $result[6]->supportStrength);
        self::assertSame(1, $result[6]->resistanceStrength);
    }

    private function candle(int $index, string $high, string $low, string $close, string $volume): Candle
    {
        $turnover = (string) ((int) $close * (int) $volume);

        return new Candle(
            'bybit', 'linear', 'BTCUSDT', Interval::FiveMinutes, $index * 300_000,
            $close, $high, $low, $close, $volume, $turnover, true,
        );
    }
}
