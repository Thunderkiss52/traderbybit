<?php

declare(strict_types=1);

namespace Tests\Feature\QuoteIntegrity;

use App\MarketData\Application\ClosedCandleIngestor;
use App\MarketData\Infrastructure\Bybit\BybitWebSocketMessageParser;
use App\MarketData\Infrastructure\Persistence\EloquentCandleStore;
use App\QuoteIntegrity\Application\QimFeatureFlag;
use App\QuoteIntegrity\Application\QimGapRecorder;
use App\QuoteIntegrity\Application\QimObservationService;
use App\QuoteIntegrity\Application\QimWsObserver;
use App\QuoteIntegrity\Infrastructure\Persistence\QimGapEventRecord;
use App\QuoteIntegrity\Infrastructure\Persistence\QimGapEventRepository;
use App\QuoteIntegrity\Infrastructure\Persistence\QimObservationRecord;
use App\QuoteIntegrity\Infrastructure\Persistence\QimObservationRepository;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Log\NullLogger;
use Tests\TestCase;

final class QimFeatureFlagOffTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_qim_endpoints_return_503_when_flag_is_off(): void
    {
        config(['quote_integrity.enabled' => false]);

        $endpoints = [
            ['GET', '/api/v1/quote-integrity/status'],
            ['GET', '/api/v1/quote-integrity/summary'],
            ['GET', '/api/v1/quote-integrity/comparisons'],
            ['GET', '/api/v1/quote-integrity/incidents'],
            ['GET', '/api/v1/quote-integrity/incidents/1'],
            ['GET', '/api/v1/quote-integrity/alerts'],
            ['POST', '/api/v1/quote-integrity/evidence'],
            ['GET', '/api/v1/quote-integrity/evidence/1'],
            ['POST', '/api/v1/quote-integrity/incidents/1/transitions'],
        ];

        foreach ($endpoints as [$method, $uri]) {
            $response = $this->json($method, $uri);
            $response->assertStatus(503);
            $response->assertJson([
                'error' => [
                    'code' => 'FEATURE_DISABLED',
                ],
            ]);
        }
    }

    public function test_no_qim_records_created_during_ingestion_when_flag_is_off(): void
    {
        config(['quote_integrity.enabled' => false]);

        $obsRepo = new QimObservationRepository;
        $gapRepo = new QimGapEventRepository;
        $flag = new QimFeatureFlag;
        $logger = new NullLogger;

        $obsService = new QimObservationService($obsRepo, $flag, $logger);
        $gapRecorder = new QimGapRecorder($gapRepo, $flag, $logger);
        $wsObserver = new QimWsObserver($obsService, $logger);

        // Gap detection attempt
        $detectionId = $gapRecorder->recordGapDetected(
            symbol: 'BTCUSDT',
            interval: '1m',
            gapFrom: CarbonImmutable::now(),
            gapTo: CarbonImmutable::now()->addHour(),
        );
        $this->assertNull($detectionId);
        $this->assertSame(0, QimGapEventRecord::count());

        // Closed candle ingestion attempt
        $store = new EloquentCandleStore;
        $ingestor = new ClosedCandleIngestor(
            parser: new BybitWebSocketMessageParser,
            store: $store,
            qimObserver: $wsObserver,
            log: $logger,
        );

        $message = json_encode([
            'topic' => 'kline.5.BTCUSDT',
            'data' => [[
                'start' => 1700000000000,
                'open' => '50000.0',
                'high' => '50100.0',
                'low' => '49900.0',
                'close' => '50050.0',
                'volume' => '100.0',
                'turnover' => '5000000.0',
                'confirm' => true,
            ]],
            'ts' => 1700000300000,
        ], JSON_THROW_ON_ERROR);

        $result = $ingestor->ingest($message);
        $this->assertTrue($result);

        // Candle stored in market_candles, BUT zero records in QIM!
        $this->assertSame(0, QimObservationRecord::count());
    }
}
