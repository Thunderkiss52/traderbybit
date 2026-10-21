<?php

declare(strict_types=1);

namespace Tests\Unit\QuoteIntegrity;

use App\QuoteIntegrity\Application\QimFeatureFlag;
use App\QuoteIntegrity\Application\QimGapRecorder;
use App\QuoteIntegrity\Domain\QimGapEventType;
use App\QuoteIntegrity\Infrastructure\Persistence\QimGapEventRepository;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class QimGapRecorderTest extends TestCase
{
    use RefreshDatabase;

    private QimGapRecorder $recorder;

    private QimGapEventRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        config(['quote_integrity.enabled' => true]);

        $this->repository = new QimGapEventRepository;
        $this->recorder = new QimGapRecorder(
            repository: $this->repository,
            featureFlag: new QimFeatureFlag,
            log: new NullLogger,
        );
    }

    public function test_records_detected_and_recovered_events_sharing_detection_id(): void
    {
        $gapFrom = CarbonImmutable::parse('2026-10-20T10:00:00Z');
        $gapTo = CarbonImmutable::parse('2026-10-20T10:30:00Z');

        $detectionId = $this->recorder->recordGapDetected(
            symbol: 'BTCUSDT',
            interval: '1m',
            gapFrom: $gapFrom,
            gapTo: $gapTo,
        );

        $this->assertNotNull($detectionId);
        $this->assertTrue(Uuid::isValid($detectionId));

        $recovered = $this->recorder->recordGapRecovered(
            detectionId: $detectionId,
            symbol: 'BTCUSDT',
            interval: '1m',
            gapFrom: $gapFrom,
            gapTo: $gapTo,
            outcome: 'RECOVERED',
            remainingMissingCount: 0,
        );

        $this->assertNotNull($recovered);
        $this->assertSame($detectionId, $recovered->detection_id);

        // Crucial test: both events survive! DETECTED is never erased or overwritten.
        $events = $this->repository->findByDetectionId($detectionId);
        $this->assertCount(2, $events);
        $this->assertSame(QimGapEventType::DETECTED->value, $events[0]->event_type);
        $this->assertSame(QimGapEventType::RECOVERED->value, $events[1]->event_type);
        $this->assertSame($detectionId, $events[0]->detection_id);
        $this->assertSame($detectionId, $events[1]->detection_id);
    }

    public function test_records_recovery_failed_event(): void
    {
        $gapFrom = CarbonImmutable::parse('2026-10-20T10:00:00Z');
        $gapTo = CarbonImmutable::parse('2026-10-20T10:30:00Z');

        $detectionId = $this->recorder->recordGapDetected(
            symbol: 'ETHUSDT',
            interval: '5m',
            gapFrom: $gapFrom,
            gapTo: $gapTo,
        );

        $failed = $this->recorder->recordGapRecoveryFailed(
            detectionId: $detectionId,
            symbol: 'ETHUSDT',
            interval: '5m',
            gapFrom: $gapFrom,
            gapTo: $gapTo,
            outcome: 'PARTIAL',
            remainingMissingCount: 2,
        );

        $this->assertNotNull($failed);
        $events = $this->repository->findByDetectionId($detectionId);
        $this->assertCount(2, $events);
        $this->assertSame(QimGapEventType::DETECTED->value, $events[0]->event_type);
        $this->assertSame(QimGapEventType::RECOVERY_FAILED->value, $events[1]->event_type);
        $this->assertSame(2, $events[1]->remaining_missing_count);
    }

    public function test_no_op_when_feature_flag_is_disabled(): void
    {
        config(['quote_integrity.enabled' => false]);

        $detectionId = $this->recorder->recordGapDetected(
            symbol: 'BTCUSDT',
            interval: '1m',
            gapFrom: CarbonImmutable::now(),
            gapTo: CarbonImmutable::now()->addHour(),
        );

        $this->assertNull($detectionId);
    }
}
