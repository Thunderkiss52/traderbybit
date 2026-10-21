<?php

declare(strict_types=1);

namespace Tests\Unit\QuoteIntegrity;

use App\QuoteIntegrity\Application\QimComparisonEngine;
use App\QuoteIntegrity\Application\QimFeatureFlag;
use App\QuoteIntegrity\Domain\QimClassification;
use App\QuoteIntegrity\Domain\QimClockSyncState;
use App\QuoteIntegrity\Infrastructure\Persistence\QimObservationRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class QimComparisonEngineTest extends TestCase
{
    use RefreshDatabase;

    private QimComparisonEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'quote_integrity.enabled' => true,
            'quote_integrity.grace_period_seconds' => 10,
            'quote_integrity.max_data_age_seconds' => 300,
            'quote_integrity.tolerances.price_absolute' => '0.00000001',
            'quote_integrity.tolerances.price_relative' => '0.0001',
        ]);

        $this->engine = new QimComparisonEngine(new QimFeatureFlag);
    }

    private function createObservation(array $overrides = []): QimObservationRecord
    {
        $open = CarbonImmutable::now()->subMinute();
        $close = $open->addMinute();
        $defaults = [
            'provider' => 'bybit',
            'channel' => 'REST',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '1m',
            'candle_open_time' => $open,
            'candle_close_time' => $close,
            'open' => '65000.00000000',
            'high' => '65100.00000000',
            'low' => '64950.00000000',
            'close' => '65050.00000000',
            'volume' => '10.50000000',
            'turnover' => '683025.00000000',
            'is_closed' => true,
            'request_sent_at' => $close->addSeconds(12),
            'response_received_at' => $close->addSeconds(13),
            'local_received_at' => $close->addSeconds(13),
            'clock_sync_state' => QimClockSyncState::SYNCHRONIZED->value,
            'logical_key' => 'test-key',
            'capture_attempt_id' => 'test-attempt',
            'payload_sha256' => 'dummy',
            'raw_payload' => '{}',
            'created_at' => CarbonImmutable::now(),
        ];

        return QimObservationRecord::create(array_merge($defaults, $overrides));
    }

    public function test_matched_when_prices_agree_within_tolerance(): void
    {
        $open = CarbonImmutable::now()->subSeconds(30);
        $close = $open->addMinute();

        $rest = $this->createObservation([
            'channel' => 'REST',
            'candle_open_time' => $open,
            'candle_close_time' => $close,
            'response_received_at' => $close->addSeconds(15),
            'clock_sync_state' => QimClockSyncState::SYNCHRONIZED->value,
            'close' => '65050.00000000',
        ]);

        $ws = $this->createObservation([
            'channel' => 'WS',
            'candle_open_time' => $open,
            'candle_close_time' => $close,
            'clock_sync_state' => QimClockSyncState::SYNCHRONIZED->value,
            'close' => '65050.00000000',
        ]);

        $comparison = $this->engine->compare($rest, $ws);

        $this->assertNotNull($comparison);
        $this->assertSame(QimClassification::MATCHED->value, $comparison->classification);
        $this->assertTrue($comparison->is_comparable);
        $this->assertSame('0', (string) ((float) $comparison->price_diff_abs));
    }

    public function test_channel_inconsistency_when_prices_differ_beyond_tolerance(): void
    {
        $open = CarbonImmutable::now()->subSeconds(30);
        $close = $open->addMinute();

        $rest = $this->createObservation([
            'channel' => 'REST',
            'candle_open_time' => $open,
            'candle_close_time' => $close,
            'response_received_at' => $close->addSeconds(15),
            'clock_sync_state' => QimClockSyncState::SYNCHRONIZED->value,
            'close' => '65050.00000000',
        ]);

        $ws = $this->createObservation([
            'channel' => 'WS',
            'candle_open_time' => $open,
            'candle_close_time' => $close,
            'clock_sync_state' => QimClockSyncState::SYNCHRONIZED->value,
            'close' => '65120.00000000', // Differ by $70 (exceeds 0.01%)
        ]);

        $comparison = $this->engine->compare($rest, $ws);

        $this->assertNotNull($comparison);
        $this->assertSame(QimClassification::CHANNEL_INCONSISTENCY->value, $comparison->classification);
        $this->assertTrue($comparison->is_comparable);
        $this->assertSame('70.00000000', (string) $comparison->price_diff_abs);
    }

    public function test_stale_data_when_observation_exceeds_max_data_age(): void
    {
        // 10 minutes old (exceeds 300s limit)
        $open = CarbonImmutable::now()->subMinutes(10);
        $close = $open->addMinute();

        $rest = $this->createObservation([
            'channel' => 'REST',
            'candle_open_time' => $open,
            'candle_close_time' => $close,
            'response_received_at' => $close->addSeconds(15),
            'clock_sync_state' => QimClockSyncState::SYNCHRONIZED->value,
        ]);

        $ws = $this->createObservation([
            'channel' => 'WS',
            'candle_open_time' => $open,
            'candle_close_time' => $close,
            'clock_sync_state' => QimClockSyncState::SYNCHRONIZED->value,
        ]);

        $comparison = $this->engine->compare($rest, $ws);

        $this->assertNotNull($comparison);
        $this->assertSame(QimClassification::STALE_DATA->value, $comparison->classification);
    }

    public function test_clock_sync_unknown_when_clock_is_not_verified(): void
    {
        $open = CarbonImmutable::now()->subSeconds(30);
        $close = $open->addMinute();

        $rest = $this->createObservation([
            'channel' => 'REST',
            'candle_open_time' => $open,
            'candle_close_time' => $close,
            'response_received_at' => $close->addSeconds(15),
            'clock_sync_state' => QimClockSyncState::CLOCK_SYNC_UNKNOWN->value,
        ]);

        $ws = $this->createObservation([
            'channel' => 'WS',
            'candle_open_time' => $open,
            'candle_close_time' => $close,
            'clock_sync_state' => QimClockSyncState::SYNCHRONIZED->value,
        ]);

        $comparison = $this->engine->compare($rest, $ws);

        $this->assertNotNull($comparison);
        $this->assertSame(QimClassification::CLOCK_SYNC_UNKNOWN->value, $comparison->classification);
    }

    public function test_timestamp_unreliable_when_clock_is_unreliable(): void
    {
        $open = CarbonImmutable::now()->subSeconds(30);
        $close = $open->addMinute();

        $rest = $this->createObservation([
            'channel' => 'REST',
            'candle_open_time' => $open,
            'candle_close_time' => $close,
            'response_received_at' => $close->addSeconds(15),
            'clock_sync_state' => QimClockSyncState::TIMESTAMP_UNRELIABLE->value,
        ]);

        $ws = $this->createObservation([
            'channel' => 'WS',
            'candle_open_time' => $open,
            'candle_close_time' => $close,
            'clock_sync_state' => QimClockSyncState::SYNCHRONIZED->value,
        ]);

        $comparison = $this->engine->compare($rest, $ws);

        $this->assertNotNull($comparison);
        $this->assertSame(QimClassification::TIMESTAMP_UNRELIABLE->value, $comparison->classification);
    }

    public function test_insufficient_evidence_when_rest_missing(): void
    {
        $ws = $this->createObservation(['channel' => 'WS']);
        $comparison = $this->engine->compare(null, $ws);

        $this->assertNotNull($comparison);
        $this->assertSame(QimClassification::INSUFFICIENT_EVIDENCE->value, $comparison->classification);
        $this->assertFalse($comparison->is_comparable);
    }

    public function test_insufficient_evidence_when_ws_not_confirmed(): void
    {
        $open = CarbonImmutable::now()->subSeconds(30);
        $close = $open->addMinute();

        $rest = $this->createObservation([
            'channel' => 'REST',
            'candle_open_time' => $open,
            'candle_close_time' => $close,
            'response_received_at' => $close->addSeconds(15),
        ]);

        $ws = $this->createObservation([
            'channel' => 'WS',
            'candle_open_time' => $open,
            'candle_close_time' => $close,
            'is_closed' => false, // Open/unconfirmed candle
        ]);

        $comparison = $this->engine->compare($rest, $ws);

        $this->assertNotNull($comparison);
        $this->assertSame(QimClassification::INSUFFICIENT_EVIDENCE->value, $comparison->classification);
        $this->assertFalse($comparison->is_comparable);
    }

    public function test_insufficient_evidence_when_rest_sampled_before_grace_window(): void
    {
        $open = CarbonImmutable::now()->subSeconds(30);
        $close = $open->addMinute();

        $rest = $this->createObservation([
            'channel' => 'REST',
            'candle_open_time' => $open,
            'candle_close_time' => $close,
            'response_received_at' => $close->addSeconds(2), // only 2s elapsed, grace window is 10s!
        ]);

        $ws = $this->createObservation([
            'channel' => 'WS',
            'candle_open_time' => $open,
            'candle_close_time' => $close,
            'is_closed' => true,
        ]);

        $comparison = $this->engine->compare($rest, $ws);

        $this->assertNotNull($comparison);
        $this->assertSame(QimClassification::INSUFFICIENT_EVIDENCE->value, $comparison->classification);
        $this->assertFalse($comparison->is_comparable);
    }
}
