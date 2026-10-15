<?php

declare(strict_types=1);

namespace App\Indicators\Application;

use App\Indicators\Domain\IndicatorParameters;
use App\Indicators\Domain\WarmupStatus;

final readonly class WarmupPolicy
{
    public function __construct(private IndicatorParameters $parameters) {}

    public function requiredCandles(): int
    {
        return max(
            $this->parameters->emaEntryPeriod,
            $this->parameters->emaFastPeriod,
            $this->parameters->emaSlowPeriod,
            $this->parameters->smaPeriod,
            $this->parameters->rsiPeriod + 1,
            $this->parameters->macdSlowPeriod + $this->parameters->macdSignalPeriod - 1,
            $this->parameters->atrPeriod,
            $this->parameters->bollingerPeriod,
            $this->parameters->adxPeriod * 2,
            $this->parameters->vwapPeriod,
            $this->parameters->volumePeriod,
            $this->parameters->supportResistanceLookback,
        );
    }

    public function status(int $availableCandles): WarmupStatus
    {
        return new WarmupStatus($this->requiredCandles(), $availableCandles);
    }
}
