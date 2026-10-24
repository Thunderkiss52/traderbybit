<?php

declare(strict_types=1);

namespace Tests\Feature\Strategy;

use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Application\PaperProcessingService;
use App\PaperTrading\Domain\PositionStatus;
use App\PaperTrading\Infrastructure\Persistence\RiskDecisionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Contracts\SignalRepository;
use App\Strategy\Domain\SignalSide;
use App\Strategy\Domain\StrategyInput;
use App\Strategy\Domain\TimeframeState;
use App\Strategy\Infrastructure\Persistence\LevelInteractionForecastRecord;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Fixtures\Strategy\StrategyFixture;
use Tests\TestCase;

final class SupportResistanceStrategiesTest extends TestCase
{
    use RefreshDatabase;

    private DateTimeImmutable $at;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at = new DateTimeImmutable('2026-10-13T10:15:00Z');
    }

    public function test_all_primary_accounts_exist_and_are_isolated(): void
    {
        $accountService = $this->app->make(PaperAccountService::class);
        $accounts = $accountService->allPrimaryAccounts();

        self::assertCount(13, $accounts);

        $expectedStrategies = [
            'baseline',
            'trend_pullback',
            'bollinger_mean_reversion',
            'donchian_atr_breakout',
            'regime_switching',
            'momentum',
            'support_resistance_bounce',
            'support_resistance_breakout',
            'funding_open_interest_squeeze',
            'liquidity_sweep_reversal',
            'volatility_compression_breakout',
            'btc_eth_lead_lag',
            'multi_timeframe_trend_following',
        ];

        $strategyIds = $accounts->pluck('primary_strategy_id')->all();
        self::assertSame($expectedStrategies, $strategyIds);

        foreach ($accounts as $acc) {
            self::assertSame('ACTIVE', $acc->status);
            self::assertTrue(BigDecimal::of((string) $acc->starting_balance)->isEqualTo(10000));
            self::assertTrue(BigDecimal::of((string) $acc->current_equity)->isEqualTo(10000));
            self::assertTrue((bool) $acc->is_primary);
        }
    }

    public function test_sr_bounce_and_breakout_skip_when_sample_size_is_under_thirty(): void
    {
        $registry = $this->app->make(StrategyRegistry::class);
        $input = StrategyFixture::input();

        $bounceStrategy = $registry->get('support_resistance_bounce');
        $breakoutStrategy = $registry->get('support_resistance_breakout');

        $bounceDecision = $bounceStrategy->evaluate($input);
        $breakoutDecision = $breakoutStrategy->evaluate($input);

        self::assertSame(SignalSide::Skip, $breakoutDecision->side);
        self::assertContains('level_probability_insufficient_data', $breakoutDecision->reasons);

        self::assertSame(SignalSide::Skip, $bounceDecision->side);
        self::assertContains('no_support_resistance_bounce_trigger', $bounceDecision->reasons);
    }

    public function test_sr_breakout_emits_buy_with_sufficient_resolved_forecasts(): void
    {
        $this->seedForecasts('resistance_breakout', 'BUY', successCount: 28, failureCount: 7);

        $registry = $this->app->make(StrategyRegistry::class);
        $input = StrategyFixture::input();

        $breakoutStrategy = $registry->get('support_resistance_breakout');
        $decision = $breakoutStrategy->evaluate($input);

        self::assertSame(SignalSide::Buy, $decision->side);
        self::assertContains('resistance_breakout', $decision->reasons);
        self::assertContains('positive_expected_value_after_costs', $decision->reasons);
        self::assertNotNull($decision->pricePlan);
        self::assertGreaterThanOrEqual(70, $decision->score);
    }

    public function test_sr_bounce_emits_buy_on_support_rejection_with_sufficient_data(): void
    {
        $this->seedForecasts('support_rejection', 'BUY', successCount: 28, failureCount: 7);

        $input = $this->createBounceInput();

        $registry = $this->app->make(StrategyRegistry::class);
        $bounceStrategy = $registry->get('support_resistance_bounce');
        $decision = $bounceStrategy->evaluate($input);

        self::assertSame(SignalSide::Buy, $decision->side);
        self::assertContains('support_rejection', $decision->reasons);
        self::assertContains('positive_expected_value_after_costs', $decision->reasons);
    }

    public function test_sr_signals_execute_in_isolated_strategy_paper_account(): void
    {
        $accountService = $this->app->make(PaperAccountService::class);
        $breakoutAccount = $accountService->primaryAccount('support_resistance_breakout');
        $breakoutAccount->update(['created_at' => $this->at->modify('-1 hour')]);

        $this->seedForecasts('resistance_breakout', 'BUY', 28, 7);
        $input = StrategyFixture::input();

        $decision = $this->app->make(StrategyRegistry::class)->get('support_resistance_breakout')->evaluate($input);
        $signal = $this->app->make(SignalRepository::class)->createIfAbsent($decision);

        // Ensure signal created_at is at decision_time so causality checks pass
        SignalRecord::query()->where('id', $signal->id)->update([
            'created_at' => $this->at,
            'decision_time' => $this->at,
        ]);

        $this->seedContext('BTCUSDT', '10:15');
        $this->seedCandle('10:15', '103.2', '104.5', '103.0', '103.5');

        $processor = $this->app->make(PaperProcessingService::class);
        $processor->process(accountId: $breakoutAccount->id, symbol: 'BTCUSDT', at: $this->at->modify('+10 minutes'));

        $position = VirtualPositionRecord::query()->where('paper_account_id', $breakoutAccount->id)->first();
        self::assertNotNull($position);
        self::assertSame('BTCUSDT', $position->symbol);
        self::assertSame('BUY', $position->side);
        self::assertSame(PositionStatus::Open->value, $position->status);
    }

    public function test_one_way_position_conflict_rejects_opposite_signal(): void
    {
        $accountService = $this->app->make(PaperAccountService::class);
        $account = $accountService->primaryAccount('baseline');
        $account->update(['created_at' => $this->at->modify('-1 hour')]);

        $initialBuySignal = SignalRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 85,
            'decision_time' => $this->at->modify('-15 minutes'),
            'candle_close_time' => $this->at->modify('-15 minutes'),
            'entry_low' => '100',
            'entry_high' => '101',
            'stop_loss' => '95',
            'take_profit_1' => '110',
            'take_profit_2' => '115',
            'risk_reward_1' => '2.0',
            'model_risk_percent' => '0.50',
            'expires_at' => $this->at->modify('+15 minutes'),
            'maximum_holding_minutes' => 240,
            'reasons' => ['support_bounce'],
            'risks' => [],
            'indicator_snapshot' => [],
            'contract' => ['research_mode' => true],
            'strategy_version' => '1.0.0',
            'schema_version' => '1.0.0',
            'strategy_id' => 'baseline',
            'created_at' => $this->at->modify('-15 minutes'),
        ]);

        VirtualPositionRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'paper_account_id' => $account->id,
            'signal_id' => $initialBuySignal->id,
            'symbol' => 'BTCUSDT',
            'side' => 'BUY',
            'quality_class' => 'A',
            'status' => PositionStatus::Open->value,
            'initial_quantity' => '0.1',
            'remaining_quantity' => '0.1',
            'qty_step' => '0.001',
            'risk_capital' => '50',
            'entry_price' => '100',
            'stop_loss' => '95',
            'take_profit_1' => '110',
            'take_profit_2' => '115',
            'opened_at' => $this->at,
            'maximum_holding_until' => $this->at->modify('+4 hours'),
            'execution_model' => 'BAR_INTRABAR',
            'strategy_version' => '1.0.0',
            'risk_version' => '1.0.0',
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);

        $sellSignal = SignalRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15',
            'side' => 'SELL',
            'quality_class' => 'A',
            'score' => 85,
            'decision_time' => $this->at,
            'candle_close_time' => $this->at,
            'entry_low' => '99',
            'entry_high' => '100',
            'stop_loss' => '105',
            'take_profit_1' => '90',
            'risk_reward_1' => '2.0',
            'model_risk_percent' => '0.50',
            'expires_at' => $this->at->modify('+30 minutes'),
            'maximum_holding_minutes' => 240,
            'reasons' => ['resistance_rejection'],
            'risks' => [],
            'indicator_snapshot' => [],
            'contract' => ['research_mode' => true],
            'strategy_version' => '1.0.0',
            'schema_version' => '1.0.0',
            'strategy_id' => 'baseline',
            'created_at' => $this->at,
        ]);

        $this->seedContext('BTCUSDT', '10:15');
        $this->seedCandle('10:15', '100', '101', '99', '99.5');

        $processor = $this->app->make(PaperProcessingService::class);
        $processor->process(accountId: $account->id, symbol: 'BTCUSDT', at: $this->at);

        $riskDecision = RiskDecisionRecord::query()->where('signal_id', $sellSignal->id)->first();
        self::assertNotNull($riskDecision);
        self::assertFalse($riskDecision->allowed);
        self::assertContains('position_conflict', $riskDecision->reasons);
    }

    private function seedForecasts(string $event, string $side, int $successCount, int $failureCount): void
    {
        $at = new DateTimeImmutable('2026-10-13T10:15:00Z');
        $total = $successCount + $failureCount;
        for ($i = 0; $i < $total; $i++) {
            $triggered = $at->modify('-'.($total + 5 - $i).' hours');
            $outcome = $i < $successCount ? 'SUCCESS' : 'FAILURE';

            LevelInteractionForecastRecord::query()->create([
                'id' => Uuid::uuid4()->toString(),
                'exchange' => 'bybit',
                'category' => 'linear',
                'symbol' => 'BTCUSDT',
                'timeframe' => '15',
                'event' => $event,
                'side' => $side,
                'triggered_at' => $triggered,
                'horizon_ends_at' => $triggered->modify('+3 hours'),
                'model_version' => '1.0.0',
                'configuration_hash' => str_repeat('a', 64),
                'sample_size' => 0,
                'validation_status' => 'INSUFFICIENT_DATA',
                'outcome' => $outcome,
                'outcome_at' => $triggered->modify('+1 hour'),
                'features' => [
                    'reference_price' => '100',
                    'atr' => '1',
                ],
            ]);
        }
    }

    private function createBounceInput(): StrategyInput
    {
        $entryPrev = StrategyFixture::bar('2026-10-13T09:45:00Z', 15, [
            'close' => '101',
            'support' => '100',
            'resistance' => null,
            'atr' => '1',
            'emaEntry' => '100',
        ]);

        $entryNow = StrategyFixture::bar('2026-10-13T10:00:00Z', 15, [
            'open' => '100.2',
            'close' => '101.5',
            'low' => '100.1',
            'high' => '102.0',
            'support' => '100',
            'resistance' => null,
            'atr' => '1',
            'rsi' => '55',
            'macdHistogram' => '0.2',
            'relativeVolume' => '1.5',
            'emaEntry' => '101',
        ]);

        return StrategyFixture::input(SignalSide::Buy, [
            'entry' => new TimeframeState('15', [$entryPrev, $entryNow], true),
        ]);
    }

    private function seedContext(string $symbol, string $time): void
    {
        MarketContextRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => $symbol,
            'observed_at' => new DateTimeImmutable("2026-10-13T{$time}:00Z"),
            'bid' => '103.1',
            'ask' => '103.2',
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

    private function seedCandle(string $time, string $open, string $high, string $low, string $close, string $symbol = 'BTCUSDT'): void
    {
        DB::table('market_candles')->insert([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => $symbol,
            'interval' => '5',
            'open_time' => new DateTimeImmutable("2026-10-13T{$time}:00Z"),
            'open' => $open,
            'high' => $high,
            'low' => $low,
            'close' => $close,
            'volume' => '100',
            'turnover' => '10000',
            'is_closed' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
