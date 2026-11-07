<?php

declare(strict_types=1);

namespace Tests\Feature\Trading;

use App\Exchanges\Binance\BinanceClosedCandleIngestor;
use App\Exchanges\Binance\BinanceDeploymentInitializer;
use App\Exchanges\Binance\BinanceWebSocketMessageParser;
use App\MarketData\Application\ClosedCandleIngestor;
use App\MarketData\Infrastructure\Bybit\BybitWebSocketMessageParser;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\MarketData\Infrastructure\Persistence\EloquentCandleStore;
use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Application\PaperProcessingService;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use App\Trading\Application\TradingPipelineEventService;
use App\Trading\Infrastructure\Persistence\TradingPipelineEventRecord;
use App\Trading\Jobs\ProcessClosedCandleEventJob;
use App\Trading\Jobs\ProcessPaperPipelineJob;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class EventDrivenTradingPipelineChainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        config([
            'trading.pipeline_mode' => 'EVENT_DRIVEN',
            'exchanges.binance.paper_taker_rate' => '0.0005',
            'exchanges.binance.paper_slippage_rate' => '0.0002',
            'exchanges.binance.paper_execution_approved' => true,
            'exchanges.binance.research_cycle_enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow(null);
        parent::tearDown();
    }

    public function test_bybit_complete_candle_to_signal_to_paper_event_chain(): void
    {
        Queue::fake();

        $accountService = app(PaperAccountService::class);
        $strategy = app(StrategyRegistry::class)->get('baseline');
        $account = $accountService->strategyForward('baseline', $strategy->version(), $strategy->parameters());

        $nowTs = time();
        $alignedTs = $nowTs - ($nowTs % 900);
        $decisionTime = (new DateTimeImmutable("@{$alignedTs}"))->setTimezone(new \DateTimeZone('UTC'));
        CarbonImmutable::setTestNow($decisionTime->modify('+30 seconds'));
        $openTime = $decisionTime->modify('-15 minutes');
        $openTimeMs = ((int) $openTime->format('U')) * 1000;

        // Seed recent context
        $this->seedMarketContext('bybit', 'BTCUSDT', $decisionTime);

        // Seed 220 historical candles for 15m, 5m, 30m, 60m timeframes
        $this->seedContiguousHistory('bybit', 'BTCUSDT', $decisionTime);

        $wsMessage = json_encode([
            'topic' => 'kline.15.BTCUSDT',
            'data' => [
                [
                    'start' => $openTimeMs,
                    'open' => '100.0',
                    'high' => '105.0',
                    'low' => '99.0',
                    'close' => '104.0',
                    'volume' => '1000.0',
                    'turnover' => '100000.0',
                    'confirm' => true,
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $ingestor = new ClosedCandleIngestor(
            new BybitWebSocketMessageParser,
            new EloquentCandleStore,
        );

        $ingested = $ingestor->ingest($wsMessage);
        self::assertTrue($ingested);

        // 1. Verify CANDLE_CLOSED event created and dispatched on trading-bybit
        Queue::assertPushedOn('trading-bybit', ProcessClosedCandleEventJob::class);

        $candleEvent = TradingPipelineEventRecord::query()
            ->where('exchange', 'bybit')
            ->where('symbol', 'BTCUSDT')
            ->where('timeframe', '15')
            ->where('stage', TradingPipelineEventRecord::STAGE_CANDLE_CLOSED)
            ->first();

        self::assertNotNull($candleEvent);
        self::assertSame(TradingPipelineEventRecord::STATUS_DISPATCHED, $candleEvent->status);

        // 2. Execute ProcessClosedCandleEventJob
        $pipelineService = app(TradingPipelineEventService::class);
        $candleJob = new ProcessClosedCandleEventJob(
            $candleEvent->id,
            'bybit',
            'BTCUSDT',
            '15',
            $decisionTime->format(DATE_ATOM)
        );
        $candleJob->handle($pipelineService);

        // 3. A dispatched paper job has not yet acknowledged the candle.
        self::assertSame(TradingPipelineEventRecord::STATUS_DISPATCHED, $candleEvent->fresh()->status);

        // 4. Verify signals and observations were created for all 13 registered strategies
        $bybitObservations = StrategyObservationRecord::query()
            ->where('exchange', 'bybit')
            ->where('symbol', 'BTCUSDT');
        $observedStrategies = (clone $bybitObservations)->distinct()->orderBy('strategy_id')->pluck('strategy_id')->all();
        $expectedStrategies = collect(app(StrategyRegistry::class)->all())->map(fn ($strategy): string => $strategy->id())->sort()->values()->all();
        self::assertCount(13, $observedStrategies);
        self::assertSame($expectedStrategies, $observedStrategies);
        self::assertSame(13, (clone $bybitObservations)->count());
        self::assertSame(0, StrategyObservationRecord::query()->where('exchange', 'binance')->count());

        // 5. Verify SIGNALS_EVALUATED event created and dispatched ProcessPaperPipelineJob
        Queue::assertPushedOn('trading-bybit', ProcessPaperPipelineJob::class);

        $signalEvent = TradingPipelineEventRecord::query()
            ->where('exchange', 'bybit')
            ->where('symbol', 'BTCUSDT')
            ->where('stage', TradingPipelineEventRecord::STAGE_SIGNALS_EVALUATED)
            ->first();
        self::assertNotNull($signalEvent);

        // 6. Execute ProcessPaperPipelineJob
        $paperJob = new ProcessPaperPipelineJob('bybit', 'BTCUSDT', $decisionTime->format(DATE_ATOM));
        $paperJob->handle($pipelineService, app(PaperProcessingService::class));

        // 7. Verify PAPER_PROCESSED event created with status COMPLETED
        $paperEvent = TradingPipelineEventRecord::query()
            ->where('exchange', 'bybit')
            ->where('symbol', 'BTCUSDT')
            ->where('stage', TradingPipelineEventRecord::STAGE_PAPER_PROCESSED)
            ->first();
        self::assertNotNull($paperEvent);
        self::assertSame(TradingPipelineEventRecord::STATUS_COMPLETED, $paperEvent->status);
        self::assertSame(TradingPipelineEventRecord::STATUS_COMPLETED, $candleEvent->fresh()->status);
    }

    public function test_binance_complete_candle_to_signal_to_paper_event_chain(): void
    {
        Queue::fake();

        $initializer = app(BinanceDeploymentInitializer::class);
        $initializer->initialize('10000', 'OWNER-VECTOR-20261023');

        $nowTs = time();
        $alignedTs = $nowTs - ($nowTs % 900);
        $decisionTime = (new DateTimeImmutable("@{$alignedTs}"))->setTimezone(new \DateTimeZone('UTC'));
        CarbonImmutable::setTestNow($decisionTime->modify('+30 seconds'));
        $openTime = $decisionTime->modify('-15 minutes');
        $openTimeMs = ((int) $openTime->format('U')) * 1000;
        $closeTimeMs = $openTimeMs + 900000 - 1;

        $this->seedMarketContext('binance', 'BTCUSDT', $decisionTime);
        $this->seedContiguousHistory('binance', 'BTCUSDT', $decisionTime);

        $wsMessage = json_encode([
            'e' => 'kline',
            'E' => $closeTimeMs + 1,
            's' => 'BTCUSDT',
            'k' => [
                't' => $openTimeMs,
                'T' => $closeTimeMs,
                's' => 'BTCUSDT',
                'i' => '15m',
                'f' => 100,
                'L' => 200,
                'o' => '100.0',
                'c' => '104.0',
                'h' => '105.0',
                'l' => '99.0',
                'v' => '1000.0',
                'n' => 100,
                'x' => true,
                'q' => '100000.0',
                'V' => '500.0',
                'Q' => '50000.0',
                'B' => '0',
            ],
        ], JSON_THROW_ON_ERROR);

        $ingestor = new BinanceClosedCandleIngestor(
            new BinanceWebSocketMessageParser,
            new EloquentCandleStore,
        );
        $ingestResult = $ingestor->ingest($wsMessage);
        self::assertTrue($ingestResult);

        // 1. Verify CANDLE_CLOSED event created and dispatched on trading-binance
        Queue::assertPushedOn('trading-binance', ProcessClosedCandleEventJob::class);
        Queue::assertNotPushed(ProcessClosedCandleEventJob::class, fn ($job) => $job->queue === 'trading-bybit');

        $candleEvent = TradingPipelineEventRecord::query()
            ->where('exchange', 'binance')
            ->where('symbol', 'BTCUSDT')
            ->where('timeframe', '15')
            ->where('stage', TradingPipelineEventRecord::STAGE_CANDLE_CLOSED)
            ->first();

        self::assertNotNull($candleEvent);
        self::assertSame(TradingPipelineEventRecord::STATUS_DISPATCHED, $candleEvent->status);

        // 2. Execute ProcessClosedCandleEventJob
        $pipelineService = app(TradingPipelineEventService::class);
        $candleJob = new ProcessClosedCandleEventJob(
            $candleEvent->id,
            'binance',
            'BTCUSDT',
            '15',
            $decisionTime->format(DATE_ATOM)
        );
        $candleJob->handle($pipelineService);

        // 3. A dispatched paper job has not yet acknowledged the candle.
        self::assertSame(TradingPipelineEventRecord::STATUS_DISPATCHED, $candleEvent->fresh()->status);

        // 4. Verify signals and observations were created for Binance
        $binanceObservations = StrategyObservationRecord::query()
            ->where('exchange', 'binance')
            ->where('symbol', 'BTCUSDT');
        $observedStrategies = (clone $binanceObservations)->distinct()->orderBy('strategy_id')->pluck('strategy_id')->all();
        $expectedStrategies = collect(app(StrategyRegistry::class)->all())->map(fn ($strategy): string => $strategy->id())->sort()->values()->all();
        self::assertCount(13, $observedStrategies);
        self::assertSame($expectedStrategies, $observedStrategies);
        self::assertSame(13, (clone $binanceObservations)->count());
        self::assertSame(0, StrategyObservationRecord::query()->where('exchange', 'bybit')->count());

        // 5. Verify SIGNALS_EVALUATED event created and dispatched ProcessPaperPipelineJob on trading-binance
        Queue::assertPushedOn('trading-binance', ProcessPaperPipelineJob::class);
        Queue::assertNotPushed(ProcessPaperPipelineJob::class, fn ($job) => $job->queue === 'trading-bybit');

        // 6. Execute ProcessPaperPipelineJob
        $paperJob = new ProcessPaperPipelineJob('binance', 'BTCUSDT', $decisionTime->format(DATE_ATOM));
        $paperJob->handle($pipelineService, app(PaperProcessingService::class));

        // 7. Verify PAPER_PROCESSED event created with status COMPLETED
        $paperEvent = TradingPipelineEventRecord::query()
            ->where('exchange', 'binance')
            ->where('symbol', 'BTCUSDT')
            ->where('stage', TradingPipelineEventRecord::STAGE_PAPER_PROCESSED)
            ->first();
        self::assertNotNull($paperEvent);
        self::assertSame(TradingPipelineEventRecord::STATUS_COMPLETED, $paperEvent->status);
        self::assertSame(TradingPipelineEventRecord::STATUS_COMPLETED, $candleEvent->fresh()->status);
    }

    private function seedMarketContext(string $exchange, string $symbol, DateTimeImmutable $at): void
    {
        MarketContextRecord::query()->create([
            'exchange' => $exchange,
            'category' => 'linear',
            'symbol' => $symbol,
            'observed_at' => $at,
            'funding_rate' => '0.0001',
            'open_interest' => '50000',
            'open_interest_change' => '0.01',
            'turnover_24h' => '100000000',
            'tick_size' => '0.1',
            'bid' => '100.0',
            'ask' => '100.1',
            'created_at' => CarbonImmutable::now('UTC'),
            'updated_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    private function seedContiguousHistory(string $exchange, string $symbol, DateTimeImmutable $decisionTime): void
    {
        $intervals = [
            '5' => 300,
            '15' => 900,
            '30' => 1800,
            '60' => 3600,
        ];

        foreach ($intervals as $interval => $seconds) {
            $min = $interval === '15' ? 2 : 1;
            for ($i = 220; $i >= $min; $i--) {
                $openTime = $decisionTime->modify('-'.($i * $seconds).' seconds');
                CandleRecord::query()->updateOrCreate([
                    'exchange' => $exchange,
                    'category' => 'linear',
                    'symbol' => $symbol,
                    'interval' => $interval,
                    'open_time' => $openTime,
                ], [
                    'open' => '100.0',
                    'high' => '102.0',
                    'low' => '99.0',
                    'close' => '101.0',
                    'volume' => '500.0',
                    'turnover' => '50000.0',
                    'is_closed' => true,
                    'created_at' => CarbonImmutable::now('UTC'),
                    'updated_at' => CarbonImmutable::now('UTC'),
                ]);
            }
        }
    }
}
