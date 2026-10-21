<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Application;

use App\QuoteIntegrity\Domain\QimChannel;
use App\QuoteIntegrity\Domain\QimClassification;
use App\QuoteIntegrity\Infrastructure\Persistence\QimObservationRecord;
use App\QuoteIntegrity\Infrastructure\Persistence\QimObservationRepository;
use Carbon\CarbonImmutable;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

class QimObservationService
{
    public function __construct(
        private readonly QimObservationRepository $repository,
        private readonly QimFeatureFlag $featureFlag,
        private readonly LoggerInterface $log,
    ) {}

    /**
     * @param array{
     *     provider: string,
     *     channel: QimChannel|string,
     *     category: string,
     *     symbol: string,
     *     interval: string,
     *     candle_open_time: CarbonImmutable|string,
     *     candle_close_time: CarbonImmutable|string,
     *     open: string,
     *     high: string,
     *     low: string,
     *     close: string,
     *     volume: string,
     *     turnover?: ?string,
     *     is_closed?: bool,
     *     request_sent_at?: CarbonImmutable|string|null,
     *     response_received_at?: CarbonImmutable|string|null,
     *     provider_event_time?: CarbonImmutable|string|null,
     *     local_received_at?: CarbonImmutable|string|null,
     *     clock_sync_state?: string,
     *     clock_offset_estimate_ms?: ?int,
     *     source_event_id?: ?string,
     *     raw_payload: string,
     *     normalization_rule_version?: string,
     * } $data
     */
    public function recordObservation(array $data): ?QimObservationRecord
    {
        if (! $this->featureFlag->canRecordObservations()) {
            return null;
        }

        try {
            $channel = $data['channel'] instanceof QimChannel ? $data['channel']->value : (string) $data['channel'];
            $provider = (string) ($data['provider'] ?? 'bybit');
            $category = (string) ($data['category'] ?? 'linear');
            $symbol = (string) $data['symbol'];
            $interval = (string) $data['interval'];

            $candleOpenTime = $data['candle_open_time'] instanceof CarbonImmutable
                ? $data['candle_open_time']
                : CarbonImmutable::parse($data['candle_open_time']);

            $candleCloseTime = $data['candle_close_time'] instanceof CarbonImmutable
                ? $data['candle_close_time']
                : CarbonImmutable::parse($data['candle_close_time']);

            $rawPayload = (string) $data['raw_payload'];
            $payloadSha256 = hash('sha256', $rawPayload);

            $logicalKeyString = sprintf(
                '%s:%s:%s:%s:%s:%d',
                $provider,
                $channel,
                $category,
                $symbol,
                $interval,
                $candleOpenTime->getTimestampMs(),
            );
            $logicalKey = Uuid::uuid5(Uuid::NAMESPACE_OID, $logicalKeyString)->toString();
            $captureAttemptId = Uuid::uuid4()->toString();

            $existing = $this->repository->findLatestByLogicalKey($logicalKey);
            $revision = 1;
            $duplicateClassification = null;

            if ($existing !== null) {
                $revision = $existing->revision + 1;

                if ($existing->payload_sha256 === $payloadSha256) {
                    $duplicateClassification = QimClassification::DUPLICATE_EXACT->value;
                } else {
                    $pricesMatch = bccomp((string) $existing->open, (string) $data['open'], 8) === 0
                        && bccomp((string) $existing->high, (string) $data['high'], 8) === 0
                        && bccomp((string) $existing->low, (string) $data['low'], 8) === 0
                        && bccomp((string) $existing->close, (string) $data['close'], 8) === 0
                        && bccomp((string) $existing->volume, (string) $data['volume'], 8) === 0;

                    $ruleVersion = $data['normalization_rule_version'] ?? config('quote_integrity.rules_version', '1.0.0');

                    if (! $pricesMatch) {
                        $duplicateClassification = QimClassification::DUPLICATE_CONFLICTING->value;
                    } elseif ($existing->normalization_rule_version !== $ruleVersion) {
                        $duplicateClassification = QimClassification::DUPLICATE_IDENTITY_CONFIG->value;
                    } else {
                        $duplicateClassification = QimClassification::DUPLICATE_EXACT->value;
                    }
                }
            }

            $localReceivedAt = isset($data['local_received_at'])
                ? ($data['local_received_at'] instanceof CarbonImmutable
                    ? $data['local_received_at']
                    : CarbonImmutable::parse($data['local_received_at']))
                : CarbonImmutable::now();

            $ruleVersion = $data['normalization_rule_version'] ?? config('quote_integrity.rules_version', '1.0.0');

            return $this->repository->insert([
                'provider' => $provider,
                'channel' => $channel,
                'category' => $category,
                'symbol' => $symbol,
                'interval' => $interval,
                'candle_open_time' => $candleOpenTime,
                'candle_close_time' => $candleCloseTime,
                'open' => (string) $data['open'],
                'high' => (string) $data['high'],
                'low' => (string) $data['low'],
                'close' => (string) $data['close'],
                'volume' => (string) $data['volume'],
                'turnover' => isset($data['turnover']) ? (string) $data['turnover'] : null,
                'is_closed' => (bool) ($data['is_closed'] ?? true),
                'request_sent_at' => $data['request_sent_at'] ?? null,
                'response_received_at' => $data['response_received_at'] ?? null,
                'provider_event_time' => $data['provider_event_time'] ?? null,
                'local_received_at' => $localReceivedAt,
                'clock_sync_state' => $data['clock_sync_state'] ?? 'CLOCK_SYNC_UNKNOWN',
                'clock_offset_estimate_ms' => $data['clock_offset_estimate_ms'] ?? null,
                'logical_key' => $logicalKey,
                'capture_attempt_id' => $captureAttemptId,
                'source_event_id' => $data['source_event_id'] ?? null,
                'revision' => $revision,
                'payload_sha256' => $payloadSha256,
                'raw_payload' => $rawPayload,
                'quality_state' => 'OK',
                'duplicate_classification' => $duplicateClassification,
                'normalization_rule_version' => $ruleVersion,
                'created_at' => CarbonImmutable::now(),
            ]);
        } catch (Throwable $e) {
            $this->log->warning('QIM observation recording failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
