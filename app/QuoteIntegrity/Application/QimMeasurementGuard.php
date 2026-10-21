<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Application;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

class QimMeasurementGuard
{
    public function __construct(
        private readonly QimFeatureFlag $featureFlag,
        private readonly QimIncidentService $incidentService,
    ) {}

    public function validateMeasurementLimits(): void
    {
        if (! (bool) config('quote_integrity.measurement_mode', false)) {
            return;
        }

        $capGb = config('quote_integrity.measurement_disk_cap_gb');
        $ttlDays = config('quote_integrity.measurement_ttl_days');

        if ($capGb === null || (int) $capGb <= 0 || $ttlDays === null || (int) $ttlDays <= 0) {
            throw new RuntimeException('MEASUREMENT_LIMITS_UNSET: Measurement mode requires positive disk cap and TTL.');
        }
    }

    public function checkDiskCap(int $currentUsageBytes): bool
    {
        $capGb = (int) config('quote_integrity.measurement_disk_cap_gb', 10);
        $capBytes = $capGb * 1024 * 1024 * 1024;

        if ($currentUsageBytes >= $capBytes) {
            Cache::forever(QimFeatureFlag::CAP_REACHED_KEY, true);

            $this->incidentService->recordIncident(
                incidentKey: 'MEASUREMENT_DISK_CAP_REACHED',
                title: 'QIM Measurement Disk Cap Reached',
                severity: 'CRITICAL',
                priority: 'P1',
                description: "QIM storage usage ({$currentUsageBytes} bytes) reached or exceeded configured disk cap ({$capBytes} bytes). QIM capture stopped; trading ingestion unaffected.",
            );

            return true;
        }

        return false;
    }

    public function resetCap(): void
    {
        Cache::forget(QimFeatureFlag::CAP_REACHED_KEY);
    }
}
