<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\Exchanges\ExchangeRegistry;
use App\Exchanges\Infrastructure\Persistence\ExchangeRecord;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Application\PaperProcessingService;
use App\PaperTrading\Domain\PositionStatus;
use App\PaperTrading\Infrastructure\Persistence\MarketFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\RiskDecisionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Tests\Concerns\SeedsValidatedPaperEvidence;
use Tests\TestCase;

final class EndToEndSyntheticPaperLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use SeedsValidatedPaperEvidence;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'paper.fees.taker_rate' => '0.0006',
            'paper.slippage_rate' => '0.0002',
            'paper.portfolio.maximum_open_positions' => 5,
        ]);
        PaperAccountRecord::creating(function (PaperAccountRecord $account): void {
            if ($account->sizing_settings === null) {
                $account->sizing_settings = ['mode' => 'risk_percent'];
            }
        });
    }

    public function test_complete_synthetic_paper_lifecycle_from_candle_to_tp2_exit(): void
    {
        $registry = app(ExchangeRegistry::class);
        $this->assertCount(10, $registry->registeredIds());

        $t0 = CarbonImmutable::parse('2026-11-08 10:00:00', 'UTC');
        $tCandleClose = $t0->addMinutes(15);
        $tEntry = $tCandleClose->addMinutes(5);
        $tTp1 = $tEntry->addMinutes(5);
        $tFunding = $tTp1->addMinutes(5);
        $tTp2 = $tFunding->addMinutes(5);

        // 1. Ingest closed 15m candle
        $candle = CandleRecord::query()->create([
            'exchange' => 'okx',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '15',
            'open_time' => $t0,
            'open' => '65000.00',
            'high' => '65400.00',
            'low' => '64950.00',
            'close' => '65200.00',
            'volume' => '150.0',
            'turnover' => '9780000.00',
            'is_closed' => true,
        ]);

        // 2. Provision isolated PAPER account for OKX
        $account = PaperAccountRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'name' => 'OKX Baseline Account',
            'mode' => 'PAPER',
            'exchange_id' => 'okx',
            'strategy_id' => 'baseline',
            'status' => 'ACTIVE',
            'starting_balance' => '10000.00',
            'current_equity' => '10000.00',
            'peak_equity' => '10000.00',
            'reserved_risk' => '0.00',
            'realized_pnl' => '0.00',
            'unrealized_pnl' => '0.00',
            'created_at' => $t0->subHour(),
        ]);

        // 3. Generate BUY signal linked to closed candle with structured rule evidence
        $signalId = Uuid::uuid4()->toString();
        $rules = [
            ['rule' => 'hourly_adx_strength', 'passed' => true, 'operator' => '>=', 'actual' => 32.5, 'threshold' => 25.0, 'blocking' => false, 'category' => 'TREND_FILTER'],
            ['rule' => 'setup_alignment_30m', 'passed' => true, 'operator' => '==', 'actual' => ['direction' => 'BULLISH'], 'threshold' => ['direction' => 'BULLISH'], 'blocking' => false, 'category' => 'SETUP_FILTER'],
            ['rule' => 'confirmation_momentum_5m', 'passed' => true, 'operator' => '==', 'actual' => ['rsi' => 58.2], 'threshold' => ['rsi_midline' => 50.0], 'blocking' => false, 'category' => 'CONFIRMATION_FILTER'],
            ['rule' => 'atr_volatility_non_zero', 'passed' => true, 'operator' => '>', 'actual' => 450.0, 'threshold' => 0.0, 'blocking' => false, 'category' => 'VOLATILITY_FILTER'],
        ];

        $signal = SignalRecord::query()->create([
            'id' => $signalId,
            'exchange' => 'okx',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'candle_id' => $candle->id,
            'strategy_id' => 'baseline',
            'strategy_version' => '1.0.0',
            'timeframe' => '15',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 95,
            'maximum_holding_minutes' => 180,
            'entry_low' => '65150.00',
            'entry_high' => '65250.00',
            'stop_loss' => '64200.00',
            'take_profit_1' => '66200.00',
            'take_profit_2' => '67200.00',
            'risk_reward_1' => '1.00',
            'risk_reward_2' => '2.00',
            'model_risk_percent' => '0.50',
            'reasons' => ['bullish_hourly_trend', 'thirty_minute_setup', 'five_minute_confirmation'],
            'risks' => [],
            'indicator_snapshot' => ['15m' => ['atr_14' => '450', 'close' => '65200.00'], 'rules' => $rules],
            'schema_version' => '1.0.0',
            'contract' => ['rules' => $rules, 'schema_version' => '1.0.0'],
            'decision_time' => $tCandleClose,
            'candle_close_time' => $tCandleClose,
            'expires_at' => $tCandleClose->addHour(),
            'created_at' => $tCandleClose,
            'updated_at' => $tCandleClose,
        ]);

        $this->assertEquals($candle->id, $signal->candle_id);
        $this->seedSyntheticApprovalAndEvidence($signal);

        // 4. Market context at entry time
        MarketContextRecord::query()->create([
            'exchange' => 'okx',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'observed_at' => $tCandleClose,
            'bid' => '65200.00',
            'ask' => '65205.00',
            'turnover_24h' => '100000000.00',
            'min_order_qty' => '0.001',
            'max_order_qty' => '100.0',
            'qty_step' => '0.001',
            'tick_size' => '0.10',
            'min_notional_value' => '5.0',
            'funding_rate' => '0.0001',
            'open_interest' => '1000000',
            'open_interest_change' => '0.01',
            'created_at' => $tCandleClose,
            'updated_at' => $tCandleClose,
        ]);

        // Entry candle hitting entry zone (open at 10:15, closes at 10:20)
        $this->createCandle('okx', 'BTCUSDT', '5', $tCandleClose, '65180.00', '65220.00', '65170.00', '65200.00');

        // 5. Run paper processing for entry
        $processingService = app(PaperProcessingService::class);
        $processingService->process($account->id, 'BTCUSDT', $tEntry->toDateTimeImmutable());

        // Verify position opened
        $pos = VirtualPositionRecord::query()->where('paper_account_id', $account->id)->firstOrFail();
        $this->assertSame(PositionStatus::Open->value, $pos->status);
        $this->assertSame('okx', $pos->exchange_id);
        $this->assertSame($signalId, $pos->signal_id);
        $this->assertGreaterThan('0', (string) $pos->initial_quantity);
        $this->assertGreaterThan('0', (string) $pos->fees);

        // Verify risk decision recorded
        $riskDecision = RiskDecisionRecord::query()->where('signal_id', $signalId)->firstOrFail();
        $this->assertTrue($riskDecision->allowed);
        $this->assertSame('okx', $riskDecision->exchange_id);

        // 6. TP1 execution: price reaches 66250 (penetrates TP1 66200)
        $this->createCandle('okx', 'BTCUSDT', '5', $tEntry, '65200.00', '66300.00', '65190.00', '66250.00');
        $processingService->process($account->id, 'BTCUSDT', $tTp1->toDateTimeImmutable());

        $pos->refresh();
        $this->assertSame(PositionStatus::PartiallyClosed->value, $pos->status);
        $this->assertTrue(BigDecimal::of($pos->remaining_quantity)->isLessThan(BigDecimal::of($pos->initial_quantity)));

        // 7. Funding settlement event
        MarketFundingEventRecord::query()->create([
            'exchange' => 'okx',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'funding_at' => $tFunding,
            'rate' => '0.000100',
            'mark_price' => '66250.00',
        ]);
        // Intermediate candle between TP1 and Funding
        $this->createCandle('okx', 'BTCUSDT', '5', $tTp1, '66250.00', '66300.00', '66200.00', '66280.00');
        $processingService->process($account->id, 'BTCUSDT', $tFunding->toDateTimeImmutable());

        // 8. TP2 execution: price reaches 67300 (penetrates TP2 67200)
        $this->createCandle('okx', 'BTCUSDT', '5', $tFunding, '66280.00', '67400.00', '66200.00', '67300.00');
        $processingService->process($account->id, 'BTCUSDT', $tTp2->toDateTimeImmutable());

        $pos->refresh();
        $this->assertSame(PositionStatus::ClosedTp->value, $pos->status);
        $this->assertNotNull($pos->closed_at);
        $this->assertTrue(BigDecimal::of($pos->net_pnl)->isGreaterThan(BigDecimal::zero()));

        // Account equity reflects profit
        $account->refresh();
        $this->assertTrue(BigDecimal::of($account->current_equity)->isGreaterThan(BigDecimal::of('10000.00')));
        $this->assertTrue(BigDecimal::of($account->reserved_risk)->isZero());

        // 9. Verify Strategy Monitor API reflects the entire trace
        $monitorRes = $this->getJson('/api/v1/strategy-monitor?exchange=okx');
        $monitorRes->assertOk();
        $monitorRes->assertJsonPath('schema_version', '1.0.0');
        $signalsData = $monitorRes->json('data.signals.data');
        $this->assertNotEmpty($signalsData);
        $monitorSignal = collect($signalsData)->firstWhere('id', $signalId);
        $this->assertNotNull($monitorSignal);
        $this->assertSame('okx', $monitorSignal['exchange']);
        $this->assertSame('BUY', $monitorSignal['side']);
        $this->assertNotEmpty($monitorSignal['rules']);

        // 10. Verify Chronological Timeline API
        $timelineRes = $this->getJson("/api/v1/strategy-monitor/timeline?signal_id={$signalId}");
        $timelineRes->assertOk();
        $stages = collect($timelineRes->json('data.timeline'))->pluck('stage')->all();
        $this->assertContains('CANDLE_CLOSED', $stages);
        $this->assertContains('STRATEGY_EVALUATED', $stages);
        $this->assertContains('SIGNAL_GENERATED', $stages);
        $this->assertContains('RISK_DECISION', $stages);
    }

    public function test_skip_scenario_records_structured_rules_and_creates_no_position(): void
    {
        $t0 = CarbonImmutable::parse('2026-11-08 14:00:00', 'UTC');

        // Observation for SKIP
        $obsId = Uuid::uuid4()->toString();
        $rules = [
            ['rule' => 'hourly_adx_strength', 'passed' => false, 'operator' => '>=', 'actual' => 18.2, 'threshold' => 25.0, 'blocking' => true, 'category' => 'TREND_FILTER'],
            ['rule' => 'atr_volatility_non_zero', 'passed' => true, 'operator' => '>', 'actual' => 380.0, 'threshold' => 0.0, 'blocking' => false, 'category' => 'VOLATILITY_FILTER'],
        ];

        StrategyObservationRecord::query()->create([
            'id' => $obsId,
            'exchange' => 'bitget',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'strategy_id' => 'baseline',
            'strategy_version' => '1.0.0',
            'configuration_hash' => hash('sha256', 'test-config'),
            'side' => 'SKIP',
            'score' => 0,
            'decision_time' => $t0,
            'reasons' => ['hourly_adx_strength_below_threshold'],
            'analysis' => [
                'rules' => $rules,
                'diagnostics' => ['blocking_condition' => 'hourly_adx_strength_below_threshold'],
            ],
        ]);

        $res = $this->getJson('/api/v1/strategy-monitor?exchange=bitget&side=SKIP');
        $res->assertOk();
        $data = $res->json('data.signals.data');
        $this->assertNotEmpty($data);
        $skipItem = collect($data)->firstWhere('id', $obsId);
        $this->assertNotNull($skipItem);
        $this->assertSame('SKIP', $skipItem['side']);
        $this->assertFalse($skipItem['rules'][0]['passed']);
        $this->assertTrue($skipItem['rules'][0]['blocking']);

        // Zero positions created
        $this->assertSame(0, VirtualPositionRecord::query()->where('exchange_id', 'bitget')->count());
    }

    public function test_risk_rejection_scenario_records_rejection_without_order_placement(): void
    {
        $t0 = CarbonImmutable::parse('2026-11-08 15:00:00', 'UTC');

        $account = PaperAccountRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'name' => 'Gate Account',
            'mode' => 'PAPER',
            'exchange_id' => 'gate',
            'strategy_id' => 'baseline',
            'status' => 'ACTIVE',
            'starting_balance' => '10000.00',
            'current_equity' => '10000.00',
            'peak_equity' => '10000.00',
            'reserved_risk' => '0.00',
            'realized_pnl' => '0.00',
            'unrealized_pnl' => '0.00',
            'created_at' => $t0->subHour(),
        ]);

        $signalId = Uuid::uuid4()->toString();
        SignalRecord::query()->create([
            'id' => $signalId,
            'exchange' => 'gate',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'strategy_id' => 'baseline',
            'strategy_version' => '1.0.0',
            'timeframe' => '15m',
            'side' => 'BUY',
            'quality_class' => 'B',
            'score' => 75,
            'maximum_holding_minutes' => 120,
            'entry_low' => '65000.00',
            'entry_high' => '65100.00',
            'stop_loss' => '64000.00',
            'take_profit_1' => '66500.00',
            'take_profit_2' => '67500.00',
            'risk_reward_1' => '1.50',
            'risk_reward_2' => '2.50',
            'model_risk_percent' => '0.0100',
            'reasons' => ['pullback'],
            'risks' => [],
            'indicator_snapshot' => [],
            'schema_version' => '1.0.0',
            'contract' => ['schema_version' => '1.0.0'],
            'decision_time' => $t0,
            'candle_close_time' => $t0,
            'expires_at' => $t0->addHour(),
        ]);

        // Record risk rejection (e.g. max positions exceeded)
        RiskDecisionRecord::query()->create([
            'paper_account_id' => $account->id,
            'exchange_id' => 'gate',
            'signal_id' => $signalId,
            'allowed' => false,
            'risk_version' => '1.0.0',
            'reasons' => ['maximum_open_positions_exceeded'],
            'snapshot' => ['open_positions' => 5, 'max_allowed' => 5],
            'decided_at' => $t0->addSeconds(1),
        ]);

        // Verify monitor shows REJECTED status
        $res = $this->getJson('/api/v1/strategy-monitor?exchange=gate');
        $res->assertOk();
        $signals = $res->json('data.signals.data');
        $sig = collect($signals)->firstWhere('id', $signalId);
        $this->assertNotNull($sig);
        $this->assertFalse($sig['risk_decision']['allowed']);
        $this->assertContains('maximum_open_positions_exceeded', $sig['risk_decision']['reasons']);
        $this->assertNull($sig['position']);
    }

    public function test_stop_loss_scenario_exits_position_and_returns_risk_capital(): void
    {
        $t0 = CarbonImmutable::parse('2026-11-08 16:00:00', 'UTC');
        $tEntry = $t0->addMinutes(5);
        $tSl = $tEntry->addMinutes(5);

        $account = PaperAccountRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'name' => 'KuCoin Account',
            'mode' => 'PAPER',
            'exchange_id' => 'kucoin',
            'strategy_id' => 'baseline',
            'status' => 'ACTIVE',
            'starting_balance' => '10000.00',
            'current_equity' => '10000.00',
            'peak_equity' => '10000.00',
            'reserved_risk' => '0.00',
            'realized_pnl' => '0.00',
            'unrealized_pnl' => '0.00',
            'created_at' => $t0->subHour(),
        ]);

        $signalId = Uuid::uuid4()->toString();
        $signal = SignalRecord::query()->create([
            'id' => $signalId,
            'exchange' => 'kucoin',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'strategy_id' => 'baseline',
            'strategy_version' => '1.0.0',
            'timeframe' => '15',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 90,
            'maximum_holding_minutes' => 120,
            'entry_low' => '65000.00',
            'entry_high' => '65100.00',
            'stop_loss' => '64500.00',
            'take_profit_1' => '66000.00',
            'take_profit_2' => '67000.00',
            'risk_reward_1' => '2.00',
            'risk_reward_2' => '4.00',
            'model_risk_percent' => '0.50',
            'reasons' => ['momentum'],
            'risks' => [],
            'indicator_snapshot' => ['15m' => ['atr_14' => '300', 'close' => '65050.00']],
            'schema_version' => '1.0.0',
            'contract' => ['schema_version' => '1.0.0'],
            'decision_time' => $t0,
            'candle_close_time' => $t0,
            'expires_at' => $t0->addHour(),
            'created_at' => $t0,
            'updated_at' => $t0,
        ]);

        $this->seedSyntheticApprovalAndEvidence($signal);

        MarketContextRecord::query()->create([
            'exchange' => 'kucoin',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'observed_at' => $t0,
            'bid' => '65050.00',
            'ask' => '65055.00',
            'turnover_24h' => '100000000.00',
            'min_order_qty' => '0.001',
            'max_order_qty' => '100.0',
            'qty_step' => '0.001',
            'tick_size' => '0.10',
            'min_notional_value' => '5.0',
            'funding_rate' => '0.0001',
            'open_interest' => '1000000',
            'open_interest_change' => '0.01',
            'created_at' => $t0,
            'updated_at' => $t0,
        ]);

        // Entry candle
        $this->createCandle('kucoin', 'BTCUSDT', '5', $t0, '65020.00', '65080.00', '65010.00', '65050.00');

        $processingService = app(PaperProcessingService::class);
        $processingService->process($account->id, 'BTCUSDT', $tEntry->toDateTimeImmutable());

        $pos = VirtualPositionRecord::query()->where('paper_account_id', $account->id)->firstOrFail();
        $this->assertSame(PositionStatus::Open->value, $pos->status);

        // SL candle: price drops to 64400 (penetrates stop loss 64500)
        $this->createCandle('kucoin', 'BTCUSDT', '5', $tEntry, '65050.00', '65060.00', '64350.00', '64400.00');
        $processingService->process($account->id, 'BTCUSDT', $tSl->toDateTimeImmutable());

        $pos->refresh();
        $this->assertSame(PositionStatus::ClosedSl->value, $pos->status);
        $this->assertTrue(BigDecimal::of($pos->net_pnl)->isNegative());

        // Account reserved risk returned to zero
        $account->refresh();
        $this->assertTrue(BigDecimal::of($account->reserved_risk)->isZero());
    }

    private function seedSyntheticApprovalAndEvidence(SignalRecord $signal): void
    {
        // Test-local approval and execution assumptions are not venue readiness evidence.
        ExchangeRecord::query()->findOrFail($signal->exchange)->update([
            'paper_approved' => true,
            'paper_approved_at' => $signal->decision_time,
            'lifecycle_status' => 'ACTIVE_PAPER',
            'paper_execution_assumptions' => ['execution_model' => 'NEXT_CANDLE_OPEN'],
        ]);
        $this->seedValidatedPaperEvidence($signal);
    }

    private function createCandle(string $exchange, string $symbol, string $interval, CarbonImmutable $openTime, string $open, string $high, string $low, string $close): CandleRecord
    {
        return CandleRecord::query()->create([
            'exchange' => $exchange,
            'category' => 'linear',
            'symbol' => $symbol,
            'interval' => $interval,
            'open_time' => $openTime,
            'open' => $open,
            'high' => $high,
            'low' => $low,
            'close' => $close,
            'volume' => '50.0',
            'turnover' => '3250000.00',
            'is_closed' => true,
        ]);
    }
}
