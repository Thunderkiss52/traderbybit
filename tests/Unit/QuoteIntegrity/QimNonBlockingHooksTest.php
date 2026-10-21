<?php

declare(strict_types=1);

namespace Tests\Unit\QuoteIntegrity;

use App\MarketData\Application\CandleSynchronizer;
use App\MarketData\Application\ClosedCandleIngestor;
use App\MarketData\Application\GapDetector;
use App\MarketData\Contracts\BybitMarketDataClient;
use App\MarketData\Contracts\CandleStore;
use App\MarketData\Domain\Candle;
use App\MarketData\Domain\CandleQuery;
use App\MarketData\Domain\Interval;
use App\MarketData\Infrastructure\Bybit\BybitWebSocketMessageParser;
use App\QuoteIntegrity\Application\QimRestObserver;
use App\QuoteIntegrity\Application\QimWsObserver;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\TestCase;

final class QimNonBlockingHooksTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_closed_candle_ingestor_is_not_blocked_when_qim_observer_throws(): void
    {
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

        $failingWsObserver = Mockery::mock(QimWsObserver::class);
        $failingWsObserver->shouldReceive('observe')
            ->once()
            ->andThrow(new RuntimeException('Simulated database crash in QIM'));

        $store = Mockery::mock(CandleStore::class);
        $store->shouldReceive('upsert')
            ->once()
            ->andReturn(1);

        $ingestor = new ClosedCandleIngestor(
            parser: new BybitWebSocketMessageParser,
            store: $store,
            qimObserver: $failingWsObserver,
            log: new NullLogger,
        );

        // Crucial test: despite QIM exception, normal ingestion must succeed and return true!
        $result = $ingestor->ingest($message);
        $this->assertTrue($result);
    }

    public function test_closed_candle_ingestor_captures_duplicate_attempt_before_idempotency_lock_refusal(): void
    {
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

        // Pre-acquire the lock to simulate duplicate in-flight attempt
        $lock = Cache::lock('market-candle:linear:BTCUSDT:5:1700000000000', 30);
        $lock->get();

        $captured = false;
        $wsObserver = Mockery::mock(QimWsObserver::class);
        $wsObserver->shouldReceive('observe')
            ->once()
            ->andReturnUsing(function () use (&$captured): void {
                $captured = true;
            });

        $store = Mockery::mock(CandleStore::class);
        $store->shouldNotReceive('upsert');

        $ingestor = new ClosedCandleIngestor(
            parser: new BybitWebSocketMessageParser,
            store: $store,
            qimObserver: $wsObserver,
            log: new NullLogger,
        );

        $result = $ingestor->ingest($message);

        // Ingestion was rejected by lock, BUT QIM captured the attempt!
        $this->assertFalse($result);
        $this->assertTrue($captured, 'QIM must observe before idempotency lock drops the attempt');

        $lock->release();
    }

    public function test_candle_synchronizer_is_not_blocked_when_qim_observer_throws(): void
    {
        $candle = new Candle(
            exchange: 'bybit',
            category: 'linear',
            symbol: 'BTCUSDT',
            interval: Interval::OneMinute,
            openTimeMs: 1700000000000,
            open: '50000.0',
            high: '50100.0',
            low: '49900.0',
            close: '50050.0',
            volume: '10.0',
            turnover: '500000.0',
            isClosed: true,
        );

        $client = Mockery::mock(BybitMarketDataClient::class);
        $client->shouldReceive('candles')->andReturn([$candle]);

        $store = Mockery::mock(CandleStore::class);
        $store->shouldReceive('upsert')->andReturn(1);
        $store->shouldReceive('closedOpenTimes')->andReturn([1700000000000]);

        $gapDetector = new GapDetector;

        $failingRestObserver = Mockery::mock(QimRestObserver::class);
        $failingRestObserver->shouldReceive('observeCandles')
            ->once()
            ->andThrow(new RuntimeException('QIM disk write failed'));

        $synchronizer = new CandleSynchronizer(
            client: $client,
            store: $store,
            gapDetector: $gapDetector,
            backfillPasses: 1,
            lockSeconds: 10,
            qimObserver: $failingRestObserver,
            log: new NullLogger,
        );

        $report = $synchronizer->sync(new CandleQuery('linear', 'BTCUSDT', Interval::OneMinute, 1700000000000, 1700000060000));
        $this->assertSame(1, $report->fetched);
        $this->assertSame(1, $report->stored);
    }
}
