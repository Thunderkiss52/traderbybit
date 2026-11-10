<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\PaperTrading\Application\PaperTopologySynchronizer;
use App\PaperTrading\Domain\PositionStatus;
use App\PaperTrading\Domain\TradeRiskClass;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\RiskDecisionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Domain\SignalQuality;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class SignalQualityAnalyticsAndMultiTimeframeProfitabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['paper.topology.auto_sync_enabled' => false]);
        $this->app->make(PaperTopologySynchronizer::class)->sync(true);
    }

    public function test_signal_analytics_endpoint_returns_six_canonical_timeframes(): void
    {
        $this->seedSampleSignalCohorts();

        $response = $this->getJson('/api/v1/research/profitability/signal-analytics');
        $response->assertOk();

        $data = $response->json('data');
        self::assertNotNull($data);
        self::assertArrayHasKey('overview', $data);
        self::assertArrayHasKey('exchange_comparison', $data);
        self::assertArrayHasKey('strategy_comparison', $data);
        self::assertArrayHasKey('timeframe_comparison', $data);
        self::assertArrayHasKey('quality_risk_matrix', $data);
        self::assertArrayHasKey('diagnostics', $data);

        $timeframes = collect($data['timeframe_comparison'])->pluck('timeframe')->all();
        self::assertSame(['1m', '3m', '5m', '15m', '30m', '60m'], $timeframes);

        // Quality Risk Matrix has all 16 combinations (4 quality x 4 risk)
        self::assertCount(16, $data['quality_risk_matrix']);
    }

    public function test_exchange_filter_strictly_isolates_signal_and_position_metrics(): void
    {
        $this->seedSampleSignalCohorts();

        // 1. Bybit scope
        $bybitRes = $this->getJson('/api/v1/research/profitability/signal-analytics?exchange=bybit');
        $bybitRes->assertOk();
        $bybitData = $bybitRes->json('data');
        self::assertSame(5, $bybitData['overview']['total_signals']);
        self::assertSame('50.00', (string) BigDecimal::of((string) $bybitData['overview']['overall_net_pnl'])->toScale(2));

        // 2. Binance scope
        $binanceRes = $this->getJson('/api/v1/research/profitability/signal-analytics?exchange=binance');
        $binanceRes->assertOk();
        $binanceData = $binanceRes->json('data');
        self::assertSame(3, $binanceData['overview']['total_signals']);
        self::assertSame('-30.00', (string) BigDecimal::of((string) $binanceData['overview']['overall_net_pnl'])->toScale(2));

        // 3. OKX scope (no signals)
        $okxRes = $this->getJson('/api/v1/research/profitability/signal-analytics?exchange=okx');
        $okxRes->assertOk();
        $okxData = $okxRes->json('data');
        self::assertSame(0, $okxData['overview']['total_signals']);
        self::assertSame('0', (string) $okxData['overview']['overall_net_pnl']);
    }

    public function test_instrument_filter_strictly_isolates_btc_and_eth(): void
    {
        $this->seedSampleSignalCohorts();

        // BTC only
        $btcRes = $this->getJson('/api/v1/research/profitability/signal-analytics?symbol=BTCUSDT');
        $btcRes->assertOk();
        $btcData = $btcRes->json('data');
        self::assertSame(5, $btcData['overview']['total_signals']);
        self::assertSame('50.00', (string) BigDecimal::of((string) $btcData['overview']['overall_net_pnl'])->toScale(2));

        // ETH only
        $ethRes = $this->getJson('/api/v1/research/profitability/signal-analytics?symbol=ETHUSDT');
        $ethRes->assertOk();
        $ethData = $ethRes->json('data');
        self::assertSame(3, $ethData['overview']['total_signals']);
        self::assertSame('-30.00', (string) BigDecimal::of((string) $ethData['overview']['overall_net_pnl'])->toScale(2));
    }

    public function test_retrospective_outcome_classifies_good_bad_and_unproven(): void
    {
        $this->seedSampleSignalCohorts();

        $response = $this->getJson('/api/v1/research/profitability/signal-analytics');
        $response->assertOk();

        $overview = $response->json('data.overview');
        self::assertSame(1, $overview['outcome_distribution']['GOOD']);
        self::assertSame(1, $overview['outcome_distribution']['BAD']);
        self::assertSame(6, $overview['outcome_distribution']['UNPROVEN']);
    }

    public function test_signal_explorer_paginates_and_sorts_correctly(): void
    {
        $this->seedSampleSignalCohorts();

        $response = $this->getJson('/api/v1/research/profitability/signal-explorer?per_page=3&sort_by=decision_time&sort_dir=desc');
        $response->assertOk();

        $json = $response->json();
        self::assertSame(1, $json['current_page']);
        self::assertSame(3, $json['per_page']);
        self::assertSame(8, $json['total']);
        self::assertCount(3, $json['data']);

        $item = $json['data'][0];
        self::assertArrayHasKey('id', $item);
        self::assertArrayHasKey('exchange', $item);
        self::assertArrayHasKey('strategy_id', $item);
        self::assertArrayHasKey('symbol', $item);
        self::assertArrayHasKey('timeframe', $item);
        self::assertArrayHasKey('side', $item);
        self::assertArrayHasKey('entry_price', $item);
        self::assertArrayHasKey('signal_quality', $item);
        self::assertArrayHasKey('trade_risk', $item);
        self::assertArrayHasKey('gate_decision', $item);
        self::assertArrayHasKey('retrospective_outcome', $item);
    }

    public function test_signal_detail_endpoint_provides_complete_explainability(): void
    {
        $this->seedSampleSignalCohorts();

        $signal = SignalRecord::query()->where('exchange', 'bybit')->where('symbol', 'BTCUSDT')->firstOrFail();

        $response = $this->getJson("/api/v1/research/profitability/signal-explorer/{$signal->id}");
        $response->assertOk();

        $data = $response->json('data');
        self::assertSame($signal->id, $data['id']);
        self::assertSame('bybit', $data['exchange']);
        self::assertSame('BTCUSDT', $data['symbol']);
        self::assertSame('15m', $data['timeframe']);
        self::assertSame('HIGH', $data['signal_quality']);
        self::assertSame('LOW', $data['trade_risk']);
        self::assertSame('ELIGIBLE', $data['gate_decision']);
        self::assertTrue($data['gate_allowed']);
        self::assertNotNull($data['indicator_snapshot']);
        self::assertNotNull($data['position']);
        self::assertSame('50.00', (string) BigDecimal::of((string) $data['position']['net_pnl'])->toScale(2));
    }

    public function test_signal_explorer_csv_export_streams_valid_content(): void
    {
        $this->seedSampleSignalCohorts();

        $response = $this->get('/api/v1/research/profitability/signal-explorer/export');
        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        self::assertStringContainsString('Timestamp,Exchange,Strategy,Instrument,Timeframe,Direction', $content);
        self::assertStringContainsString('bybit', $content);
        self::assertStringContainsString('BTCUSDT', $content);
        self::assertStringContainsString('binance', $content);
        self::assertStringContainsString('ETHUSDT', $content);
    }

    private function seedSampleSignalCohorts(): void
    {
        $bybitAccount = PaperAccountRecord::query()->where('exchange_id', 'bybit')->where('primary_strategy_id', 'trend_pullback')->firstOrFail();
        $binanceAccount = PaperAccountRecord::query()->where('exchange_id', 'binance')->where('primary_strategy_id', 'trend_pullback')->firstOrFail();

        $now = CarbonImmutable::now('UTC');

        // 1. Bybit BTCUSDT 15m Winning Trade (GOOD outcome)
        $sig1 = SignalRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15m',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'side' => 'BUY',
            'quality_class' => 'A',
            'signal_quality' => SignalQuality::High->value,
            'trade_risk' => TradeRiskClass::Low->value,
            'retrospective_outcome' => 'GOOD',
            'score' => 90,
            'entry_low' => '60000',
            'entry_high' => '60050',
            'stop_loss' => '59500',
            'take_profit_1' => '61000',
            'take_profit_2' => '62000',
            'risk_reward_1' => '2.0',
            'risk_reward_2' => '4.0',
            'model_risk_percent' => '0.5',
            'decision_time' => $now->subMinutes(120),
            'candle_close_time' => $now->subMinutes(120),
            'expires_at' => $now->addHours(2),
            'maximum_holding_minutes' => 120,
            'schema_version' => '1.0.0',
            'reasons' => ['EMA_PULLBACK_RETEST', 'RSI_OVERSOLD_REVERSAL'],
            'risks' => ['NEAR_LOCAL_RESISTANCE'],
            'indicator_snapshot' => ['ema_50' => 59800, 'rsi' => 38],
            'contract' => ['rules' => ['trend_filter' => true, 'entry_trigger' => true]],
        ]);

        RiskDecisionRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'paper_account_id' => $bybitAccount->id,
            'signal_id' => $sig1->id,
            'risk_version' => '1.0.0',
            'signal_quality' => 'HIGH',
            'trade_risk' => 'LOW',
            'allowed' => true,
            'reasons' => ['GATE_APPROVED'],
            'snapshot' => ['gate_decision' => 'ELIGIBLE', 'expected_net_edge' => 0.15],
            'decided_at' => $now->subMinutes(120),
        ]);

        VirtualPositionRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'paper_account_id' => $bybitAccount->id,
            'signal_id' => $sig1->id,
            'exchange_id' => 'bybit',
            'symbol' => 'BTCUSDT',
            'side' => 'BUY',
            'quality_class' => 'A',
            'signal_quality' => 'HIGH',
            'trade_risk' => 'LOW',
            'retrospective_outcome' => 'GOOD',
            'status' => PositionStatus::ClosedTp->value,
            'entry_price' => '60025.00',
            'stop_loss' => '59500.00',
            'take_profit_1' => '61000.00',
            'take_profit_2' => '62000.00',
            'initial_quantity' => '0.1',
            'remaining_quantity' => '0',
            'qty_step' => '0.001',
            'risk_capital' => '52.50',
            'gross_pnl' => '52.50000000',
            'fees' => '2.50000000',
            'funding' => '0',
            'net_pnl' => '50.00000000',
            'r_multiple' => '0.9523809524',
            'opened_at' => $now->subMinutes(115),
            'maximum_holding_until' => $now->addHours(2),
            'closed_at' => $now->subMinutes(60),
            'strategy_version' => '1.0.0',
            'risk_version' => '1.0.0',
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
            'execution_model' => 'FORWARD_PAPER',
            'funding_complete' => true,
        ]);

        // 2. Binance ETHUSDT 5m Losing Trade (BAD outcome)
        $sig2 = SignalRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'exchange' => 'binance',
            'category' => 'linear',
            'symbol' => 'ETHUSDT',
            'timeframe' => '5m',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'side' => 'SELL',
            'quality_class' => 'B',
            'signal_quality' => SignalQuality::Medium->value,
            'trade_risk' => TradeRiskClass::Medium->value,
            'retrospective_outcome' => 'BAD',
            'score' => 70,
            'entry_low' => '3000',
            'entry_high' => '3005',
            'stop_loss' => '3050',
            'take_profit_1' => '2900',
            'take_profit_2' => '2800',
            'risk_reward_1' => '2.0',
            'risk_reward_2' => '4.0',
            'model_risk_percent' => '0.25',
            'decision_time' => $now->subMinutes(100),
            'candle_close_time' => $now->subMinutes(100),
            'expires_at' => $now->addHours(1),
            'maximum_holding_minutes' => 60,
            'schema_version' => '1.0.0',
            'reasons' => ['RESISTANCE_REJECTION'],
            'risks' => ['STRONG_MOMENTUM_AGAINST'],
            'indicator_snapshot' => ['rsi' => 65],
            'contract' => ['rules' => ['trend_filter' => true]],
        ]);

        RiskDecisionRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'paper_account_id' => $binanceAccount->id,
            'signal_id' => $sig2->id,
            'risk_version' => '1.0.0',
            'signal_quality' => 'MEDIUM',
            'trade_risk' => 'MEDIUM',
            'allowed' => true,
            'reasons' => ['CONDITIONAL_APPROVAL'],
            'snapshot' => ['gate_decision' => 'CONDITIONALLY_ELIGIBLE', 'expected_net_edge' => 0.06],
            'decided_at' => $now->subMinutes(100),
        ]);

        VirtualPositionRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'paper_account_id' => $binanceAccount->id,
            'signal_id' => $sig2->id,
            'exchange_id' => 'binance',
            'symbol' => 'ETHUSDT',
            'side' => 'SELL',
            'quality_class' => 'B',
            'signal_quality' => 'MEDIUM',
            'trade_risk' => 'MEDIUM',
            'retrospective_outcome' => 'BAD',
            'status' => PositionStatus::ClosedTp->value,
            'entry_price' => '3002.50',
            'stop_loss' => '3050.00',
            'take_profit_1' => '2900.00',
            'take_profit_2' => '2800.00',
            'initial_quantity' => '1.0',
            'remaining_quantity' => '0',
            'qty_step' => '0.01',
            'risk_capital' => '47.50',
            'gross_pnl' => '-28.00000000',
            'fees' => '2.00000000',
            'funding' => '0',
            'net_pnl' => '-30.00000000',
            'r_multiple' => '-0.6315789474',
            'opened_at' => $now->subMinutes(95),
            'maximum_holding_until' => $now->addHours(1),
            'closed_at' => $now->subMinutes(40),
            'strategy_version' => '1.0.0',
            'risk_version' => '1.0.0',
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
            'execution_model' => 'FORWARD_PAPER',
            'funding_complete' => true,
        ]);

        // 3. Bybit BTCUSDT 1m Low Quality Observation Only (UNPROVEN)
        SignalRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '1m',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'side' => 'BUY',
            'quality_class' => 'C',
            'signal_quality' => SignalQuality::Low->value,
            'trade_risk' => TradeRiskClass::Low->value,
            'retrospective_outcome' => 'UNPROVEN',
            'score' => 45,
            'entry_low' => '60100',
            'entry_high' => '60120',
            'stop_loss' => '59900',
            'decision_time' => $now->subMinutes(80),
            'candle_close_time' => $now->subMinutes(80),
            'expires_at' => $now->addMinutes(30),
            'maximum_holding_minutes' => 30,
            'schema_version' => '1.0.0',
            'indicator_snapshot' => ['rsi' => 45],
            'contract' => ['rules' => ['trend_filter' => true]],
            'reasons' => ['MINOR_BOUNCE'],
            'risks' => ['LOW_CONVICTION'],
        ]);

        // 4. Bybit BTCUSDT 3m High Risk Rejected (UNPROVEN)
        SignalRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '3m',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'side' => 'BUY',
            'quality_class' => 'A',
            'signal_quality' => SignalQuality::High->value,
            'trade_risk' => TradeRiskClass::High->value,
            'retrospective_outcome' => 'UNPROVEN',
            'score' => 88,
            'entry_low' => '60200',
            'entry_high' => '60250',
            'stop_loss' => '58000',
            'decision_time' => $now->subMinutes(70),
            'candle_close_time' => $now->subMinutes(70),
            'expires_at' => $now->addHours(1),
            'maximum_holding_minutes' => 60,
            'schema_version' => '1.0.0',
            'indicator_snapshot' => ['rsi' => 70],
            'contract' => ['rules' => ['trend_filter' => true]],
            'reasons' => ['BREAKOUT'],
            'risks' => ['EXCESSIVE_STOP_DISTANCE'],
        ]);

        // 5. Bybit BTCUSDT 30m Open Trade (UNPROVEN)
        SignalRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '30m',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'side' => 'SELL',
            'quality_class' => 'A',
            'signal_quality' => SignalQuality::High->value,
            'trade_risk' => TradeRiskClass::Low->value,
            'retrospective_outcome' => 'UNPROVEN',
            'score' => 92,
            'entry_low' => '60500',
            'entry_high' => '60520',
            'stop_loss' => '61000',
            'decision_time' => $now->subMinutes(50),
            'candle_close_time' => $now->subMinutes(50),
            'expires_at' => $now->addHours(3),
            'maximum_holding_minutes' => 180,
            'schema_version' => '1.0.0',
            'indicator_snapshot' => ['rsi' => 60],
            'contract' => ['rules' => ['trend_filter' => true]],
            'reasons' => ['HEAD_AND_SHOULDERS'],
            'risks' => [],
        ]);

        // 6. Bybit BTCUSDT 60m Unknown Quality (UNPROVEN)
        SignalRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '60m',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'side' => 'BUY',
            'quality_class' => null,
            'signal_quality' => SignalQuality::Unknown->value,
            'trade_risk' => TradeRiskClass::Unknown->value,
            'retrospective_outcome' => 'UNPROVEN',
            'score' => 10,
            'entry_low' => '60400',
            'entry_high' => '60420',
            'stop_loss' => '59000',
            'decision_time' => $now->subMinutes(30),
            'candle_close_time' => $now->subMinutes(30),
            'expires_at' => $now->addHours(4),
            'maximum_holding_minutes' => 240,
            'schema_version' => '1.0.0',
            'indicator_snapshot' => [],
            'contract' => ['rules' => []],
            'reasons' => ['DATA_INCOMPLETE'],
            'risks' => [],
        ]);

        // 7. Binance ETHUSDT 15m Signal (UNPROVEN)
        SignalRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'exchange' => 'binance',
            'category' => 'linear',
            'symbol' => 'ETHUSDT',
            'timeframe' => '15m',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'side' => 'BUY',
            'quality_class' => 'A',
            'signal_quality' => SignalQuality::High->value,
            'trade_risk' => TradeRiskClass::Low->value,
            'retrospective_outcome' => 'UNPROVEN',
            'score' => 85,
            'entry_low' => '3010',
            'entry_high' => '3020',
            'stop_loss' => '2950',
            'decision_time' => $now->subMinutes(20),
            'candle_close_time' => $now->subMinutes(20),
            'expires_at' => $now->addHours(2),
            'maximum_holding_minutes' => 120,
            'schema_version' => '1.0.0',
            'indicator_snapshot' => ['rsi' => 30],
            'contract' => ['rules' => ['trend_filter' => true]],
            'reasons' => ['SUPPORT_BOUNCE'],
            'risks' => [],
        ]);

        // 8. Binance ETHUSDT 60m Signal (UNPROVEN)
        SignalRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'exchange' => 'binance',
            'category' => 'linear',
            'symbol' => 'ETHUSDT',
            'timeframe' => '60m',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'side' => 'SELL',
            'quality_class' => 'B',
            'signal_quality' => SignalQuality::Medium->value,
            'trade_risk' => TradeRiskClass::Low->value,
            'retrospective_outcome' => 'UNPROVEN',
            'score' => 75,
            'entry_low' => '3040',
            'entry_high' => '3050',
            'stop_loss' => '3100',
            'decision_time' => $now->subMinutes(10),
            'candle_close_time' => $now->subMinutes(10),
            'expires_at' => $now->addHours(5),
            'maximum_holding_minutes' => 300,
            'schema_version' => '1.0.0',
            'indicator_snapshot' => ['rsi' => 60],
            'contract' => ['rules' => ['trend_filter' => true]],
            'reasons' => ['TREND_CONTINUATION'],
            'risks' => [],
        ]);
    }
}
