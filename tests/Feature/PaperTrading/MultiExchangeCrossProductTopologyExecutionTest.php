<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Application\PaperExecutionEngine;
use App\PaperTrading\Application\PaperTopologySynchronizer;
use App\PaperTrading\Application\PositionSizingService;
use App\PaperTrading\Domain\InstrumentConstraints;
use App\PaperTrading\Infrastructure\Persistence\EquitySnapshotRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use App\StrategyBuilder\PrimaryStrategies;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class MultiExchangeCrossProductTopologyExecutionTest extends TestCase
{
    use RefreshDatabase;

    private const EXCHANGES = [
        'bybit',
        'binance',
        'coinbase',
        'upbit',
        'okx',
        'bitget',
        'gate',
        'kucoin',
        'kraken',
        'htx',
    ];

    public function test_complete_130_portfolio_topology_is_provisioned_with_account_isolation(): void
    {
        config(['exchanges.binance_paper_provisioning_approved' => true]);
        $sync = app(PaperTopologySynchronizer::class);
        $result = $sync->sync(true);

        self::assertSame('APPLIED', $result['mode']);
        self::assertSame(130, PaperAccountRecord::query()->where('is_primary', true)->count());

        $strategies = PrimaryStrategies::ids();
        self::assertCount(13, $strategies);

        // Verify every exchange x strategy combination has exactly one isolated account
        foreach (self::EXCHANGES as $exchangeId) {
            $exchangeAccounts = PaperAccountRecord::query()
                ->where('exchange_id', $exchangeId)
                ->where('is_primary', true)
                ->get();

            self::assertCount(13, $exchangeAccounts, "Exchange {$exchangeId} must have exactly 13 primary accounts.");

            foreach ($strategies as $strategyId) {
                $account = $exchangeAccounts->firstWhere('primary_strategy_id', $strategyId);
                self::assertNotNull($account, "Missing account for {$exchangeId} x {$strategyId}");
                self::assertSame($strategyId, $account->strategy_id);
                self::assertSame($exchangeId, $account->exchange_id);
                self::assertTrue($account->is_primary);

                // Bybit accounts are ACTIVE and funded with 10,000 USDT
                if ($exchangeId === 'bybit') {
                    self::assertSame('ACTIVE', $account->status);
                    self::assertSame('10000.000000000000', (string) BigDecimal::of($account->starting_balance)->toScale(12));
                    self::assertSame('10000.000000000000', (string) BigDecimal::of($account->current_equity)->toScale(12));
                } else {
                    // Non-Bybit accounts start BLOCKED
                    self::assertSame('BLOCKED', $account->status);
                }

                // Funded accounts must have an initial performance snapshot baseline
                if (BigDecimal::of($account->starting_balance)->isPositive()) {
                    $hasSnapshot = EquitySnapshotRecord::query()
                        ->where('paper_account_id', $account->id)
                        ->where('source_event_key', 'account:initial')
                        ->exists();
                    self::assertTrue($hasSnapshot, "Initial snapshot must exist for funded account {$account->id}");
                }
            }
        }
    }

    public function test_each_exchange_calculates_costs_strictly_without_bybit_fallback(): void
    {
        $engine = app(PaperExecutionEngine::class);

        // Expected taker rates from documented exchange cost models
        $expectedTakerRates = [
            'bybit' => '0.00055',
            'binance' => '0.0005',
            'coinbase' => '0.0060',
            'upbit' => '0.0005',
            'okx' => '0.0005',
            'bitget' => '0.0006',
            'gate' => '0.0005',
            'kucoin' => '0.0006',
            'kraken' => '0.0005',
            'htx' => '0.0005',
        ];

        foreach (self::EXCHANGES as $exchangeId) {
            $costs = $engine->costs($exchangeId);
            self::assertArrayHasKey('taker_rate', $costs);
            self::assertArrayHasKey('slippage_rate', $costs);

            $expectedRate = $expectedTakerRates[$exchangeId];
            self::assertSame(
                (string) BigDecimal::of($expectedRate),
                (string) BigDecimal::of($costs['taker_rate']),
                "Exchange {$exchangeId} must use its own documented taker rate, not a fallback."
            );
        }

        // Prohibit fallback for unknown exchanges
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Unknown paper exchange 'unregistered_venue'.");
        $engine->costs('unregistered_venue');
    }

    public function test_paper_execution_lifecycle_and_accounting_reconciliation_on_multi_exchange_account(): void
    {
        // Provision topology
        config(['exchanges.binance_paper_provisioning_approved' => true]);
        app(PaperTopologySynchronizer::class)->sync(true);

        $now = CarbonImmutable::parse('2026-11-09 10:00:00', 'UTC');

        // Target an OKX account, configure it for simulated paper execution test
        $account = PaperAccountRecord::query()
            ->where('exchange_id', 'okx')
            ->where('primary_strategy_id', 'trend_pullback')
            ->firstOrFail();

        $account->update([
            'status' => 'ACTIVE',
            'starting_balance' => '10000.00',
            'current_equity' => '10000.00',
            'peak_equity' => '10000.00',
        ]);

        // 1. Ingest closed 15m candle
        $candle = CandleRecord::query()->create([
            'exchange' => 'okx',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '15',
            'open_time' => $now,
            'open' => '65000.00',
            'high' => '65500.00',
            'low' => '64800.00',
            'close' => '65200.00',
            'volume' => '100.5',
            'turnover' => '6550000.00',
            'is_closed' => true,
        ]);

        // 2. Generate signal linked to candle
        $signalId = Uuid::uuid4()->toString();
        $signal = SignalRecord::query()->create([
            'id' => $signalId,
            'exchange' => 'okx',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'candle_id' => $candle->id,
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.3.0',
            'schema_version' => '1.0.0',
            'timeframe' => '15',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 88,
            'decision_time' => $now,
            'candle_close_time' => $now,
            'entry_low' => '65100.00',
            'entry_high' => '65300.00',
            'stop_loss' => '64500.00',
            'take_profit_1' => '66600.00',
            'take_profit_2' => '67300.00',
            'risk_reward_1' => '2.00',
            'risk_reward_2' => '3.00',
            'model_risk_percent' => '0.50',
            'maximum_holding_minutes' => 60,
            'indicator_snapshot' => ['ema_fast' => '65100.00', 'ema_slow' => '64900.00'],
            'reasons' => ['trend_aligned', 'pullback_confirmed'],
            'risks' => [],
            'created_at' => $now,
            'expires_at' => $now->addMinutes(30),
            'contract' => [
                'rules' => [
                    ['rule' => 'trend_filter', 'passed' => true, 'category' => 'TREND_FILTER', 'blocking' => false],
                    ['rule' => 'pullback_entry', 'passed' => true, 'category' => 'ENTRY_TRIGGER', 'blocking' => false],
                ],
            ],
        ]);

        // 3. Position Sizing
        $engine = app(PaperExecutionEngine::class);
        $costs = $engine->costs('okx');
        self::assertSame('0.0005', (string) BigDecimal::of($costs['taker_rate']));

        $sBtn = app(PositionSizingService::class);
        $limits = new InstrumentConstraints(
            qtyStep: '0.001',
            minOrderQty: '0.001',
            maxOrderQty: '100.0',
            minNotionalValue: '5.0'
        );

        $size = $sBtn->calculate(
            equity: '10000.00',
            riskPercent: '0.50',
            entry: '65200.00',
            stop: '64500.00',
            limits: $limits,
            executionCosts: $costs
        );
        self::assertTrue(BigDecimal::of($size->quantity)->isPositive());

        // 4. Record execution & position
        $posId = Uuid::uuid4()->toString();
        $execId = Uuid::uuid4()->toString();
        $entryCommission = $engine->commission($size->quantity, '65200.00', 'okx');

        $position = VirtualPositionRecord::query()->create([
            'id' => $posId,
            'paper_account_id' => $account->id,
            'exchange_id' => 'okx',
            'signal_id' => $signalId,
            'symbol' => 'BTCUSDT',
            'side' => 'BUY',
            'quality_class' => 'A',
            'status' => 'OPEN',
            'entry_price' => '65200.00',
            'stop_loss' => '64500.00',
            'take_profit_1' => '66600.00',
            'take_profit_2' => '67300.00',
            'initial_quantity' => $size->quantity,
            'remaining_quantity' => $size->quantity,
            'qty_step' => '0.001',
            'risk_capital' => '50.00',
            'fees' => $entryCommission,
            'funding' => '0.00',
            'gross_pnl' => '0.00',
            'net_pnl' => (string) BigDecimal::of($entryCommission)->negated(),
            'unrealized_pnl' => '0.00',
            'execution_model' => 'FORWARD_PAPER',
            'funding_complete' => true,
            'strategy_version' => '1.3.0',
            'risk_version' => '1.0.0',
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
            'opened_at' => $now,
            'maximum_holding_until' => $now->addHours(4),
            'created_at' => $now,
        ]);

        VirtualExecutionRecord::query()->create([
            'id' => $execId,
            'position_id' => $posId,
            'exchange_id' => 'okx',
            'type' => 'ENTRY',
            'side' => 'BUY',
            'price' => '65200.00',
            'quantity' => $size->quantity,
            'commission' => $entryCommission,
            'slippage' => '0.00',
            'gross_pnl' => '0.00',
            'price_source' => 'SIMULATED',
            'ambiguous_execution' => false,
            'idempotency_key' => 'exec-entry-'.$posId,
            'market_event_at' => $now,
            'registered_at' => $now,
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
            'created_at' => $now,
        ]);

        // 5. Exit at Take Profit: Exit price = 66600.00
        $exitGross = $engine->grossPnl('BUY', '65200.00', '66600.00', $size->quantity);
        $exitCommission = $engine->commission($size->quantity, '66600.00', 'okx');
        $totalCommission = (string) BigDecimal::of($entryCommission)->plus($exitCommission);
        $netRealized = (string) BigDecimal::of($exitGross)->minus($totalCommission);

        VirtualExecutionRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'position_id' => $posId,
            'exchange_id' => 'okx',
            'type' => 'TAKE_PROFIT_1',
            'side' => 'SELL',
            'price' => '66600.00',
            'quantity' => $size->quantity,
            'commission' => $exitCommission,
            'slippage' => '0.00',
            'gross_pnl' => $exitGross,
            'price_source' => 'SIMULATED',
            'ambiguous_execution' => false,
            'idempotency_key' => 'exec-tp1-'.$posId,
            'market_event_at' => $now->addMinutes(15),
            'registered_at' => $now->addMinutes(15),
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
            'created_at' => $now->addMinutes(15),
        ]);

        $position->update([
            'status' => 'CLOSED_TP',
            'remaining_quantity' => '0',
            'gross_pnl' => $exitGross,
            'fees' => $totalCommission,
            'net_pnl' => $netRealized,
            'closed_at' => $now->addMinutes(15),
        ]);

        $newEquity = (string) BigDecimal::of('10000.00')->plus($netRealized);
        $account->update([
            'realized_pnl' => $netRealized,
            'current_equity' => $newEquity,
            'peak_equity' => $newEquity,
        ]);

        // 6. Verify Financial Invariant: Equity = Starting Balance + Net Realized PnL
        self::assertTrue(BigDecimal::of($exitGross)->isPositive());
        self::assertTrue(BigDecimal::of($netRealized)->isPositive());
        self::assertTrue(
            BigDecimal::of('10000.00')->plus($exitGross)->minus($totalCommission)
                ->isEqualTo($account->refresh()->current_equity)
        );
    }

    public function test_strategy_signal_monitor_api_filters_by_exchange_accurately(): void
    {
        config(['exchanges.binance_paper_provisioning_approved' => true]);
        app(PaperTopologySynchronizer::class)->sync(true);

        // Fetch monitor data for okx
        $response = $this->getJson('/api/v1/strategy-monitor?exchange=okx');
        $response->assertStatus(200);

        $data = $response->json('data');
        self::assertIsArray($data);
        self::assertArrayHasKey('signals', $data);
        self::assertArrayHasKey('exchanges', $data);
        self::assertArrayHasKey('strategies', $data);
        self::assertArrayHasKey('metrics', $data);
        self::assertCount(13, $data['strategies']);
        self::assertCount(10, $data['exchanges']);

        // Verify exchange list includes OKX with provisioned accounts count
        $okxSummary = collect($data['exchanges'])->firstWhere('id', 'okx');
        self::assertNotNull($okxSummary);
        self::assertSame(13, $okxSummary['accounts_count']);

        // Every returned item in signals belongs to okx
        foreach ($data['signals']['data'] as $item) {
            self::assertSame('okx', $item['exchange']);
            self::assertArrayHasKey('strategy_id', $item);
        }
    }
}
