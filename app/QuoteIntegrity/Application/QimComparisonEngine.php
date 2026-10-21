<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Application;

use App\QuoteIntegrity\Domain\QimClassification;
use App\QuoteIntegrity\Domain\QimClockSyncState;
use App\QuoteIntegrity\Infrastructure\Persistence\QimComparisonRecord;
use App\QuoteIntegrity\Infrastructure\Persistence\QimObservationRecord;
use Carbon\CarbonImmutable;
use Ramsey\Uuid\Uuid;

class QimComparisonEngine
{
    public function __construct(
        private readonly QimFeatureFlag $featureFlag,
    ) {}

    public function compare(
        ?QimObservationRecord $rest,
        ?QimObservationRecord $ws,
        ?string $comparisonId = null,
        int $version = 1,
    ): ?QimComparisonRecord {
        if (! $this->featureFlag->canRecordObservations()) {
            return null;
        }

        $ruleVersion = (string) config('quote_integrity.rules_version', '1.0.0');
        $comparisonId = $comparisonId ?? Uuid::uuid4()->toString();
        $now = CarbonImmutable::now();

        // 1. Missing either side -> INSUFFICIENT_EVIDENCE
        if ($rest === null || $ws === null) {
            $missing = $rest === null ? 'REST' : 'WS';
            $ref = $rest ?? $ws;

            return QimComparisonRecord::create([
                'comparison_id' => $comparisonId,
                'version' => $version,
                'provider' => $ref?->provider ?? 'bybit',
                'category' => $ref?->category ?? 'linear',
                'symbol' => $ref?->symbol ?? 'UNKNOWN',
                'interval' => $ref?->interval ?? '1m',
                'candle_open_time' => $ref?->candle_open_time ?? $now,
                'candle_close_time' => $ref?->candle_close_time ?? $now->addMinute(),
                'rest_observation_id' => $rest?->id,
                'ws_observation_id' => $ws?->id,
                'classification' => QimClassification::INSUFFICIENT_EVIDENCE->value,
                'price_diff_abs' => null,
                'price_diff_rel' => null,
                'volume_diff_abs' => null,
                'volume_diff_rel' => null,
                'timestamp_skew_ms' => null,
                'rule_version' => $ruleVersion,
                'explanation' => "Incomplete input: missing {$missing} observation for candle slot.",
                'is_comparable' => false,
                'compared_at' => $now,
                'created_at' => $now,
            ]);
        }

        // 2. Check compatibility: category, symbol, interval, candle_open_time
        if ($rest->category !== $ws->category
            || $rest->symbol !== $ws->symbol
            || $rest->interval !== $ws->interval
            || ! $rest->candle_open_time->equalTo($ws->candle_open_time)) {
            return QimComparisonRecord::create([
                'comparison_id' => $comparisonId,
                'version' => $version,
                'provider' => $rest->provider,
                'category' => $rest->category,
                'symbol' => $rest->symbol,
                'interval' => $rest->interval,
                'candle_open_time' => $rest->candle_open_time,
                'candle_close_time' => $rest->candle_close_time,
                'rest_observation_id' => $rest->id,
                'ws_observation_id' => $ws->id,
                'classification' => QimClassification::INSUFFICIENT_EVIDENCE->value,
                'rule_version' => $ruleVersion,
                'explanation' => 'Observations do not share identical slot identity (category/symbol/interval/candle_open_time).',
                'is_comparable' => false,
                'compared_at' => $now,
                'created_at' => $now,
            ]);
        }

        // 3. WS confirm=true check
        if (! $ws->is_closed) {
            return QimComparisonRecord::create([
                'comparison_id' => $comparisonId,
                'version' => $version,
                'provider' => $rest->provider,
                'category' => $rest->category,
                'symbol' => $rest->symbol,
                'interval' => $rest->interval,
                'candle_open_time' => $rest->candle_open_time,
                'candle_close_time' => $rest->candle_close_time,
                'rest_observation_id' => $rest->id,
                'ws_observation_id' => $ws->id,
                'classification' => QimClassification::INSUFFICIENT_EVIDENCE->value,
                'rule_version' => $ruleVersion,
                'explanation' => 'WS observation is not confirmed closed (confirm != true).',
                'is_comparable' => false,
                'compared_at' => $now,
                'created_at' => $now,
            ]);
        }

        // 4. REST grace window check: response must be received after candle close + grace period
        $graceSeconds = (int) config('quote_integrity.grace_period_seconds', 10);
        $minimumRestReceiptTime = $rest->candle_close_time->addSeconds($graceSeconds);

        if ($rest->response_received_at !== null && $rest->response_received_at->isBefore($minimumRestReceiptTime)) {
            return QimComparisonRecord::create([
                'comparison_id' => $comparisonId,
                'version' => $version,
                'provider' => $rest->provider,
                'category' => $rest->category,
                'symbol' => $rest->symbol,
                'interval' => $rest->interval,
                'candle_open_time' => $rest->candle_open_time,
                'candle_close_time' => $rest->candle_close_time,
                'rest_observation_id' => $rest->id,
                'ws_observation_id' => $ws->id,
                'classification' => QimClassification::INSUFFICIENT_EVIDENCE->value,
                'rule_version' => $ruleVersion,
                'explanation' => "REST sampling occurred before candle close + grace window ({$graceSeconds}s).",
                'is_comparable' => false,
                'compared_at' => $now,
                'created_at' => $now,
            ]);
        }

        // 5. Calculate price & volume differences via bccomp / bcsub
        $diffOpen = bcsub((string) $rest->open, (string) $ws->open, 8);
        $diffHigh = bcsub((string) $rest->high, (string) $ws->high, 8);
        $diffLow = bcsub((string) $rest->low, (string) $ws->low, 8);
        $diffClose = bcsub((string) $rest->close, (string) $ws->close, 8);
        $diffVolume = bcsub((string) $rest->volume, (string) $ws->volume, 8);

        $maxPriceDiffAbs = number_format(max(
            abs((float) $diffOpen),
            abs((float) $diffHigh),
            abs((float) $diffLow),
            abs((float) $diffClose),
        ), 8, '.', '');

        $relPriceDiff = (float) $rest->close > 0
            ? abs((float) $diffClose) / (float) $rest->close
            : 0.0;

        $relVolumeDiff = (float) $rest->volume > 0
            ? abs((float) $diffVolume) / (float) $rest->volume
            : 0.0;

        $skewMs = abs($rest->local_received_at->diffInMilliseconds($ws->local_received_at));

        // 6. Check STALE_DATA
        $maxDataAge = (int) config('quote_integrity.max_data_age_seconds', 300);
        $restAge = abs($now->diffInSeconds($rest->candle_close_time));
        $wsAge = abs($now->diffInSeconds($ws->candle_close_time));
        $restReceiptAge = abs($rest->local_received_at->diffInSeconds($rest->candle_close_time));
        $wsReceiptAge = abs($ws->local_received_at->diffInSeconds($ws->candle_close_time));

        $isStale = $restAge > $maxDataAge
            || $wsAge > $maxDataAge
            || $restReceiptAge > $maxDataAge
            || $wsReceiptAge > $maxDataAge;

        // 7. Check Clock Sync
        $isClockSyncUnknown = $rest->clock_sync_state === QimClockSyncState::CLOCK_SYNC_UNKNOWN->value
            || $ws->clock_sync_state === QimClockSyncState::CLOCK_SYNC_UNKNOWN->value;
        $isTimestampUnreliable = $rest->clock_sync_state === QimClockSyncState::TIMESTAMP_UNRELIABLE->value
            || $ws->clock_sync_state === QimClockSyncState::TIMESTAMP_UNRELIABLE->value;

        // 8. Classify
        $priceTolAbs = (float) config('quote_integrity.tolerances.price_absolute', '0.00000001');
        $priceTolRel = (float) config('quote_integrity.tolerances.price_relative', '0.0001');

        $priceMismatch = (float) $maxPriceDiffAbs > $priceTolAbs && $relPriceDiff > $priceTolRel;

        if ($priceMismatch) {
            $classification = QimClassification::CHANNEL_INCONSISTENCY->value;
            $explanation = "REST and WS differ beyond tolerance: max price diff={$maxPriceDiffAbs}, rel diff={$relPriceDiff}";
        } elseif ($isStale) {
            $classification = QimClassification::STALE_DATA->value;
            $explanation = "Observation exceeds max data age threshold ({$maxDataAge}s).";
        } elseif ($isTimestampUnreliable) {
            $classification = QimClassification::TIMESTAMP_UNRELIABLE->value;
            $explanation = 'Clock synchronization is unreliable.';
        } elseif ($isClockSyncUnknown) {
            $classification = QimClassification::CLOCK_SYNC_UNKNOWN->value;
            $explanation = 'Clock synchronization state is unverified; lower comparison confidence.';
        } else {
            $classification = QimClassification::MATCHED->value;
            $explanation = 'REST and WS observations match within configured tolerances.';
        }

        return QimComparisonRecord::create([
            'comparison_id' => $comparisonId,
            'version' => $version,
            'provider' => $rest->provider,
            'category' => $rest->category,
            'symbol' => $rest->symbol,
            'interval' => $rest->interval,
            'candle_open_time' => $rest->candle_open_time,
            'candle_close_time' => $rest->candle_close_time,
            'rest_observation_id' => $rest->id,
            'ws_observation_id' => $ws->id,
            'classification' => $classification,
            'price_diff_abs' => $maxPriceDiffAbs,
            'price_diff_rel' => (string) round($relPriceDiff, 6),
            'volume_diff_abs' => (string) abs((float) $diffVolume),
            'volume_diff_rel' => (string) round($relVolumeDiff, 6),
            'timestamp_skew_ms' => $skewMs,
            'rule_version' => $ruleVersion,
            'explanation' => $explanation,
            'is_comparable' => true,
            'compared_at' => $now,
            'created_at' => $now,
        ]);
    }
}
