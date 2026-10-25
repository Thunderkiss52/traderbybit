<?php

declare(strict_types=1);

namespace Tests\Feature\Exchanges;

use App\Exchanges\Binance\BinanceDeploymentInitializer;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Application\PaperProcessingService;
use App\PaperTrading\Domain\PositionStatus;
use App\PaperTrading\Domain\SignalLifecycle;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperSignalStateRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class BinanceEmergencyDisableMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_emergency_disable_of_binance_paper_execution_maintains_open_positions_and_funding_while_blocking_new_entries(): void
    {
        // 1. Initialize 26-account topology and Binance capital
        $initializer = $this->app->make(BinanceDeploymentInitializer::class);
        $initializer->initialize('10000', 'OWNER-VECTOR-20261023');

        // 2. Activate Binance trend_pullback account
        $account = PaperAccountRecord::query()->where('exchange_id', 'binance')->where('primary_strategy_id', 'trend_pullback')->firstOrFail();
        $account->update(['status' => 'ACTIVE']);

        $at = new DateTimeImmutable('2026-10-25 12:00:00 UTC');

        // 3. Seed an existing OPEN position on Binance with entry at 100, TP at 105
        $signal1 = $this->createSignal('binance', 'trend_pullback', 'BUY', '100', '95', '105', $at->modify('-1 hour'));
        $positionId = (string) Uuid::uuid4();
        $position = VirtualPositionRecord::query()->create([
            'id' => $positionId,
            'paper_account_id' => $account->id,
            'exchange_id' => 'binance',
            'signal_id' => $signal1->id,
            'symbol' => 'BTCUSDT',
            'side' => 'BUY',
            'quality_class' => 'A',
            'status' => PositionStatus::Open->value,
            'entry_price' => '100',
            'stop_loss' => '95',
            'take_profit_1' => '105',
            'take_profit_2' => '110',
            'initial_quantity' => '1',
            'remaining_quantity' => '1',
            'qty_step' => '0.001',
            'risk_capital' => '50',
            'gross_pnl' => '0',
            'net_pnl' => '-0.06',
            'fees' => '0.06',
            'funding' => '0',
            'unrealized_pnl' => '0',
            'opened_at' => $at->modify('-1 hour'),
            'maximum_holding_until' => $at->modify('+3 hours'),
            'execution_model' => 'close_5m',
            'strategy_version' => '1.3.0',
            'risk_version' => '1.0.0',
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);
        VirtualExecutionRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'position_id' => $positionId,
            'exchange_id' => 'binance',
            'type' => 'ENTRY',
            'side' => 'BUY',
            'price' => '100',
            'quantity' => '1',
            'gross_pnl' => '0',
            'commission' => '0.06',
            'slippage' => '0',
            'market_event_at' => $at->modify('-1 hour'),
            'registered_at' => $at->modify('-1 hour'),
            'price_source' => 'close_5m',
            'ambiguous_execution' => false,
            'idempotency_key' => 'exec-entry-'.Uuid::uuid4()->toString(),
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);
        // Corresponding active state
        $stateRecord = PaperSignalStateRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'paper_account_id' => $account->id,
            'exchange_id' => 'binance',
            'signal_id' => $signal1->id,
            'status' => SignalLifecycle::Active->value,
            'processed_at' => $at,
        ]);

        // 4. Emergency disable: turn OFF Binance paper execution approval
        config([
            'exchanges.binance.paper_execution_approved' => false,
            'exchanges.binance.paper_taker_rate' => '0.0006',
            'exchanges.binance.paper_slippage_rate' => '0.0002',
        ]);

        // 5. Create a new signal that attempts entry under emergency disable
        $newSignal = $this->createSignal('binance', 'trend_pullback', 'BUY', '104', '99', '110', $at);

        // Seed market context and a 5m bar with high=106 (triggers TP1 at 105)
        $this->seedCandle('binance', 'BTCUSDT', '5', $at, '103', '106', '102.5', '105.5');
        $this->seedContext('binance', 'BTCUSDT', $at);

        // 6. Run PaperProcessingService with allowNewEntries = false (or default under unapproved execution)
        $service = $this->app->make(PaperProcessingService::class);
        $res = $service->process(accountId: $account->id, symbol: 'BTCUSDT', at: $at->modify('+5 minutes'), allowNewEntries: false);

        self::assertSame($account->id, $res['account_id']);

        // 7. Verify INVARIANTS:
        // Invariant A: New signal was NEVER opened
        $newPosition = VirtualPositionRecord::query()->where('signal_id', $newSignal->id)->first();
        self::assertNull($newPosition);

        // Invariant B: Existing position was maintained and executed TP exit!
        $position->refresh();
        self::assertNotSame(PositionStatus::Open->value, $position->status);
        self::assertTrue(PositionStatus::from($position->status)->isClosed() || PositionStatus::from($position->status) === PositionStatus::PartiallyClosed);
        self::assertTrue(BigDecimal::of($position->gross_pnl)->isPositive());
        self::assertTrue(BigDecimal::of($position->net_pnl)->isPositive());

        // Invariant C: Exit execution record exists
        $exitExec = VirtualExecutionRecord::query()->where('position_id', $position->id)->where('type', 'TAKE_PROFIT_1')->first();
        self::assertNotNull($exitExec);
        self::assertSame('binance', $exitExec->exchange_id);
        self::assertTrue(BigDecimal::of((string) $exitExec->price)->isGreaterThan('104.5'));

        // Invariant D: Account equity and realized PnL correctly reflected gains
        $account->refresh();
        self::assertTrue(BigDecimal::of((string) $account->realized_pnl)->isPositive());
        self::assertTrue(BigDecimal::of((string) $account->current_equity)->isGreaterThan('10000'));
    }

    private function createSignal(string $exchange, string $strategyId, string $side, string $entry, string $sl, string $tp, DateTimeImmutable $time): SignalRecord
    {
        return SignalRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'exchange' => $exchange,
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15',
            'side' => $side,
            'quality_class' => 'A',
            'score' => 90,
            'decision_time' => $time,
            'candle_close_time' => $time,
            'entry_low' => (string) ($entry - 0.5),
            'entry_high' => (string) ($entry + 0.5),
            'stop_loss' => $sl,
            'take_profit_1' => $tp,
            'take_profit_2' => (string) ($tp + 5),
            'risk_reward_1' => '1.5',
            'risk_reward_2' => '2.5',
            'model_risk_percent' => '0.50',
            'expires_at' => $time->modify('+30 minutes'),
            'maximum_holding_minutes' => 240,
            'reasons' => ['test'],
            'risks' => [],
            'indicator_snapshot' => ['15m' => ['atr_14' => '1', 'close' => $entry]],
            'contract' => ['research_mode' => true],
            'strategy_version' => '1.3.0',
            'schema_version' => '1.0.0',
            'strategy_id' => $strategyId,
        ]);
    }

    private function seedCandle(string $exchange, string $symbol, string $interval, DateTimeImmutable $time, string $open, string $high, string $low, string $close): void
    {
        CandleRecord::query()->create([
            'exchange' => $exchange,
            'category' => 'linear',
            'symbol' => $symbol,
            'interval' => $interval,
            'open_time' => $time,
            'open' => $open,
            'high' => $high,
            'low' => $low,
            'close' => $close,
            'volume' => '100',
            'turnover' => '10000',
            'is_closed' => true,
        ]);
    }

    private function seedContext(string $exchange, string $symbol, DateTimeImmutable $time): void
    {
        MarketContextRecord::query()->create([
            'exchange' => $exchange,
            'category' => 'linear',
            'symbol' => $symbol,
            'observed_at' => $time,
            'bid' => '105.4',
            'ask' => '105.6',
            'turnover_24h' => '1000000',
            'tick_size' => '0.1',
            'qty_step' => '0.001',
            'min_order_qty' => '0.001',
            'max_order_qty' => '1000',
            'min_notional_value' => '5',
        ]);
    }
}
