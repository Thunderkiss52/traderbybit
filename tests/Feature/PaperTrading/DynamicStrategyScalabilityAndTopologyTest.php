<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\Exchanges\ExchangeRegistry;
use App\PaperTrading\Application\PaperTopologySynchronizer;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Contracts\StrategyInterface;
use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\SignalSide;
use App\Strategy\Domain\StrategyInput;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use App\StrategyBuilder\PrimaryStrategies;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class DynamicStrategyScalabilityAndTopologyTest extends TestCase
{
    use RefreshDatabase;

    private StrategyRegistry $strategyRegistry;

    private ExchangeRegistry $exchangeRegistry;

    private PaperTopologySynchronizer $synchronizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategyRegistry = $this->app->make(StrategyRegistry::class);
        $this->exchangeRegistry = $this->app->make(ExchangeRegistry::class);
        $this->synchronizer = $this->app->make(PaperTopologySynchronizer::class);
    }

    public function test_dynamic_strategy_registration_provisions_all_ten_exchanges(): void
    {
        // Initial baseline sync
        $res = $this->synchronizer->sync(true);
        $this->assertEquals('APPLIED', $res['mode']);

        $exchanges = $this->exchangeRegistry->registeredIds();
        $this->assertCount(10, $exchanges);

        // Register a new executable strategy dynamically
        $strategyId = 'dyn_alpha_momentum';
        $dynStrategy = new DynamicBenchmarkStrategy($strategyId, '1.2.0');
        $this->strategyRegistry->register($dynStrategy);

        $this->assertTrue($this->strategyRegistry->has($strategyId));
        $this->assertContains($strategyId, PrimaryStrategies::ids());

        // Verify accounts exist on all 10 exchanges
        $accounts = PaperAccountRecord::query()
            ->where('is_primary', true)
            ->where('primary_strategy_id', $strategyId)
            ->get();

        $this->assertCount(10, $accounts, 'New dynamic strategy must have exactly one account on all 10 registered exchanges');

        $bybitAccount = $accounts->firstWhere('exchange_id', 'bybit');
        $this->assertNotNull($bybitAccount);
        $this->assertEquals('BLOCKED', $bybitAccount->status, 'Venue approval does not authorize an unvalidated new strategy account');
        $this->assertEquals('0.000000000000', (string) BigDecimal::of($bybitAccount->starting_balance)->toScale(12), 'Discovery cannot allocate owner capital');

        foreach ($exchanges as $exId) {
            $acc = $accounts->firstWhere('exchange_id', $exId);
            $this->assertNotNull($acc, "Account for {$exId} must exist");
            $this->assertEquals($strategyId, $acc->primary_strategy_id);
            $this->assertEquals($strategyId, $acc->strategy_id);
            $this->assertEquals('0.000000000000', (string) BigDecimal::of($acc->starting_balance)->toScale(12));
            $this->assertSame('BLOCKED', $acc->status);
            $this->assertSame('READINESS_PENDING', $acc->execution_block_origin);
        }
    }

    public function test_repeated_synchronization_is_strictly_idempotent(): void
    {
        // 1. First sync
        $this->synchronizer->sync(true);
        $initialCount = PaperAccountRecord::query()->where('is_primary', true)->count();
        $this->assertGreaterThan(0, $initialCount);

        // Record initial state
        $initialRows = PaperAccountRecord::query()->where('is_primary', true)->orderBy('id')->get();

        // 2. Second sync
        $res2 = $this->synchronizer->sync(true);
        $secondCount = PaperAccountRecord::query()->where('is_primary', true)->count();
        $secondRows = PaperAccountRecord::query()->where('is_primary', true)->orderBy('id')->get();

        $this->assertEquals($initialCount, $secondCount, 'Account count must remain identical');
        $this->assertEquals(0, $res2['created_bybit'], 'Zero new Bybit accounts created on repeated sync');
        $this->assertEquals(0, $res2['created_other'], 'Zero new non-Bybit accounts created on repeated sync');

        for ($i = 0; $i < $initialCount; $i++) {
            $this->assertEquals($initialRows[$i]->id, $secondRows[$i]->id);
            $this->assertEquals((string) $initialRows[$i]->starting_balance, (string) $secondRows[$i]->starting_balance);
            $this->assertEquals((string) $initialRows[$i]->current_equity, (string) $secondRows[$i]->current_equity);
        }
    }

    public function test_historical_accounts_and_financial_records_preserved(): void
    {
        $this->synchronizer->sync(true);

        // Fetch Bybit baseline account
        $baseline = PaperAccountRecord::query()
            ->where('exchange_id', 'bybit')
            ->where('primary_strategy_id', 'baseline')
            ->firstOrFail();

        $canonicalId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'traderbybit:paper:default')->toString();
        $this->assertEquals($canonicalId, $baseline->id);
        $this->assertEquals('10000.000000000000', (string) $baseline->starting_balance);

        // Update balance and add a position
        $baseline->update(['current_equity' => '10250.00', 'realized_pnl' => '250.00']);

        // Run sync again
        $this->synchronizer->sync(true);

        $refreshed = PaperAccountRecord::query()->find($baseline->id);
        $this->assertEquals('10250.000000000000', (string) $refreshed->current_equity, 'Balance must NOT be reset by sync');
        $this->assertEquals('250.000000000000', (string) $refreshed->realized_pnl);
    }

    public function test_profitability_math_and_cross_exchange_reconciliation(): void
    {
        $this->synchronizer->sync(true);
        $bybitAccount = PaperAccountRecord::query()->where('exchange_id', 'bybit')->where('primary_strategy_id', 'baseline')->firstOrFail();
        $binanceAccount = PaperAccountRecord::query()->where('exchange_id', 'binance')->where('primary_strategy_id', 'baseline')->firstOrFail();

        $now = CarbonImmutable::now('UTC');

        $sigBybit = $this->createSignal('bybit', 'BTCUSDT', 'baseline');
        $sigBinance = $this->createSignal('binance', 'BTCUSDT', 'baseline');

        // Create Bybit Position: gross 200, fee 10, funding 2 => net 192
        $posBybit = VirtualPositionRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'paper_account_id' => $bybitAccount->id,
            'signal_id' => $sigBybit->id,
            'exchange_id' => 'bybit',
            'symbol' => 'BTCUSDT',
            'side' => 'BUY',
            'quality_class' => 'A',
            'status' => 'CLOSED_TAKE_PROFIT',
            'entry_price' => '65000.00',
            'stop_loss' => '64000.00',
            'take_profit_1' => '66000.00',
            'take_profit_2' => '67000.00',
            'risk_capital' => '100.00',
            'initial_quantity' => '0.1',
            'remaining_quantity' => '0.0',
            'qty_step' => '0.001',
            'gross_pnl' => '200.00',
            'net_pnl' => '192.00',
            'unrealized_pnl' => '0.00',
            'fees' => '10.00',
            'funding' => '2.00',
            'opened_at' => $now->subHour(),
            'closed_at' => $now,
            'maximum_holding_until' => $now->addHours(23),
            'execution_model' => 'MARKET_TAKER',
            'strategy_version' => '1.0.0',
            'risk_version' => '1.0.0',
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);

        // Create Binance Position: gross -100, fee 5, funding -1 => net -106
        $posBinance = VirtualPositionRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'paper_account_id' => $binanceAccount->id,
            'signal_id' => $sigBinance->id,
            'exchange_id' => 'binance',
            'symbol' => 'BTCUSDT',
            'side' => 'SELL',
            'quality_class' => 'B',
            'status' => 'CLOSED_STOP_LOSS',
            'entry_price' => '65000.00',
            'stop_loss' => '66000.00',
            'take_profit_1' => '64000.00',
            'take_profit_2' => '63000.00',
            'risk_capital' => '100.00',
            'initial_quantity' => '0.1',
            'remaining_quantity' => '0.0',
            'qty_step' => '0.001',
            'gross_pnl' => '-100.00',
            'net_pnl' => '-106.00',
            'unrealized_pnl' => '0.00',
            'fees' => '5.00',
            'funding' => '-1.00',
            'opened_at' => $now->subHour(),
            'closed_at' => $now,
            'maximum_holding_until' => $now->addHours(23),
            'execution_model' => 'MARKET_TAKER',
            'strategy_version' => '1.0.0',
            'risk_version' => '1.0.0',
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);

        // Query All Exchanges
        $resAll = $this->withHeaders(['X-Exchange' => 'all'])->getJson('/api/v1/research/profitability/summary');
        $resAll->assertOk();

        $dataAll = $resAll->json('data.accounting_scopes.full_lifecycle_economics');
        $this->assertTrue(BigDecimal::of($dataAll['gross_pnl'])->isEqualTo('100.00')); // 200 - 100
        $this->assertTrue(BigDecimal::of($dataAll['fees'])->isEqualTo('15.00')); // 10 + 5
        $this->assertTrue(BigDecimal::of($dataAll['signed_funding'])->isEqualTo('1.00')); // 2 - 1
        $this->assertTrue(BigDecimal::of($dataAll['net_pnl'])->isEqualTo('86.00')); // 192 - 106 = 86
        $this->assertTrue($dataAll['accounting_identity_holds']);

        // Query Bybit Only
        $resBybit = $this->withHeaders(['X-Exchange' => 'bybit'])->getJson('/api/v1/research/profitability/summary');
        $resBybit->assertOk();
        $dataBybit = $resBybit->json('data.accounting_scopes.full_lifecycle_economics');
        $this->assertTrue(BigDecimal::of($dataBybit['gross_pnl'])->isEqualTo('200.00'));
        $this->assertTrue(BigDecimal::of($dataBybit['fees'])->isEqualTo('10.00'));
        $this->assertTrue(BigDecimal::of($dataBybit['signed_funding'])->isEqualTo('2.00'));
        $this->assertTrue(BigDecimal::of($dataBybit['net_pnl'])->isEqualTo('192.00'));

        // Query Binance Only
        $resBinance = $this->withHeaders(['X-Exchange' => 'binance'])->getJson('/api/v1/research/profitability/summary');
        $resBinance->assertOk();
        $dataBinance = $resBinance->json('data.accounting_scopes.full_lifecycle_economics');
        $this->assertTrue(BigDecimal::of($dataBinance['gross_pnl'])->isEqualTo('-100.00'));
        $this->assertTrue(BigDecimal::of($dataBinance['fees'])->isEqualTo('5.00'));
        $this->assertTrue(BigDecimal::of($dataBinance['signed_funding'])->isEqualTo('-1.00'));
        $this->assertTrue(BigDecimal::of($dataBinance['net_pnl'])->isEqualTo('-106.00'));

        // Verify mathematical reconciliation
        $sumGross = (float) $dataBybit['gross_pnl'] + (float) $dataBinance['gross_pnl'];
        $this->assertEquals((float) $dataAll['gross_pnl'], $sumGross);

        $sumNet = (float) $dataBybit['net_pnl'] + (float) $dataBinance['net_pnl'];
        $this->assertEquals((float) $dataAll['net_pnl'], $sumNet);
    }

    public function test_scalability_at_63_100_500_and_1000_strategies(): void
    {
        $exchanges = $this->exchangeRegistry->registeredIds();
        $exchangeCount = count($exchanges);
        $this->assertEquals(10, $exchangeCount);

        $scales = [
            13 => 130,
            63 => 630,
            100 => 1000,
            500 => 5000,
            1000 => 10000,
        ];

        $currentCount = count(PrimaryStrategies::ids()); // 13

        foreach ($scales as $targetStrategyCount => $expectedAccountCount) {
            $needed = $targetStrategyCount - $currentCount;

            $memStart = memory_get_usage(true);
            $t0 = microtime(true);

            // Register synthetic strategies in batch
            $batch = [];
            for ($i = 0; $i < $needed; $i++) {
                $num = $currentCount + $i + 1;
                $batch[] = new DynamicBenchmarkStrategy("scale_strat_{$num}", '1.0.0');
            }
            $this->strategyRegistry->registerBatch($batch);
            $duration = (microtime(true) - $t0) * 1000;
            $memDelta = (memory_get_usage(true) - $memStart) / 1024 / 1024;

            $currentCount = count(PrimaryStrategies::ids());
            $this->assertEquals($targetStrategyCount, $currentCount);

            $accountCount = PaperAccountRecord::query()->where('is_primary', true)->count();
            $this->assertEquals($expectedAccountCount, $accountCount, "Topology must have exactly {$expectedAccountCount} accounts for {$targetStrategyCount} strategies x 10 exchanges");

            // Measure query latency
            $queryStart = microtime(true);
            $primaryAccounts = PaperAccountRecord::query()->where('is_primary', true)->where('exchange_id', 'bybit')->get();
            $queryMs = (microtime(true) - $queryStart) * 1000;

            $this->assertCount($targetStrategyCount, $primaryAccounts);

            // Log benchmark evidence for report
            fwrite(STDERR, sprintf(
                "\n[SCALABILITY BENCHMARK] Scale: %4d strategies | %5d accounts | Sync: %7.2f ms | Query: %5.2f ms | Mem: %5.2f MB\n",
                $targetStrategyCount,
                $accountCount,
                $duration,
                $queryMs,
                $memDelta
            ));
        }
    }

    private function createSignal(string $exchange, string $symbol, string $strategyId = 'baseline'): SignalRecord
    {
        return SignalRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'exchange' => $exchange,
            'category' => 'linear',
            'symbol' => $symbol,
            'timeframe' => '15m',
            'strategy_id' => $strategyId,
            'strategy_version' => '1.0.0',
            'side' => 'BUY',
            'quality_class' => 'A',
            'signal_quality' => 'A',
            'trade_risk' => 'LOW',
            'score' => 85,
            'entry_low' => '64900.00',
            'entry_high' => '65100.00',
            'stop_loss' => '64000.00',
            'take_profit_1' => '66000.00',
            'take_profit_2' => '67000.00',
            'risk_reward_1' => '2.0',
            'risk_reward_2' => '4.0',
            'model_risk_percent' => '0.20',
            'reasons' => ['test_fixture'],
            'risks' => [],
            'contract' => ['rules' => []],
            'indicator_snapshot' => ['15m' => ['atr_14' => '500.00', 'close' => '65000.00']],
            'decision_time' => CarbonImmutable::now('UTC'),
            'candle_close_time' => CarbonImmutable::now('UTC'),
            'candle_id' => null,
            'maximum_holding_minutes' => 120,
            'schema_version' => '1.0.0',
            'expires_at' => CarbonImmutable::now('UTC')->addMinutes(30),
        ]);
    }
}

