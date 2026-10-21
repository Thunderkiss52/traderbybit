<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Application;

use App\QuoteIntegrity\Domain\QimGapEventType;
use App\QuoteIntegrity\Infrastructure\Persistence\QimGapEventRecord;
use App\QuoteIntegrity\Infrastructure\Persistence\QimGapEventRepository;
use Carbon\CarbonImmutable;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

class QimGapRecorder
{
    public function __construct(
        private readonly QimGapEventRepository $repository,
        private readonly QimFeatureFlag $featureFlag,
        private readonly LoggerInterface $log,
    ) {}

    /** @param array<string, mixed>|null $metadata */
    public function recordGapDetected(
        string $symbol,
        string $interval,
        CarbonImmutable $gapFrom,
        CarbonImmutable $gapTo,
        string $channel = 'WS',
        string $provider = 'bybit',
        string $category = 'linear',
        ?array $metadata = null,
        ?string $detectionId = null,
    ): ?string {
        if (! $this->featureFlag->canRecordObservations()) {
            return null;
        }

        try {
            $detectionId = $detectionId ?? Uuid::uuid4()->toString();
            $now = CarbonImmutable::now();

            $this->repository->insert([
                'detection_id' => $detectionId,
                'event_type' => QimGapEventType::DETECTED->value,
                'provider' => $provider,
                'channel' => $channel,
                'category' => $category,
                'symbol' => $symbol,
                'interval' => $interval,
                'gap_from' => $gapFrom,
                'gap_to' => $gapTo,
                'detected_at' => $now,
                'resolved_at' => null,
                'outcome' => null,
                'remaining_missing_count' => 0,
                'metadata' => $metadata,
                'created_at' => $now,
            ]);

            return $detectionId;
        } catch (Throwable $e) {
            $this->log->warning('QIM gap detection recording failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** @param array<string, mixed>|null $metadata */
    public function recordGapRecovered(
        string $detectionId,
        string $symbol,
        string $interval,
        CarbonImmutable $gapFrom,
        CarbonImmutable $gapTo,
        string $outcome = 'RECOVERED',
        int $remainingMissingCount = 0,
        string $channel = 'WS',
        string $provider = 'bybit',
        string $category = 'linear',
        ?array $metadata = null,
    ): ?QimGapEventRecord {
        if (! $this->featureFlag->canRecordObservations()) {
            return null;
        }

        try {
            $now = CarbonImmutable::now();

            return $this->repository->insert([
                'detection_id' => $detectionId,
                'event_type' => QimGapEventType::RECOVERED->value,
                'provider' => $provider,
                'channel' => $channel,
                'category' => $category,
                'symbol' => $symbol,
                'interval' => $interval,
                'gap_from' => $gapFrom,
                'gap_to' => $gapTo,
                'detected_at' => $now,
                'resolved_at' => $now,
                'outcome' => $outcome,
                'remaining_missing_count' => $remainingMissingCount,
                'metadata' => $metadata,
                'created_at' => $now,
            ]);
        } catch (Throwable $e) {
            $this->log->warning('QIM gap recovered recording failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** @param array<string, mixed>|null $metadata */
    public function recordGapRecoveryFailed(
        string $detectionId,
        string $symbol,
        string $interval,
        CarbonImmutable $gapFrom,
        CarbonImmutable $gapTo,
        string $outcome = 'FAILED',
        int $remainingMissingCount = 0,
        string $channel = 'WS',
        string $provider = 'bybit',
        string $category = 'linear',
        ?array $metadata = null,
    ): ?QimGapEventRecord {
        if (! $this->featureFlag->canRecordObservations()) {
            return null;
        }

        try {
            $now = CarbonImmutable::now();

            return $this->repository->insert([
                'detection_id' => $detectionId,
                'event_type' => QimGapEventType::RECOVERY_FAILED->value,
                'provider' => $provider,
                'channel' => $channel,
                'category' => $category,
                'symbol' => $symbol,
                'interval' => $interval,
                'gap_from' => $gapFrom,
                'gap_to' => $gapTo,
                'detected_at' => $now,
                'resolved_at' => $now,
                'outcome' => $outcome,
                'remaining_missing_count' => $remainingMissingCount,
                'metadata' => $metadata,
                'created_at' => $now,
            ]);
        } catch (Throwable $e) {
            $this->log->warning('QIM gap recovery failure recording failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
