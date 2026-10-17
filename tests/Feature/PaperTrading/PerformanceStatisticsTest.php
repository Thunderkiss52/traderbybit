<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Application\PerformanceStatisticsService;
use App\PaperTrading\Infrastructure\Persistence\EquitySnapshotRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class PerformanceStatisticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_win_rate_profit_factor_average_r_and_equity_drawdown(): void
    {
        $account = $this->app->make(PaperAccountService::class)->default();
        $this->position($account->id, 'BTCUSDT', 'BUY', '100', '1.0', '10', '-2', '10:00');
        $this->position($account->id, 'ETHUSDT', 'SELL', '-50', '-0.5', '5', '1', '11:00');
        foreach ([['10:00', '10000'], ['11:00', '10100'], ['12:00', '10050']] as [$time, $equity]) {
            EquitySnapshotRecord::query()->create([
                'paper_account_id' => $account->id, 'recorded_at' => new DateTimeImmutable("2026-10-14T{$time}:00Z"),
                'equity' => $equity, 'realized_pnl' => '0', 'unrealized_pnl' => '0', 'drawdown_percent' => '0', 'source_event_key' => "stats:{$time}",
            ]);
        }
        $account->update(['current_equity' => '10050', 'realized_pnl' => '50']);

        $report = $this->app->make(PerformanceStatisticsService::class)->calculate($account->id)->metrics;

        self::assertSame('50.00000000', $report['win_rate']);
        self::assertSame('2.00000000', $report['profit_factor']);
        self::assertSame('0.25000000', $report['average_r']);
        self::assertSame('50', $report['net_pnl']);
        self::assertSame('0.49504950', $report['maximum_drawdown_percent']);
        self::assertSame(1, $report['maximum_consecutive_wins']);
        self::assertSame(1, $report['maximum_consecutive_losses']);
    }

    public function test_profit_factor_is_null_with_explicit_reason_when_there_are_no_losses_and_filters_isolate_portfolios(): void
    {
        $first = $this->app->make(PaperAccountService::class)->default();
        $second = $this->account('Second');
        $this->position($first->id, 'BTCUSDT', 'BUY', '100', '1', '1', '0', '10:00');
        $this->position($second->id, 'ETHUSDT', 'SELL', '-100', '-1', '1', '0', '10:00');

        $report = $this->app->make(PerformanceStatisticsService::class)->calculate($first->id, ['symbol' => 'BTCUSDT'])->metrics;
        self::assertSame(1, $report['closed_positions']);
        self::assertSame('100', $report['net_pnl']);
        self::assertNull($report['profit_factor']);
        self::assertSame('no_negative_net_pnl', $report['profit_factor_unavailable_reason']);
    }

    private function position(string $accountId, string $symbol, string $side, string $net, string $r, string $fees, string $funding, string $time): void
    {
        $signalId = Uuid::uuid5(Uuid::NAMESPACE_URL, "stats:signal:{$accountId}:{$symbol}:{$time}")->toString();
        $at = new DateTimeImmutable("2026-10-14T{$time}:00Z");
        SignalRecord::query()->create([
            'id' => $signalId, 'exchange' => 'bybit', 'category' => 'linear', 'symbol' => $symbol, 'timeframe' => '15', 'side' => $side,
            'quality_class' => 'A', 'score' => 90, 'decision_time' => $at, 'candle_close_time' => $at, 'entry_low' => '99', 'entry_high' => '100',
            'stop_loss' => '95', 'take_profit_1' => '105', 'take_profit_2' => '110', 'risk_reward_1' => '1.5', 'risk_reward_2' => '2.5',
            'model_risk_percent' => '0.5', 'expires_at' => $at->modify('+30 minutes'), 'maximum_holding_minutes' => 240,
            'reasons' => [], 'risks' => [], 'indicator_snapshot' => [], 'contract' => [], 'strategy_version' => '1.0.0', 'schema_version' => '1.0.0',
        ]);
        VirtualPositionRecord::query()->create([
            'id' => Uuid::uuid5(Uuid::NAMESPACE_URL, "stats:position:{$signalId}")->toString(), 'paper_account_id' => $accountId, 'signal_id' => $signalId,
            'symbol' => $symbol, 'side' => $side, 'quality_class' => 'A', 'status' => $net[0] === '-' ? 'CLOSED_SL' : 'CLOSED_TP',
            'entry_price' => '100', 'stop_loss' => '95', 'take_profit_1' => '105', 'take_profit_2' => '110', 'initial_quantity' => '1', 'remaining_quantity' => '0', 'qty_step' => '0.001',
            'risk_capital' => '100', 'gross_pnl' => $net, 'net_pnl' => $net, 'unrealized_pnl' => '0', 'fees' => $fees, 'funding' => $funding, 'r_multiple' => $r,
            'opened_at' => $at, 'maximum_holding_until' => $at->modify('+4 hours'), 'closed_at' => $at->modify('+1 hour'),
            'execution_model' => 'fixture', 'strategy_version' => '1.0.0', 'risk_version' => '1.0.0', 'execution_version' => '1.0.0', 'schema_version' => '1.0.0',
            'funding_complete' => true,
        ]);
    }

    private function account(string $name): PaperAccountRecord
    {
        return PaperAccountRecord::query()->create([
            'id' => Uuid::uuid4()->toString(), 'name' => $name, 'status' => 'ACTIVE', 'starting_balance' => '10000', 'current_equity' => '10000',
            'peak_equity' => '10000', 'realized_pnl' => '0', 'unrealized_pnl' => '0', 'reserved_risk' => '0', 'maximum_drawdown_percent' => '0', 'current_drawdown_percent' => '0',
        ]);
    }
}
