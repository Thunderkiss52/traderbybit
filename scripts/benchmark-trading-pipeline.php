<?php

declare(strict_types=1);

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
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use App\Trading\Application\TradingPipelineEventService;
use App\Trading\Infrastructure\Persistence\TradingPipelineEventRecord;
use App\Trading\Jobs\ProcessClosedCandleEventJob;
use App\Trading\Jobs\ProcessPaperPipelineJob;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Strict environment guard: isolated loopback PostgreSQL bybit_test required.
if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'pgsql'
    || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_DATABASE') !== 'bybit_test'
    || ! empty(getenv('DB_URL'))) {
    throw new RuntimeException('Explicit isolated loopback PostgreSQL bybit_test configuration required.');
}

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection();
if (! $app->environment('testing') || $db->getDriverName() !== 'pgsql'
    || $db->getConfig('host') !== '127.0.0.1' || $db->getDatabaseName() !== 'bybit_test') {
    throw new RuntimeException('Resolved benchmark database configuration is not isolated.');
}

$options = getopt('', ['runs:', 'warmup:', 'revision:']);
$runs = (int) ($options['runs'] ?? 20);
$warmup = (int) ($options['warmup'] ?? 3);
if ($runs < 5 || $runs > 100 || $warmup < 1 || $warmup > 20) {
    throw new InvalidArgumentException('Use 5..100 measured runs and 1..20 warmup runs.');
}

config([
    'trading.pipeline_mode' => 'EVENT_DRIVEN',
    'trading.freshness_seconds' => 86400,
    'exchanges.binance.paper_taker_rate' => '0.0005',
    'exchanges.binance.paper_slippage_rate' => '0.0002',
    'exchanges.binance.paper_execution_approved' => true,
    'exchanges.binance.research_cycle_enabled' => true,
]);

$percentile = static function (array $samples, float $fraction): float {
    sort($samples, SORT_NUMERIC);

    return round($samples[(int) ceil(count($samples) * $fraction) - 1], 3);
};

$report = [
    'benchmark' => 'VECTOR V2.6 Event-Driven Pipeline Processing Latency',
    'metric_scope' => 'SIGNAL_ELIGIBILITY_AND_PAPER_PROCESSING_ONLY; NO VIRTUAL FILL OR MARKET-WAIT LATENCY IS MEASURED',
    'stage_c_correction' => 'The Stage C metric previously named virtual entry latency measured processing only. Virtual fill causality is verified by TradingPipelineForwardCausalityTest.',
    'timestamp' => now('UTC')->toIso8601String(),
    'runs' => $runs,
    'warmup' => $warmup,
    'acceptance_target_seconds' => 15.0,
    'cases' => [],
];

// Helper functions for benchmark setup
function seedContext(string $exchange, string $symbol, DateTimeImmutable $at): void
{
    MarketContextRecord::query()->updateOrCreate(
        ['exchange' => $exchange, 'category' => 'linear', 'symbol' => $symbol, 'observed_at' => $at],
        [
            'funding_rate' => '0.0001',
            'open_interest' => '50000',
            'open_interest_change' => '0.01',
            'turnover_24h' => '100000000',
            'tick_size' => '0.1',
            'bid' => '100.0',
            'ask' => '100.1',
            'qty_step' => '0.001',
            'min_order_qty' => '0.001',
            'max_order_qty' => '100.0',
            'min_notional_value' => '5.0',
            'created_at' => CarbonImmutable::now('UTC'),
            'updated_at' => CarbonImmutable::now('UTC'),
        ]
    );
}

function seedHistory(string $exchange, string $symbol, DateTimeImmutable $decisionTime): void
{
    $intervals = ['5' => 300, '15' => 900, '30' => 1800, '60' => 3600];
    foreach ($intervals as $interval => $seconds) {
        $existing = CandleRecord::query()
            ->where('exchange', $exchange)->where('category', 'linear')->where('symbol', $symbol)
            ->where('interval', $interval)->count();
        if ($existing < 220) {
            for ($i = 220; $i >= 2; $i--) {
                $openTime = $decisionTime->modify('-'.($i * $seconds).' seconds');
                CandleRecord::query()->updateOrCreate(
                    ['exchange' => $exchange, 'category' => 'linear', 'symbol' => $symbol, 'interval' => $interval, 'open_time' => $openTime],
                    [
                        'open' => '100.0', 'high' => '102.0', 'low' => '99.0', 'close' => '101.0',
                        'volume' => '500.0', 'turnover' => '50000.0', 'is_closed' => true,
                        'created_at' => CarbonImmutable::now('UTC'), 'updated_at' => CarbonImmutable::now('UTC'),
                    ]
                );
            }
        }
    }
}

// Ensure accounts exist
$accountService = $app->make(PaperAccountService::class);
$strategy = $app->make(StrategyRegistry::class)->get('baseline');
$bybitAccount = $accountService->strategyForward('baseline', $strategy->version(), $strategy->parameters());

$binanceInitializer = $app->make(BinanceDeploymentInitializer::class);
$binanceInitializer->initialize('10000', 'OWNER-VECTOR-20261023');

$pipelineService = $app->make(TradingPipelineEventService::class);
$paperService = $app->make(PaperProcessingService::class);

