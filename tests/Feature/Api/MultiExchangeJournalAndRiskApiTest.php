<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Exchanges\ExchangeRegistry;
use App\PaperTrading\Infrastructure\Persistence\JournalEventRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\RiskDecisionRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class MultiExchangeJournalAndRiskApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_ten_registered_exchanges_are_supported_in_paper_accounts_filtering(): void
    {
        $registry = app(ExchangeRegistry::class);
        $registered = $registry->registeredIds();

        $this->assertCount(10, $registered);
        $this->assertContains('bybit', $registered);
        $this->assertContains('binance', $registered);
        $this->assertContains('coinbase', $registered);
        $this->assertContains('upbit', $registered);
        $this->assertContains('okx', $registered);
        $this->assertContains('bitget', $registered);
        $this->assertContains('gate', $registered);
        $this->assertContains('kucoin', $registered);
        $this->assertContains('kraken', $registered);
        $this->assertContains('htx', $registered);

        foreach ($registered as $exchange) {
            $response = $this->getJson("/api/v1/paper/accounts?exchange={$exchange}");
            $response->assertOk();
            $response->assertJsonPath('schema_version', '1.0.0');
        }
    }

    public function test_unknown_exchange_returns_400_bad_request_on_paper_accounts(): void
    {
        $response = $this->getJson('/api/v1/paper/accounts?exchange=unsupported_venue');
        $response->assertStatus(400);
        $response->assertJsonPath('error.code', 'unsupported_exchange');
    }

    public function test_journal_filters_all_ten_exchanges_and_returns_payload(): void
    {
        $account = PaperAccountRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'name' => 'OKX Account',
            'mode' => 'PAPER',
            'exchange_id' => 'okx',
            'strategy_id' => 'baseline',
            'status' => 'ACTIVE',
            'starting_balance' => '10000.00',
            'current_equity' => '10000.00',
            'peak_equity' => '10000.00',
        ]);

        $signalId = Uuid::uuid4()->toString();
        SignalRecord::query()->create([
            'id' => $signalId,
            'exchange' => 'okx',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'strategy_id' => 'baseline',
            'strategy_version' => '1.0.0',
            'timeframe' => '15m',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 85,
            'maximum_holding_minutes' => 120,
            'entry_low' => '65000.00',
            'entry_high' => '65100.00',
            'stop_loss' => '64000.00',
            'take_profit_1' => '67000.00',
            'take_profit_2' => '69000.00',
            'risk_reward_1' => '2.00',
            'risk_reward_2' => '4.00',
            'model_risk_percent' => '0.0100',
            'reasons' => ['trend_aligned', 'pullback'],
            'risks' => [],
            'indicator_snapshot' => ['rsi' => 30.5, 'ema' => 64500.0],
            'schema_version' => '1.0.0',
            'contract' => ['schema_version' => '1.0.0'],
            'decision_time' => now('UTC'),
            'candle_close_time' => now('UTC')->subMinute(),
            'expires_at' => now('UTC')->addHour(),
        ]);

        $eventPayload = [
            'sizing' => [
                'notional' => '500.00',
                'leverage' => '1.00',
                'margin' => '500.00',
            ],
            'rejection' => null,
            'execution_delay_seconds' => 1.25,
        ];

        JournalEventRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'idempotency_key' => 'test-idempotency-key-1',
            'paper_account_id' => $account->id,
            'exchange_id' => 'okx',
            'signal_id' => $signalId,
            'event_type' => 'ORDER_FILLED',
            'payload' => $eventPayload,
            'occurred_at' => now('UTC'),
        ]);

        // Filter by okx
        $response = $this->getJson('/api/v1/journal?exchange=okx');
        $response->assertOk();
        $response->assertJsonPath('schema_version', '1.0.0');
        $data = $response->json('data.data');
        $this->assertNotEmpty($data);
        $this->assertSame('ORDER_FILLED', $data[0]['event_type']);
        $this->assertSame($signalId, $data[0]['signal_id']);
        $this->assertEquals($eventPayload, $data[0]['payload']);

        // Unsupported exchange on journal returns 400
        $badResponse = $this->getJson('/api/v1/journal?exchange=fake_exchange');
        $badResponse->assertStatus(400);
        $badResponse->assertJsonPath('error.code', 'unsupported_exchange');
    }

    public function test_risk_decisions_expose_decision_snapshot_and_signal_id(): void
    {
        $account = PaperAccountRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'name' => 'Kraken Account',
            'mode' => 'PAPER',
            'exchange_id' => 'kraken',
            'strategy_id' => 'baseline',
            'status' => 'ACTIVE',
            'starting_balance' => '10000.00',
            'current_equity' => '10000.00',
            'peak_equity' => '10000.00',
        ]);

        $signalId = Uuid::uuid4()->toString();
        SignalRecord::query()->create([
            'id' => $signalId,
            'exchange' => 'kraken',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'strategy_id' => 'baseline',
            'strategy_version' => '1.0.0',
            'timeframe' => '15m',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 90,
            'maximum_holding_minutes' => 120,
            'entry_low' => '65000.00',
            'entry_high' => '65100.00',
            'stop_loss' => '64000.00',
            'take_profit_1' => '67000.00',
            'take_profit_2' => '69000.00',
            'risk_reward_1' => '2.00',
            'risk_reward_2' => '4.00',
            'model_risk_percent' => '0.0100',
            'reasons' => ['trend_aligned'],
            'risks' => [],
            'indicator_snapshot' => ['rsi' => 30.5, 'ema' => 64500.0],
            'schema_version' => '1.0.0',
            'contract' => ['schema_version' => '1.0.0'],
            'decision_time' => now('UTC'),
            'candle_close_time' => now('UTC')->subMinute(),
            'expires_at' => now('UTC')->addHour(),
        ]);

        $snapshot = [
            'account_equity' => '10000.00',
            'risk_per_trade_pct' => '0.01',
            'delay_seconds' => 0.45,
            'checks_passed' => ['daily_drawdown', 'max_positions', 'notional_exposure'],
        ];

        RiskDecisionRecord::query()->create([
            'paper_account_id' => $account->id,
            'exchange_id' => 'kraken',
            'signal_id' => $signalId,
            'allowed' => true,
            'risk_version' => '1.0.0',
            'reasons' => ['all_checks_passed'],
            'snapshot' => $snapshot,
            'decided_at' => now('UTC'),
        ]);

        $response = $this->getJson("/api/v1/risk?exchange=kraken&account={$account->id}");
        $response->assertOk();
        $decisions = $response->json('data.decisions.data');
        $this->assertNotEmpty($decisions);
        $this->assertSame($signalId, $decisions[0]['signal_id']);
        $this->assertTrue($decisions[0]['allowed']);
        $this->assertEquals($snapshot, $decisions[0]['snapshot']);
    }
}
