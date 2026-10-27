<?php

declare(strict_types=1);

namespace Tests\Unit\Research;

use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Research\Application\PortfolioCorrelationService;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PortfolioCorrelationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function seedSignal(string $id, string $strategyId, string $symbol, string $side, CarbonImmutable $at): void
    {
        SignalRecord::query()->create([
            'id' => $id,
            'strategy_id' => $strategyId,
            'strategy_version' => '1.0.0',
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => $symbol,
            'timeframe' => '15',
            'side' => $side,
            'quality_class' => 'A',
            'score' => 90,
            'decision_time' => $at,
            'candle_close_time' => $at,
            'entry_low' => '100',
            'entry_high' => '101',
            'stop_loss' => '95',
            'take_profit_1' => '110',
            'take_profit_2' => '115',
            'risk_reward_1' => '2.0',
            'risk_reward_2' => '3.0',
            'model_risk_percent' => '1.0',
            'expires_at' => $at->addMinutes(30),
            'maximum_holding_minutes' => 240,
            'reasons' => ['test'],
            'risks' => [],
            'indicator_snapshot' => [],
            'contract' => [],
            'schema_version' => '1.0.0',
        ]);
    }

    public function test_computes_portfolio_correlation_and_overlap(): void
    {
        $service = new PortfolioCorrelationService;
        $now = CarbonImmutable::now();

        $accountA = PaperAccountRecord::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Account A',
            'status' => 'ACTIVE',
            'starting_balance' => '10000',
            'current_equity' => '10000',
            'peak_equity' => '10000',
            'realized_pnl' => '0',
            'unrealized_pnl' => '0',
            'reserved_risk' => '0',
            'maximum_drawdown_percent' => '0',
            'current_drawdown_percent' => '0',
            'mode' => 'FORWARD_PAPER',
            'strategy_id' => 'strategy_a',
            'strategy_version' => '1.0.0',
        ]);

        $accountB = PaperAccountRecord::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Account B',
            'status' => 'ACTIVE',
            'starting_balance' => '10000',
            'current_equity' => '10000',
            'peak_equity' => '10000',
            'realized_pnl' => '0',
            'unrealized_pnl' => '0',
            'reserved_risk' => '0',
            'maximum_drawdown_percent' => '0',
            'current_drawdown_percent' => '0',
            'mode' => 'FORWARD_PAPER',
            'strategy_id' => 'strategy_b',
            'strategy_version' => '1.0.0',
        ]);

        // Seed 3 closed positions for strategy A
        for ($i = 0; $i < 3; $i++) {
            $sigId = (string) Str::uuid();
            $this->seedSignal($sigId, 'strategy_a', 'BTCUSDT', 'BUY', $now->subDays(5 - $i));

            VirtualPositionRecord::query()->create([
                'id' => (string) Str::uuid(),
                'paper_account_id' => $accountA->id,
                'signal_id' => $sigId,
                'strategy_version' => '1.0.0',
                'risk_version' => '1.0.0',
                'quality_class' => 'A',
                'execution_model' => 'paper',
                'execution_version' => '1.0.0',
                'schema_version' => '1.0.0',
                'symbol' => 'BTCUSDT',
                'side' => 'BUY',
                'status' => 'CLOSED',
                'entry_price' => '60000.0',
                'stop_loss' => '59000.0',
                'take_profit_1' => '62000.0',
                'take_profit_2' => '63000.0',
                'initial_quantity' => '0.1',
                'remaining_quantity' => '0.0',
                'qty_step' => '0.001',
                'risk_capital' => '100.0',
                'gross_pnl' => (string) (($i + 1) * 50),
                'net_pnl' => (string) (($i + 1) * 48),
                'unrealized_pnl' => '0.0',
                'fees' => '2.0',
                'funding' => '0.0',
                'opened_at' => $now->subDays(5 - $i),
                'closed_at' => $now->subDays(4 - $i),
                'maximum_holding_until' => $now->addDay(),
            ]);
        }

        // Seed 3 closed positions for strategy B (positive co-movement)
        for ($i = 0; $i < 3; $i++) {
            $sigId = (string) Str::uuid();
            $this->seedSignal($sigId, 'strategy_b', 'ETHUSDT', 'BUY', $now->subDays(5 - $i));

            VirtualPositionRecord::query()->create([
                'id' => (string) Str::uuid(),
                'paper_account_id' => $accountB->id,
                'signal_id' => $sigId,
                'strategy_version' => '1.0.0',
                'risk_version' => '1.0.0',
                'quality_class' => 'A',
                'execution_model' => 'paper',
                'execution_version' => '1.0.0',
                'schema_version' => '1.0.0',
                'symbol' => 'ETHUSDT',
                'side' => 'BUY',
                'status' => 'CLOSED',
                'entry_price' => '3000.0',
                'stop_loss' => '2950.0',
                'take_profit_1' => '3100.0',
                'take_profit_2' => '3200.0',
                'initial_quantity' => '1.0',
                'remaining_quantity' => '0.0',
                'qty_step' => '0.01',
                'risk_capital' => '100.0',
                'gross_pnl' => (string) (($i + 1) * 60),
                'net_pnl' => (string) (($i + 1) * 58),
                'unrealized_pnl' => '0.0',
                'fees' => '2.0',
                'funding' => '0.0',
                'opened_at' => $now->subDays(5 - $i),
                'closed_at' => $now->subDays(4 - $i),
                'maximum_holding_until' => $now->addDay(),
            ]);
        }

        // Seed concurrent OPEN positions on BTCUSDT BUY
        for ($i = 0; $i < 2; $i++) {
            $sigId = (string) Str::uuid();
            $this->seedSignal($sigId, $i === 0 ? 'strategy_a' : 'strategy_b', 'BTCUSDT', 'BUY', $now->subHour());

            VirtualPositionRecord::query()->create([
                'id' => (string) Str::uuid(),
                'paper_account_id' => $i === 0 ? $accountA->id : $accountB->id,
                'signal_id' => $sigId,
                'strategy_version' => '1.0.0',
                'risk_version' => '1.0.0',
                'quality_class' => 'A',
                'execution_model' => 'paper',
                'execution_version' => '1.0.0',
                'schema_version' => '1.0.0',
                'symbol' => 'BTCUSDT',
                'side' => 'BUY',
                'status' => 'OPEN',
                'entry_price' => '65000.0',
                'stop_loss' => '64000.0',
                'take_profit_1' => '67000.0',
                'take_profit_2' => '68000.0',
                'initial_quantity' => '0.1',
                'remaining_quantity' => '0.1',
                'qty_step' => '0.001',
                'risk_capital' => '100.0',
                'gross_pnl' => '0.0',
                'net_pnl' => '0.0',
                'unrealized_pnl' => '10.0',
                'fees' => '0.0',
                'funding' => '0.0',
                'opened_at' => $now->subHour(),
                'maximum_holding_until' => $now->addDay(),
            ]);
        }

        $snap = $service->evaluate('14d');

        self::assertNotNull($snap);
        self::assertArrayHasKey('strategy_a', $snap->pair_matrix);
        self::assertSame(1.0, (float) $snap->pair_matrix['strategy_a']['strategy_b']);
        self::assertSame(2, $snap->concurrent_overlap['BTCUSDT_BUY']);
        self::assertNotEmpty($snap->alerts);
    }

    public function test_f15_real_lifecycle_closed_and_partially_closed_positions_are_included(): void
    {
        $service = new PortfolioCorrelationService;
        $now = CarbonImmutable::now();

        $accountA = PaperAccountRecord::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Account Real A',
            'status' => 'ACTIVE',
            'starting_balance' => '10000',
            'current_equity' => '10000',
            'peak_equity' => '10000',
            'realized_pnl' => '0',
            'unrealized_pnl' => '0',
            'reserved_risk' => '0',
            'maximum_drawdown_percent' => '0',
            'current_drawdown_percent' => '0',
            'mode' => 'FORWARD_PAPER',
            'strategy_id' => 'strat_x',
            'strategy_version' => '1.0.0',
        ]);

        $accountB = PaperAccountRecord::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Account Real B',
            'status' => 'ACTIVE',
            'starting_balance' => '10000',
            'current_equity' => '10000',
            'peak_equity' => '10000',
            'realized_pnl' => '0',
            'unrealized_pnl' => '0',
            'reserved_risk' => '0',
            'maximum_drawdown_percent' => '0',
            'current_drawdown_percent' => '0',
            'mode' => 'FORWARD_PAPER',
            'strategy_id' => 'strat_y',
            'strategy_version' => '1.0.0',
        ]);

        // Seed 3 positions with real closed statuses: CLOSED_TP, CLOSED_SL, CLOSED_TIMEOUT
        $closedStatuses = ['CLOSED_TP', 'CLOSED_SL', 'CLOSED_TIMEOUT'];
        for ($i = 0; $i < 3; $i++) {
            $sigA = (string) Str::uuid();
            $this->seedSignal($sigA, 'strat_x', 'BTCUSDT', 'BUY', $now->subDays(4 - $i));
            VirtualPositionRecord::query()->create([
                'id' => (string) Str::uuid(),
                'paper_account_id' => $accountA->id,
                'signal_id' => $sigA,
                'strategy_version' => '1.0.0',
                'risk_version' => '1.0.0',
                'quality_class' => 'A',
                'execution_model' => 'paper',
                'execution_version' => '1.0.0',
                'schema_version' => '1.0.0',
                'symbol' => 'BTCUSDT',
                'side' => 'BUY',
                'status' => $closedStatuses[$i],
                'entry_price' => '60000.0',
                'stop_loss' => '59000.0',
                'take_profit_1' => '62000.0',
                'take_profit_2' => '63000.0',
                'initial_quantity' => '0.1',
                'remaining_quantity' => '0.0',
                'qty_step' => '0.001',
                'risk_capital' => '100.0',
                'gross_pnl' => (string) (($i + 1) * 50),
                'net_pnl' => (string) (($i + 1) * 48),
                'unrealized_pnl' => '0.0',
                'fees' => '2.0',
                'funding' => '0.0',
                'opened_at' => $now->subDays(4 - $i),
                'closed_at' => $now->subDays(3 - $i),
                'maximum_holding_until' => $now->addDay(),
            ]);

            $sigB = (string) Str::uuid();
            $this->seedSignal($sigB, 'strat_y', 'ETHUSDT', 'BUY', $now->subDays(4 - $i));
            VirtualPositionRecord::query()->create([
                'id' => (string) Str::uuid(),
                'paper_account_id' => $accountB->id,
                'signal_id' => $sigB,
                'strategy_version' => '1.0.0',
                'risk_version' => '1.0.0',
                'quality_class' => 'A',
                'execution_model' => 'paper',
                'execution_version' => '1.0.0',
                'schema_version' => '1.0.0',
                'symbol' => 'ETHUSDT',
                'side' => 'BUY',
                'status' => $closedStatuses[$i],
                'entry_price' => '3000.0',
                'stop_loss' => '2950.0',
                'take_profit_1' => '3100.0',
                'take_profit_2' => '3200.0',
                'initial_quantity' => '1.0',
                'remaining_quantity' => '0.0',
                'qty_step' => '0.01',
                'risk_capital' => '100.0',
                'gross_pnl' => (string) (($i + 1) * 60),
                'net_pnl' => (string) (($i + 1) * 58),
                'unrealized_pnl' => '0.0',
                'fees' => '2.0',
                'funding' => '0.0',
                'opened_at' => $now->subDays(4 - $i),
                'closed_at' => $now->subDays(3 - $i),
                'maximum_holding_until' => $now->addDay(),
            ]);
        }

        // Also seed 1 PARTIALLY_CLOSED open position to verify concurrent overlap detects it
        $sigOpen = (string) Str::uuid();
        $this->seedSignal($sigOpen, 'strat_x', 'BTCUSDT', 'BUY', $now->subMinutes(30));
        VirtualPositionRecord::query()->create([
            'id' => (string) Str::uuid(),
            'paper_account_id' => $accountA->id,
            'signal_id' => $sigOpen,
            'strategy_version' => '1.0.0',
            'risk_version' => '1.0.0',
            'quality_class' => 'A',
            'execution_model' => 'paper',
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
            'symbol' => 'BTCUSDT',
            'side' => 'BUY',
            'status' => 'PARTIALLY_CLOSED',
            'entry_price' => '65000.0',
            'stop_loss' => '64000.0',
            'take_profit_1' => '67000.0',
            'take_profit_2' => '68000.0',
            'initial_quantity' => '0.1',
            'remaining_quantity' => '0.05',
            'qty_step' => '0.001',
            'risk_capital' => '50.0',
            'gross_pnl' => '20.0',
            'net_pnl' => '19.0',
            'unrealized_pnl' => '5.0',
            'fees' => '1.0',
            'funding' => '0.0',
            'opened_at' => $now->subHour(),
            'maximum_holding_until' => $now->addDay(),
        ]);

        $snap = $service->evaluate('14d');
        self::assertNotNull($snap);
        self::assertArrayHasKey('strat_x', $snap->pair_matrix);
        self::assertSame(1.0, (float) $snap->pair_matrix['strat_x']['strat_y']);
        self::assertSame(1, $snap->concurrent_overlap['BTCUSDT_BUY']);
    }
}
