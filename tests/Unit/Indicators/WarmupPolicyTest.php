<?php

declare(strict_types=1);

namespace Tests\Unit\Indicators;

use App\Indicators\Application\WarmupPolicy;
use App\Indicators\Domain\IndicatorParameters;
use PHPUnit\Framework\TestCase;

final class WarmupPolicyTest extends TestCase
{
    public function test_required_history_is_the_maximum_of_every_indicator_warmup(): void
    {
        $parameters = new IndicatorParameters(
            emaEntryPeriod: 3,
            emaFastPeriod: 2,
            emaSlowPeriod: 5,
            smaPeriod: 5,
            rsiPeriod: 3,
            macdFastPeriod: 2,
            macdSlowPeriod: 4,
            macdSignalPeriod: 2,
            atrPeriod: 3,
            bollingerPeriod: 3,
            bollingerStandardDeviations: '2',
            adxPeriod: 4,
            vwapPeriod: 3,
            volumePeriod: 3,
            supportResistanceLookback: 5,
            supportResistanceLeftBars: 1,
            supportResistanceRightBars: 1,
            supportResistanceClusterTolerance: '0.002',
        );
        $policy = new WarmupPolicy($parameters);

        self::assertSame(8, $policy->requiredCandles());
        self::assertFalse($policy->status(7)->isReady());
        self::assertSame(1, $policy->status(7)->missingCandles());
        self::assertTrue($policy->status(8)->isReady());
    }
}
