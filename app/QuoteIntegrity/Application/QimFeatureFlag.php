<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Application;

use Illuminate\Support\Facades\Cache;

final readonly class QimFeatureFlag
{
    public const CAP_REACHED_KEY = 'qim:measurement_cap_reached';

    public function isEnabled(): bool
    {
        return (bool) config('quote_integrity.enabled', false);
    }

    public function isMeasurementMode(): bool
    {
        return $this->isEnabled() && (bool) config('quote_integrity.measurement_mode', false);
    }

    public function isCapReached(): bool
    {
        return (bool) Cache::get(self::CAP_REACHED_KEY, false);
    }

    public function canRecordObservations(): bool
    {
        return $this->isEnabled() && ! $this->isCapReached();
    }
}
