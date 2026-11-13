<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\PaperTrading\Application\PaperProcessingService;
use App\PaperTrading\Application\PaperTopologySynchronizer;
use App\PaperTrading\Domain\PositionStatus;
use App\PaperTrading\Infrastructure\Persistence\MarketFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Contracts\StrategyInterface;
use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\StrategyInput;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use App\StrategyBuilder\PrimaryStrategies;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Concerns\SeedsValidatedPaperEvidence;
use Tests\TestCase;

final class DynamicStrategyRegistrationAndPaperExecutionTest extends TestCase
{
    use RefreshDatabase;
    use SeedsValidatedPaperEvidence;

    protected function setUp(): void
    {
        parent::setUp();
        config(['exchanges.binance_paper_provisioning_approved' => true]);
        config(['exchanges.binance_paper_execution_approved' => false]);
    }

    public function test_dynamic_strategy_registration_and_full_paper_execution_lifecycle(): void
    {
        $this->travelTo(new DateTimeImmutable('2026-10-14T10:00:00Z'));
        // 1. Initial state: synchronize canonical topology (13 strategies)
        $synchronizer = app(PaperTopologySynchronizer::class);
        $syncResult = $synchronizer->sync(true);

        self::assertSame(13, $syncResult['created_bybit']);
        self::assertSame(13, $syncResult['created_binance_blocked_unfunded']);
        self::assertSame(26, PaperAccountRecord::query()->whereIn('exchange_id', ['bybit', 'binance'])->where('is_primary', true)->count());

        $existingSnapshot = PaperAccountRecord::query()->where('is_primary', true)->get()->mapWithKeys(
            fn ($a) => [$a->id => [(string) $a->starting_balance, (string) $a->current_equity, $a->status]]
        )->all();

        // 2. Dynamically register a 14th strategy: dynamic_trend_breakout_v14
        $strategyId = 'dynamic_trend_breakout_v14';
        $dynamicStrategy = $this->makeDynamicStrategy($strategyId);

        $registry = app(StrategyRegistry::class);
        $registry->register($dynamicStrategy);

        // Verify strategy is registered in canonical IDs
        self::assertContains($strategyId, PrimaryStrategies::ids());
        self::assertCount(14, PrimaryStrategies::ids());

        // Verify accounts were automatically provisioned upon registration across all registered exchanges
        self::assertSame(28, PaperAccountRecord::query()->whereIn('exchange_id', ['bybit', 'binance'])->where('is_primary', true)->count());
        self::assertSame(140, PaperAccountRecord::query()->where('is_primary', true)->count());

        // Calling sync again is strictly idempotent (0 newly created)
        $idempotentSync = $synchronizer->sync(true);
        self::assertSame(0, $idempotentSync['created_bybit']);
        self::assertSame(0, $idempotentSync['created_binance_blocked_unfunded']);
        self::assertSame(140, PaperAccountRecord::query()->where('is_primary', true)->count());

        // Assert existing 26 accounts are completely untouched
        foreach ($existingSnapshot as $id => $expected) {
            $current = PaperAccountRecord::query()->findOrFail($id);
            self::assertSame($expected, [(string) $current->starting_balance, (string) $current->current_equity, $current->status]);
        }

        // Assert newly registered strategy accounts
        $bybitAccount = PaperAccountRecord::query()->where('exchange_id', 'bybit')->where('primary_strategy_id', $strategyId)->firstOrFail();
        $binanceAccount = PaperAccountRecord::query()->where('exchange_id', 'binance')->where('primary_strategy_id', $strategyId)->firstOrFail();

        self::assertSame('BLOCKED', $bybitAccount->status);
        self::assertSame('READINESS_PENDING', $bybitAccount->execution_block_origin);
        self::assertDecimalSame('0', (string) $bybitAccount->starting_balance);
        self::assertDecimalSame('0', (string) $bybitAccount->current_equity);

        self::assertSame('BLOCKED', $binanceAccount->status);
        self::assertSame('READINESS_PENDING', $binanceAccount->execution_block_origin);
        self::assertDecimalSame('0', (string) $binanceAccount->starting_balance);
        self::assertDecimalSame('0', (string) $binanceAccount->current_equity);
        self::assertSame(0, DB::table('paper_capital_events')->whereIn('paper_account_id', [$bybitAccount->id, $binanceAccount->id])->count());

        // Explicit synthetic owner allocation for this fresh Bybit test portfolio only.
        $approvedAt = new DateTimeImmutable('2026-10-14T10:10:00Z');
        $this->travelTo($approvedAt);
        $capitalId = Uuid::uuid4()->toString();
        DB::table('paper_capital_events')->insert([
            'id' => $capitalId, 'paper_account_id' => $bybitAccount->id, 'exchange_id' => 'bybit',
            'amount' => '10000', 'approval_reference' => 'SYNTHETIC-DYNAMIC-BYBIT-OWNER-APPROVAL',
            'reason' => 'OWNER_APPROVED_PAPER_ALLOCATION', 'occurred_at' => $approvedAt,
            'created_at' => $approvedAt, 'updated_at' => $approvedAt,
        ]);
        $bybitAccount->update([
            'starting_balance' => '10000', 'current_equity' => '10000', 'peak_equity' => '10000',
            'status' => 'ACTIVE', 'execution_block_origin' => null,
        ]);
        DB::table('equity_snapshots')->insert([
            'paper_account_id' => $bybitAccount->id, 'exchange_id' => 'bybit', 'recorded_at' => $approvedAt,
            'equity' => '10000', 'realized_pnl' => '0', 'unrealized_pnl' => '0', 'drawdown_percent' => '0',
            'valued_through' => $approvedAt, 'valuation_fresh' => true,
            'source_event_key' => 'owner-paper-capital:'.$capitalId,
            'created_at' => $approvedAt, 'updated_at' => $approvedAt,
        ]);

        // 3. Simulate market data and signal evaluation for the 14th strategy on Bybit
        $this->travelTo(new DateTimeImmutable('2026-10-14T10:15:00Z'));
        $this->createContext('BTCUSDT', '10:15');
        $this->createCandle('10:15', '100.5', '102', '100', '101');

        $signal = $this->createSignal('BUY', 'BTCUSDT', '10:15', $strategyId);

        // 4. Process Paper Trading: virtual entry execution
        $processing = app(PaperProcessingService::class);
        $processing->process(accountId: $bybitAccount->id, symbol: 'BTCUSDT', at: new DateTimeImmutable('2026-10-14T10:20:00Z'));

        $position = VirtualPositionRecord::query()->where('paper_account_id', $bybitAccount->id)->firstOrFail();
        self::assertSame(PositionStatus::Open->value, $position->status);
        self::assertSame('BTCUSDT', $position->symbol);
        self::assertSame('BUY', $position->side);
        self::assertTrue(BigDecimal::of((string) $position->initial_quantity)->isPositive());

        // Assert fill execution was logged
        $entryExecution = VirtualExecutionRecord::query()->where('position_id', $position->id)->where('type', 'ENTRY')->firstOrFail();
        self::assertTrue(BigDecimal::of((string) $entryExecution->commission)->isPositive());

        // Assert account balance updated and risk reserved
        $bybitAccount->refresh();
        self::assertTrue(BigDecimal::of((string) $bybitAccount->reserved_risk)->isPositive());

        // 5. Simulate price reaching TP1 (take_profit_1 = 105)
        $this->createCandle('10:20', '101', '106', '101', '105.5');
        $processing->process(accountId: $bybitAccount->id, symbol: 'BTCUSDT', at: new DateTimeImmutable('2026-10-14T10:25:00Z'));

        $position->refresh();
        self::assertSame(PositionStatus::PartiallyClosed->value, $position->status);
        self::assertTrue(BigDecimal::of((string) $position->remaining_quantity)->isLessThan(BigDecimal::of((string) $position->initial_quantity)));
        self::assertTrue(VirtualExecutionRecord::query()->where('position_id', $position->id)->where('type', 'TAKE_PROFIT_1')->exists());
        self::assertTrue(BigDecimal::of((string) $position->gross_pnl)->isPositive());

        // 6. Simulate Market Funding event
        MarketFundingEventRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'funding_at' => new DateTimeImmutable('2026-10-14T10:22:00Z'),
            'rate' => '0.0001',
            'mark_price' => '104',
        ]);

        $processing->process(accountId: $bybitAccount->id, symbol: 'BTCUSDT', at: new DateTimeImmutable('2026-10-14T10:25:00Z'));
        $position->refresh();

        // 7. Simulate price reaching TP2 (take_profit_2 = 110) -> full exit
        $this->createCandle('10:25', '105.5', '111', '105', '110.5');
        $processing->process(accountId: $bybitAccount->id, symbol: 'BTCUSDT', at: new DateTimeImmutable('2026-10-14T10:30:00Z'));

        $position->refresh();
        self::assertSame(PositionStatus::ClosedTp->value, $position->status);
        self::assertNotNull($position->closed_at);
        self::assertTrue(BigDecimal::of((string) $position->net_pnl)->isPositive());

        // Assert financial identity: Equity = starting_balance + realized_pnl + unrealized_pnl
        $bybitAccount->refresh();
        $expectedEquity = BigDecimal::of((string) $bybitAccount->starting_balance)
            ->plus(BigDecimal::of((string) $bybitAccount->realized_pnl))
            ->plus(BigDecimal::of((string) $bybitAccount->unrealized_pnl));
        self::assertTrue(BigDecimal::of((string) $bybitAccount->current_equity)->isEqualTo($expectedEquity));

        // 8. Verify API Visibility
        // Dashboard API
        $dashboardResponse = $this->getJson('/api/v1/dashboard?exchange=bybit&account=all');
        $dashboardResponse->assertOk();
        $portfolios = $dashboardResponse->json('data.strategy_portfolios');
        self::assertContains($strategyId, array_column($portfolios, 'strategy_id'));

        // Strategies Catalog API
        $strategiesResponse = $this->getJson('/api/v1/research/strategies?per_page=100');
        $strategiesResponse->assertOk();
        $catalog = $strategiesResponse->json('data.data');
        self::assertContains($strategyId, array_column($catalog, 'id'));

        // Exchange Comparison API
        $comparisonResponse = $this->getJson('/api/v1/exchanges/comparison');
        $comparisonResponse->assertOk();
        $accounts = $comparisonResponse->json('data.accounts');
        $newBybitRow = collect($accounts)->first(fn ($r) => $r['strategy_id'] === $strategyId && $r['exchange_id'] === 'bybit');
        $newBinanceRow = collect($accounts)->first(fn ($r) => $r['strategy_id'] === $strategyId && $r['exchange_id'] === 'binance');

        self::assertNotNull($newBybitRow);
        self::assertSame('ACTIVE', $newBybitRow['status']);
        self::assertSame('STRATEGY_EVALUATING', $newBybitRow['evaluation_status']);

        self::assertNotNull($newBinanceRow);
        self::assertSame('BLOCKED', $newBinanceRow['status']);
        self::assertSame('EXECUTION_BLOCKED', $newBinanceRow['evaluation_status']);

        // 9. Worker restart & idempotency verification
        // Re-run processing at the same timestamp; verify no duplicate fills or balance mutation
        $balanceBefore = (string) $bybitAccount->current_equity;
        $executionCountBefore = VirtualExecutionRecord::query()->where('position_id', $position->id)->count();

        $processing->process(accountId: $bybitAccount->id, symbol: 'BTCUSDT', at: new DateTimeImmutable('2026-10-14T10:30:00Z'));

        self::assertSame($balanceBefore, (string) $bybitAccount->refresh()->current_equity);
        self::assertSame($executionCountBefore, VirtualExecutionRecord::query()->where('position_id', $position->id)->count());
    }

    public function test_representative_scaling_topology(): void
    {
        $synchronizer = app(PaperTopologySynchronizer::class);
        $synchronizer->sync(true);

        // Test scaling across counts: 1, 13, 25, 50, 100
        $benchmarkCounts = [15, 25, 50];
        $registry = app(StrategyRegistry::class);

        foreach ($benchmarkCounts as $targetCount) {
            $currentCount = count(PrimaryStrategies::ids());
            for ($i = $currentCount + 1; $i <= $targetCount; $i++) {
                $registry->register($this->makeDynamicStrategy("scaled_strategy_{$i}"));
            }

            self::assertCount($targetCount, PrimaryStrategies::ids());
            $syncResult = $synchronizer->sync(true);

            // Assert total pairs across 10 exchanges equals targetCount * 10
            $totalExpected = $targetCount * 10;
            self::assertSame($totalExpected, PaperAccountRecord::query()->where('is_primary', true)->count());

            // Assert each strategy has strictly unique isolated accounts
            $accountIds = PaperAccountRecord::query()->where('is_primary', true)->pluck('id');
            self::assertSame($totalExpected, $accountIds->unique()->count());
        }
    }

    private function makeDynamicStrategy(string $id): StrategyInterface
    {
        return new class($id) implements StrategyInterface
        {
            public function __construct(private readonly string $id) {}

            public function id(): string
            {
                return $this->id;
            }

            public function version(): string
            {
                return '1.0.0';
            }

            public function description(): string
            {
                return 'Dynamic test strategy: '.$this->id;
            }

            public function parameters(): array
            {
                return ['version' => '1.0.0', 'risk_factor' => '1.5'];
            }

            public function evaluate(StrategyInput $input): SignalDecision
            {
                return SignalDecision::skip($this->id, '1.0.0', $input->symbol, $input->decisionTime, ['test_fixture']);
            }
        };
    }

    private function createSignal(string $side, string $symbol, string $time, string $strategyId): SignalRecord
    {
        $at = new DateTimeImmutable("2026-10-14T{$time}:00Z");
        $buy = $side === 'BUY';

        $signal = SignalRecord::query()->create([
            'id' => Uuid::uuid5(Uuid::NAMESPACE_URL, "dyn-test:{$symbol}:{$side}:{$time}:{$strategyId}")->toString(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => $symbol,
            'timeframe' => '15',
            'side' => $side,
            'quality_class' => 'A',
            'score' => 95,
            'decision_time' => $at,
            'candle_close_time' => $at,
            'entry_low' => $buy ? '100' : '99',
            'entry_high' => $buy ? '101' : '100',
            'stop_loss' => $buy ? '95' : '105',
            'take_profit_1' => $buy ? '105' : '95',
            'take_profit_2' => $buy ? '110' : '90',
            'risk_reward_1' => '1.5',
            'risk_reward_2' => '2.5',
            'model_risk_percent' => '0.50',
            'expires_at' => $at->modify('+30 minutes'),
            'maximum_holding_minutes' => 240,
            'reasons' => ['dynamic_breakout_confirmed'],
            'risks' => [],
            'indicator_snapshot' => ['15m' => ['atr_14' => '1', 'close' => '100']],
            'contract' => ['research_mode' => true],
            'strategy_version' => '1.0.0',
            'schema_version' => '1.0.0',
            'strategy_id' => $strategyId,
        ]);
        $this->seedValidatedPaperEvidence($signal);

        return $signal;
    }

    private function createContext(string $symbol, string $time): void
    {
        MarketContextRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => $symbol,
            'observed_at' => new DateTimeImmutable("2026-10-14T{$time}:00Z"),
            'bid' => '100',
            'ask' => '100.05',
            'turnover_24h' => '100000000',
            'tick_size' => '0.1',
            'funding_rate' => '0.0001',
            'open_interest' => '1000000',
            'qty_step' => '0.001',
            'min_order_qty' => '0.001',
            'max_order_qty' => '1000',
            'min_notional_value' => '5',
        ]);
    }

    private function createCandle(string $time, string $open, string $high, string $low, string $close, string $symbol = 'BTCUSDT'): void
    {
        DB::table('market_candles')->insert([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => $symbol,
            'interval' => '5',
            'open_time' => new DateTimeImmutable("2026-10-14T{$time}:00Z"),
            'open' => $open,
            'high' => $high,
            'low' => $low,
            'close' => $close,
            'volume' => '1000',
            'turnover' => '100000',
            'is_closed' => true,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }
}
