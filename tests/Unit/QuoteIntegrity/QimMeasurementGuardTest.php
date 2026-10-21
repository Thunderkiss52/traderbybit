<?php

declare(strict_types=1);

namespace Tests\Unit\QuoteIntegrity;

use App\QuoteIntegrity\Application\QimFeatureFlag;
use App\QuoteIntegrity\Application\QimIncidentService;
use App\QuoteIntegrity\Application\QimMeasurementGuard;
use App\QuoteIntegrity\Infrastructure\Persistence\QimIncidentRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

final class QimMeasurementGuardTest extends TestCase
{
    use RefreshDatabase;

    private QimMeasurementGuard $guard;

    private QimFeatureFlag $featureFlag;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->featureFlag = new QimFeatureFlag;
        $this->guard = new QimMeasurementGuard(
            featureFlag: $this->featureFlag,
            incidentService: new QimIncidentService,
        );
    }

    public function test_validate_measurement_limits_throws_when_limits_unset(): void
    {
        config([
            'quote_integrity.enabled' => true,
            'quote_integrity.measurement_mode' => true,
            'quote_integrity.measurement_disk_cap_gb' => null,
            'quote_integrity.measurement_ttl_days' => null,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MEASUREMENT_LIMITS_UNSET');

        $this->guard->validateMeasurementLimits();
    }

    public function test_validate_measurement_limits_throws_when_values_non_positive(): void
    {
        config([
            'quote_integrity.enabled' => true,
            'quote_integrity.measurement_mode' => true,
            'quote_integrity.measurement_disk_cap_gb' => 0,
            'quote_integrity.measurement_ttl_days' => -5,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MEASUREMENT_LIMITS_UNSET');

        $this->guard->validateMeasurementLimits();
    }

    public function test_validate_measurement_limits_succeeds_with_positive_limits(): void
    {
        config([
            'quote_integrity.enabled' => true,
            'quote_integrity.measurement_mode' => true,
            'quote_integrity.measurement_disk_cap_gb' => 10,
            'quote_integrity.measurement_ttl_days' => 7,
        ]);

        $this->guard->validateMeasurementLimits();
        $this->assertTrue(true, 'Validation passed without throwing');
    }

    public function test_disk_cap_breach_stops_qim_capture_and_records_critical_incident(): void
    {
        config([
            'quote_integrity.enabled' => true,
            'quote_integrity.measurement_disk_cap_gb' => 5, // 5 GB
        ]);

        $this->assertTrue($this->featureFlag->canRecordObservations());

        // Simulate 6 GB usage (breaching the 5 GB cap)
        $sixGbBytes = 6 * 1024 * 1024 * 1024;
        $breached = $this->guard->checkDiskCap($sixGbBytes);

        $this->assertTrue($breached);
        $this->assertTrue($this->featureFlag->isCapReached());
        $this->assertFalse($this->featureFlag->canRecordObservations(), 'QIM writes must stop when disk cap is reached');

        // Check critical incident
        $incident = QimIncidentRecord::where('incident_key', 'MEASUREMENT_DISK_CAP_REACHED')->first();
        $this->assertNotNull($incident);
        $this->assertSame('CRITICAL', $incident->severity);
        $this->assertSame('P1', $incident->priority);
    }
}
