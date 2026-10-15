<?php

declare(strict_types=1);

namespace Tests\Unit\Indicators;

use App\Indicators\Calculators\EmaCalculator;
use App\Indicators\Calculators\MacdCalculator;
use App\Indicators\Calculators\RsiCalculator;
use App\Indicators\Calculators\SmaCalculator;
use App\Indicators\Math\Decimal;
use App\Indicators\Math\SeriesValidator;
use PHPUnit\Framework\TestCase;

final class MovingMomentumIndicatorsTest extends TestCase
{
    private Decimal $decimal;

    private SeriesValidator $validator;

    protected function setUp(): void
    {
        $this->decimal = new Decimal;
        $this->validator = new SeriesValidator;
    }

    public function test_sma_uses_a_complete_rolling_window(): void
    {
        $result = (new SmaCalculator($this->decimal, $this->validator))->calculate(['1', '2', '3', '4', '5'], 3);

        self::assertSame([null, null, '2.00000000', '3.00000000', '4.00000000'], $result);
    }

    public function test_ema_is_seeded_with_sma_and_then_uses_recursive_smoothing(): void
    {
        $result = (new EmaCalculator($this->decimal, $this->validator))->calculate(['1', '2', '3', '6', '5'], 3);

        self::assertSame([null, null, '2.00000000', '4.00000000', '4.50000000'], $result);
    }

    public function test_rsi_matches_wilders_reference_series(): void
    {
        $closes = [
            '44.34', '44.09', '44.15', '43.61', '44.33',
            '44.83', '45.10', '45.42', '45.84', '46.08',
            '45.89', '46.03', '45.61', '46.28', '46.28',
        ];

        $result = (new RsiCalculator($this->decimal, $this->validator))->calculate($closes, 14);

        self::assertSame('70.46413502', $result[14]);
        self::assertNull($result[13]);
    }

    public function test_rsi_flat_series_is_neutral_and_rising_series_is_one_hundred(): void
    {
        $calculator = new RsiCalculator($this->decimal, $this->validator);

        self::assertSame('50.00000000', $calculator->calculate(['1', '1', '1', '1'], 3)[3]);
        self::assertSame('100.00000000', $calculator->calculate(['1', '2', '3', '4'], 3)[3]);
    }

    public function test_macd_aligns_signal_and_histogram_with_original_series(): void
    {
        $ema = new EmaCalculator($this->decimal, $this->validator);
        $result = (new MacdCalculator($this->decimal, $this->validator, $ema))
            ->calculate(['1', '2', '4', '8', '16'], 2, 3, 2);

        self::assertSame([null, null, '0.83333333', '1.22222222', '2.21296296'], $result->macd);
        self::assertSame([null, null, null, '1.02777778', '1.81790123'], $result->signal);
        self::assertSame([null, null, null, '0.19444444', '0.39506173'], $result->histogram);
    }
}