/**
 * Lightweight synthetic strategy implementation for dynamic scalability benchmarks.
 */
final readonly class DynamicBenchmarkStrategy implements StrategyInterface
{
    public function __construct(
        private string $strategyId,
        private string $strategyVersion = '1.0.0',
    ) {}

    public function id(): string
    {
        return $this->strategyId;
    }

    public function version(): string
    {
        return $this->strategyVersion;
    }

    public function targetExchanges(): array
    {
        return ['bybit', 'binance', 'coinbase', 'upbit', 'okx', 'bitget', 'gate', 'kucoin', 'kraken', 'htx'];
    }

    public function description(): string
    {
        return "Dynamic Benchmark Strategy {$this->strategyId}";
    }

    public function parameters(): array
    {
        return [
            'period' => 20,
            'threshold' => 1.5,
            'synthetic_benchmark' => true,
        ];
    }

    public function evaluate(StrategyInput $input): SignalDecision
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return new SignalDecision(
            id: 'dyn-sig-'.Uuid::uuid4()->toString(),
            exchange: $input->exchange,
            category: $input->category,
            symbol: $input->symbol,
            timeframe: '15m',
            decisionTime: $now,
            candleCloseTime: $now,
            side: SignalSide::Skip,
            qualityClass: null,
            score: 0,
            pricePlan: null,
            modelRiskPercent: null,
            expiresAt: $now->modify('+30 minutes'),
            maximumHoldingMinutes: 60,
            reasons: ['synthetic_benchmark_skip'],
            risks: [],
            indicatorSnapshot: [],
            strategyVersion: $this->strategyVersion,
            schemaVersion: '1.0.0',
        );
    }
}