foreach (['bybit', 'binance'] as $exchange) {
    $eligibilityProcessingSamples = [];
    $paperProcessingSamples = [];

    $symbol = 'BTCUSDT';

    for ($run = -$warmup; $run < $runs; $run++) {
        $decisionTime = new DateTimeImmutable('2026-10-26T10:00:00Z');
        CarbonImmutable::setTestNow($decisionTime->modify('+30 seconds'));
        $openTime = $decisionTime->modify('-15 minutes');
        $openTimeMs = ((int) $openTime->format('U')) * 1000;
        $closeTimeMs = $openTimeMs + 900000 - 1;

        seedContext($exchange, $symbol, $decisionTime);
        seedHistory($exchange, $symbol, $decisionTime);

        // Clean any existing signals/events and the target candle for this iteration
        CandleRecord::query()->where('exchange', $exchange)->where('symbol', $symbol)->where('interval', '15')->where('open_time', $openTime)->delete();
        TradingPipelineEventRecord::query()->where('exchange', $exchange)->where('symbol', $symbol)->where('candle_close_at', $decisionTime)->delete();
        StrategyObservationRecord::query()->where('exchange', $exchange)->where('symbol', $symbol)->where('decision_time', $decisionTime)->delete();
        SignalRecord::query()->where('exchange', $exchange)->where('symbol', $symbol)->where('decision_time', $decisionTime)->delete();

        // Start measurement: candle closure observation
        $start = hrtime(true);

        if ($exchange === 'bybit') {
            $wsMessage = json_encode([
                'topic' => 'kline.15.BTCUSDT',
                'data' => [
                    [
                        'start' => $openTimeMs, 'open' => '100.0', 'high' => '105.0', 'low' => '99.0',
                        'close' => '104.0', 'volume' => '1000.0', 'turnover' => '100000.0', 'confirm' => true,
                    ],
                ],
            ], JSON_THROW_ON_ERROR);
            $ingestor = new ClosedCandleIngestor(new BybitWebSocketMessageParser, new EloquentCandleStore);
            $ingestor->ingest($wsMessage);
        } else {
            $wsMessage = json_encode([
                'e' => 'kline',
                'E' => $closeTimeMs + 1,
                's' => $symbol,
                'k' => [
                    't' => $openTimeMs,
                    'T' => $closeTimeMs,
                    's' => $symbol,
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
            $ingestor = new BinanceClosedCandleIngestor(new BinanceWebSocketMessageParser, new EloquentCandleStore);
            $ingestor->ingest($wsMessage);
        }

        $candleEvent = TradingPipelineEventRecord::query()
            ->where('exchange', $exchange)->where('symbol', $symbol)->where('timeframe', '15')
            ->where('stage', TradingPipelineEventRecord::STAGE_CANDLE_CLOSED)
            ->where('candle_close_at', $decisionTime)
            ->first();

        if ($candleEvent === null) {
            throw new RuntimeException("CANDLE_CLOSED event not created for {$exchange}:{$symbol}");
        }

        // Execute signal generation stage
        $candleJob = new ProcessClosedCandleEventJob($candleEvent->id, $exchange, $symbol, '15', $decisionTime->format(DATE_ATOM));
        $candleJob->handle($pipelineService);

        $eligibilityProcessingMs = (hrtime(true) - $start) / 1e6;

        // Verify signals generated
        $signalsCount = StrategyObservationRecord::query()->where('exchange', $exchange)->where('symbol', $symbol)->where('decision_time', $decisionTime)->count();
        if ($signalsCount < 13) {
            throw new RuntimeException("Expected at least 13 strategy evaluations, got {$signalsCount}");
        }

        // Execute paper trading processing stage
        $paperJob = new ProcessPaperPipelineJob($exchange, $symbol, $decisionTime->format(DATE_ATOM));
        $paperJob->handle($pipelineService, $paperService);

        $paperProcessingMs = (hrtime(true) - $start) / 1e6;

        if ($run >= 0) {
            $eligibilityProcessingSamples[] = round($eligibilityProcessingMs, 3);
            $paperProcessingSamples[] = round($paperProcessingMs, 3);
        }
    }

    $caseReport = [
        'exchange' => $exchange,
        'symbol' => $symbol,
        'samples_count' => count($eligibilityProcessingSamples),
        'eligibility_processing_latency' => [
            'p50_ms' => $percentile($eligibilityProcessingSamples, 0.50),
            'p95_ms' => $percentile($eligibilityProcessingSamples, 0.95),
            'p99_ms' => $percentile($eligibilityProcessingSamples, 0.99),
            'min_ms' => min($eligibilityProcessingSamples),
            'max_ms' => max($eligibilityProcessingSamples),
            'avg_ms' => round(array_sum($eligibilityProcessingSamples) / count($eligibilityProcessingSamples), 3),
        ],
        'paper_processing_latency' => [
            'p50_ms' => $percentile($paperProcessingSamples, 0.50),
            'p95_ms' => $percentile($paperProcessingSamples, 0.95),
            'p99_ms' => $percentile($paperProcessingSamples, 0.99),
            'min_ms' => min($paperProcessingSamples),
            'max_ms' => max($paperProcessingSamples),
            'avg_ms' => round(array_sum($paperProcessingSamples) / count($paperProcessingSamples), 3),
        ],
        'virtual_fill_measured' => false,
        'target_passed' => $percentile($paperProcessingSamples, 0.95) < 15000.0,
    ];

    $report['cases'][$exchange] = $caseReport;
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
