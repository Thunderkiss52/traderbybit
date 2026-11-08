<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Infrastructure\Persistence\JournalEventRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\RiskDecisionRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class StrategyMonitorApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_strategy_monitor_returns_metrics_exchanges_and_signals(): void
    {
        $response = $this->getJson('/api/v1/strategy-monitor');
        $response->assertOk();
        $response->assertJsonPath('schema_version', '1.0.0');
        $response->assertJsonPath('research_mode', true);
        $response->assertJsonStructure([
            'data' => [
                'exchanges',
                'strategies',
                'metrics' => [
                    'total_evaluations',
                    'total_signals',
                    'total_skips',
                    'risk_approved',
                    'active_positions',
                    'closed_positions',
                ],
                'signals' => [
                    'data',
                    'current_page',
                    'last_page',
                    'total',
                ],
            ],
        ]);

        $exchanges = $response->json('data.exchanges');
        $this->assertCount(10, $exchanges);
    }

    public function test_strategy_monitor_rejects_unsupported_exchange_with_400(): void
    {
        $response = $this->getJson('/api/v1/strategy-monitor?exchange=invalid_exchange');
        $response->assertStatus(400);
        $response->assertJsonPath('error.code', 'unsupported_exchange');
    }

    public function test_strategy_monitor_signal_timeline_flow(): void
    {
        $openTime = CarbonImmutable::parse('2026-11-08 12:00:00', 'UTC');
        $closeTime = $openTime->addMinutes(15);

        $candle = CandleRecord::query()->create([
            'exchange' => 'coinbase',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '15',
            'open_time' => $openTime,
            'open' => '65000.00',
            'high' => '65500.00',
            'low' => '64900.00',
            'close' => '65400.00',
            'volume' => '120.5',
            'turnover' => '7800000.00',
            'is_closed' => true,
        ]);

        $signalId = Uuid::uuid4()->toString();
        $signal = SignalRecord::query()->create([
            'id' => $signalId,
            'exchange' => 'coinbase',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'candle_id' => $candle->id,
            'strategy_id' => 'baseline',
            'strategy_version' => '1.0.0',
            'timeframe' => '15m',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 92,
            'maximum_holding_minutes' => 120,
            'entry_low' => '65400.00',
            'entry_high' => '65450.00',
            'stop_loss' => '64800.00',
            'take_profit_1' => '66600.00',
            'take_profit_2' => '67800.00',
            'risk_reward_1' => '2.00',
            'risk_reward_2' => '4.00',
            'model_risk_percent' => '0.0100',
            'reasons' => ['trend_continuation'],
            'risks' => [],
            'indicator_snapshot' => [
                'rules' => [
                    ['rule' => 'hourly_adx_strength', 'passed' => true, 'operator' => '>=', 'actual' => 28.5, 'threshold' => 25.0, 'blocking' => false, 'category' => 'TREND_FILTER'],
                ],
            ],
            'schema_version' => '1.0.0',
            'contract' => ['schema_version' => '1.0.0'],
            'decision_time' => $closeTime,
            'candle_close_time' => $closeTime,
            'expires_at' => $closeTime->addHour(),
        ]);

        $account = PaperAccountRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'name' => 'Coinbase Account',
            'mode' => 'PAPER',
            'exchange_id' => 'coinbase',
            'strategy_id' => 'baseline',
            'status' => 'ACTIVE',
            'starting_balance' => '10000.00',
            'current_equity' => '10000.00',
            'peak_equity' => '10000.00',
        ]);

        RiskDecisionRecord::query()->create([
            'paper_account_id' => $account->id,
            'exchange_id' => 'coinbase',
            'signal_id' => $signalId,
            'allowed' => true,
            'risk_version' => '1.0.0',
            'reasons' => ['all_checks_passed'],
            'snapshot' => ['risk' => '0.01'],
            'decided_at' => $closeTime->addSeconds(2),
        ]);

        JournalEventRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'idempotency_key' => 'order-filled-cb-1',
            'paper_account_id' => $account->id,
            'exchange_id' => 'coinbase',
            'signal_id' => $signalId,
            'event_type' => 'ORDER_FILLED',
            'payload' => ['price' => '65420.00', 'quantity' => '0.015'],
            'occurred_at' => $closeTime->addSeconds(5),
        ]);

        $response = $this->getJson("/api/v1/strategy-monitor/timeline?signal_id={$signalId}");
        $response->assertOk();
        $timeline = $response->json('data.timeline');
        $this->assertNotEmpty($timeline);

        $stages = collect($timeline)->pluck('stage')->all();
        $this->assertContains('CANDLE_CLOSED', $stages);
        $this->assertContains('STRATEGY_EVALUATED', $stages);
        $this->assertContains('SIGNAL_GENERATED', $stages);
        $this->assertContains('RISK_DECISION', $stages);
        $this->assertContains('ORDER_FILLED', $stages);
    }
}
