<?php

declare(strict_types=1);

namespace Tests\Unit\QuoteIntegrity;

use App\QuoteIntegrity\Application\QimFeatureFlag;
use App\QuoteIntegrity\Application\QimObservationService;
use App\QuoteIntegrity\Domain\QimClassification;
use App\QuoteIntegrity\Infrastructure\Persistence\QimObservationRepository;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class QimObservationServiceTest extends TestCase
{
    use RefreshDatabase;

    private QimObservationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['quote_integrity.enabled' => true]);

        $this->service = new QimObservationService(
            repository: new QimObservationRepository,
            featureFlag: new QimFeatureFlag,
            log: new NullLogger,
        );
    }

    public function test_computes_logical_key_as_uuidv5_and_sha256_from_raw_bytes(): void
    {
        $openTime = CarbonImmutable::parse('2026-10-20T12:00:00.000Z');
        $closeTime = $openTime->addMinute();
        $rawBytes = '{"topic":"kline.1.BTCUSDT","data":[{"confirm":true,"open":"65000.5"}],"ts":1729425600000}';
        $expectedSha256 = hash('sha256', $rawBytes);

        $record = $this->service->recordObservation([
            'provider' => 'bybit',
            'channel' => 'WS',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '1m',
            'candle_open_time' => $openTime,
            'candle_close_time' => $closeTime,
            'open' => '65000.50000000',
            'high' => '65100.00000000',
            'low' => '64950.00000000',
            'close' => '65050.00000000',
            'volume' => '10.50000000',
            'raw_payload' => $rawBytes,
        ]);

        $this->assertNotNull($record);
        $this->assertSame($expectedSha256, $record->payload_sha256);
        $this->assertTrue(Uuid::isValid($record->logical_key));
        $this->assertSame(5, Uuid::fromString($record->logical_key)->getVersion());
        $this->assertSame(1, $record->revision);
        $this->assertNull($record->duplicate_classification);
    }

    public function test_detects_duplicate_exact(): void
    {
        $openTime = CarbonImmutable::parse('2026-10-20T12:00:00.000Z');
        $closeTime = $openTime->addMinute();
        $rawBytes = '{"topic":"kline.1.BTCUSDT","raw":"exact-sample-payload"}';

        $first = $this->service->recordObservation([
            'provider' => 'bybit',
            'channel' => 'WS',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '1m',
            'candle_open_time' => $openTime,
            'candle_close_time' => $closeTime,
            'open' => '65000.00000000',
            'high' => '65100.00000000',
            'low' => '64950.00000000',
            'close' => '65050.00000000',
            'volume' => '10.50000000',
            'raw_payload' => $rawBytes,
        ]);

        $second = $this->service->recordObservation([
            'provider' => 'bybit',
            'channel' => 'WS',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '1m',
            'candle_open_time' => $openTime,
            'candle_close_time' => $closeTime,
            'open' => '65000.00000000',
            'high' => '65100.00000000',
            'low' => '64950.00000000',
            'close' => '65050.00000000',
            'volume' => '10.50000000',
            'raw_payload' => $rawBytes,
        ]);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame(1, $first->revision);
        $this->assertSame(2, $second->revision);
        $this->assertSame($first->logical_key, $second->logical_key);
        $this->assertSame(QimClassification::DUPLICATE_EXACT->value, $second->duplicate_classification);
    }

    public function test_detects_duplicate_conflicting_and_preserves_both_versions(): void
    {
        $openTime = CarbonImmutable::parse('2026-10-20T12:00:00.000Z');
        $closeTime = $openTime->addMinute();

        $first = $this->service->recordObservation([
            'provider' => 'bybit',
            'channel' => 'WS',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '1m',
            'candle_open_time' => $openTime,
            'candle_close_time' => $closeTime,
            'open' => '65000.00000000',
            'high' => '65100.00000000',
            'low' => '64950.00000000',
            'close' => '65050.00000000',
            'volume' => '10.50000000',
            'raw_payload' => 'payload-v1',
        ]);

        $second = $this->service->recordObservation([
            'provider' => 'bybit',
            'channel' => 'WS',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '1m',
            'candle_open_time' => $openTime,
            'candle_close_time' => $closeTime,
            'open' => '65000.00000000',
            'high' => '65200.00000000', // Conflicting high!
            'low' => '64950.00000000',
            'close' => '65150.00000000', // Conflicting close!
            'volume' => '12.00000000',
            'raw_payload' => 'payload-v2-different',
        ]);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame(2, $second->revision);
        $this->assertSame(QimClassification::DUPLICATE_CONFLICTING->value, $second->duplicate_classification);

        // Verify both records exist in DB
        $repo = new QimObservationRepository;
        $all = $repo->findAllByLogicalKey($first->logical_key);
        $this->assertCount(2, $all);
        $this->assertSame('payload-v1', $all[0]->raw_payload);
        $this->assertSame('payload-v2-different', $all[1]->raw_payload);
    }

    public function test_detects_duplicate_identity_config_when_rule_version_differs(): void
    {
        $openTime = CarbonImmutable::parse('2026-10-20T12:00:00.000Z');
        $closeTime = $openTime->addMinute();

        $first = $this->service->recordObservation([
            'provider' => 'bybit',
            'channel' => 'WS',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '1m',
            'candle_open_time' => $openTime,
            'candle_close_time' => $closeTime,
            'open' => '65000.00000000',
            'high' => '65100.00000000',
            'low' => '64950.00000000',
            'close' => '65050.00000000',
            'volume' => '10.50000000',
            'raw_payload' => 'payload-v1',
            'normalization_rule_version' => '1.0.0',
        ]);

        $second = $this->service->recordObservation([
            'provider' => 'bybit',
            'channel' => 'WS',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '1m',
            'candle_open_time' => $openTime,
            'candle_close_time' => $closeTime,
            'open' => '65000.00000000',
            'high' => '65100.00000000',
            'low' => '64950.00000000',
            'close' => '65050.00000000',
            'volume' => '10.50000000',
            'raw_payload' => 'payload-v1-reconfigured',
            'normalization_rule_version' => '2.0.0',
        ]);

        $this->assertNotNull($second);
        $this->assertSame(QimClassification::DUPLICATE_IDENTITY_CONFIG->value, $second->duplicate_classification);
    }

    public function test_no_op_when_feature_flag_is_disabled(): void
    {
        config(['quote_integrity.enabled' => false]);

        $record = $this->service->recordObservation([
            'provider' => 'bybit',
            'channel' => 'WS',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '1m',
            'candle_open_time' => CarbonImmutable::now(),
            'candle_close_time' => CarbonImmutable::now()->addMinute(),
            'open' => '65000.0',
            'high' => '65100.0',
            'low' => '64950.0',
            'close' => '65050.0',
            'volume' => '10.0',
            'raw_payload' => 'payload',
        ]);

        $this->assertNull($record);
    }
}
