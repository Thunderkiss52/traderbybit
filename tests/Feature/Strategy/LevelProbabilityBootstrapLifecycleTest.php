<?php

declare(strict_types=1);

namespace Tests\Feature\Strategy;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\Strategy\Application\LevelInteractionAnalyzer;
use App\Strategy\Application\LevelProbabilityService;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Domain\MarketContext;
use App\Strategy\Domain\SignalSide;
use App\Strategy\Domain\StrategyInput;
use App\Strategy\Domain\TimeframeState;
use App\Strategy\Infrastructure\Persistence\LevelInteractionForecastRecord;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Tests\Fixtures\Strategy\StrategyFixture;
use Tests\TestCase;

final class LevelProbabilityBootstrapLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private DateTimeImmutable $t0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t0 = new DateTimeImmutable('2026-10-15T10:00:00Z');
    }

    public function test_forecast_creation_is_autonomous_and_decoupled_from_strategy_execution(): void
    {
        $levels = $this->app->make(LevelInteractionAnalyzer::class);
        $probabilities = $this->app->make(LevelProbabilityService::class);
        $registry = $this->app->make(StrategyRegistry::class);

        $input = $this->createBounceInput('binance', $this->t0);

        // Analyze price interaction
        $interaction = $levels->analyze($input);
        self::assertSame('support_rejection', $interaction['selected_event']);
        self::assertSame('BUY', $interaction['selected_side']);

        // Record forecast (occurs before strategy evaluation in pipeline)
        $forecastData = $probabilities->record($input, $interaction);
        self::assertNotNull($forecastData);
        self::assertSame(0, $forecastData['sample_size']);
        self::assertSame('INSUFFICIENT_DATA', $forecastData['validation_status']);

        // Verify the forecast record is persisted in DB with outcome = NULL
        $storedForecast = LevelInteractionForecastRecord::query()->where('exchange', 'binance')->first();
        self::assertNotNull($storedForecast);
        self::assertSame('support_rejection', $storedForecast->event);
        self::assertSame('BUY', $storedForecast->side);
        self::assertNull($storedForecast->outcome);
        self::assertNull($storedForecast->outcome_at);
        self::assertSame('2026-10-15 13:00:00.000+00:00', $storedForecast->horizon_ends_at->format('Y-m-d H:i:s.vP'));

        // Strategy evaluates and SKIPS due to insufficient data (< 30)
        $bounceStrategy = $registry->get('support_resistance_bounce');
        $decision = $bounceStrategy->evaluate($input);
        self::assertSame(SignalSide::Skip, $decision->side);
        self::assertContains('level_probability_insufficient_data', $decision->reasons);

        // Crucial invariant: The forecast persists even though the strategy SKIPPED and NO orders/positions were created
        self::assertDatabaseCount('level_interaction_forecasts', 1);
        self::assertDatabaseCount('virtual_positions', 0);
    }

    public function test_outcome_maturation_occurs_via_price_bars_without_trading_activity(): void
    {
        $probabilities = $this->app->make(LevelProbabilityService::class);

        $input = $this->createBounceInput('binance', $this->t0);

        // 1. Create initial forecast at t0 (10:00:00 UTC) with reference_price=100.0, atr=1.0, side=BUY
        LevelInteractionForecastRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'exchange' => 'binance',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15',
            'event' => 'support_rejection',
            'side' => 'BUY',
            'triggered_at' => $this->t0,
            'horizon_ends_at' => $this->t0->modify('+3 hours'), // 13:00:00
            'model_version' => '0.9.0',
            'configuration_hash' => str_repeat('a', 64),
            'sample_size' => 0,
            'validation_status' => 'INSUFFICIENT_DATA',
            'outcome' => null,
            'outcome_at' => null,
            'features' => ['reference_price' => '100', 'atr' => '1'],
        ]);

        self::assertDatabaseCount('level_interaction_forecasts', 1);
        self::assertNull(LevelInteractionForecastRecord::query()->first()->outcome);

        // 2. Seed future 15m closed price bars between 10:00 and 13:00
        // Target for BUY: reference + 1.0 * ATR = 100 + 1.0 = 101.0
        // In bar at 10:45, high reaches 101.5 (hits target -> SUCCESS)
        $this->seedCandles('binance', 'BTCUSDT', [
            ['10:00', '100.2', '100.8', '99.8', '100.5'],
            ['10:15', '100.5', '100.9', '100.1', '100.7'],
            ['10:30', '100.7', '100.9', '100.4', '100.6'],
            ['10:45', '100.6', '101.5', '100.5', '101.2'], // SUCCESS hit here
            ['11:00', '101.2', '101.8', '101.0', '101.5'],
        ]);

        // 3. At 13:15 (after horizon_ends_at 13:00), an observation occurs
        $futureInput = $this->createBounceInput('binance', $this->t0->modify('+3 hours 15 minutes'));

        // Calling record() triggers resolveMatured() at the start
        $probabilities->record($futureInput, []);

        // 4. Verify outcome transitioned from NULL to SUCCESS autonomously
        $maturedForecast = LevelInteractionForecastRecord::query()->where('triggered_at', $this->t0)->first();
        self::assertNotNull($maturedForecast);
        self::assertSame('SUCCESS', $maturedForecast->outcome);
        self::assertSame('2026-10-15 11:00:00.000+00:00', $maturedForecast->outcome_at->format('Y-m-d H:i:s.vP'));

        // 5. Verify sample_size progresses from 0 to 1 for subsequent forecasts
        $estimate = $probabilities->estimateFor($futureInput, 'support_rejection', []);
        self::assertSame(1, $estimate['sample_size']);
        self::assertSame('INSUFFICIENT_DATA', $estimate['validation_status']);
    }

    public function test_statistical_gating_transitions_to_active_trading_at_thirty_samples(): void
    {
        $probabilities = $this->app->make(LevelProbabilityService::class);
        $registry = $this->app->make(StrategyRegistry::class);

        $input = $this->createBounceInput('binance', $this->t0);

        // Seed 29 prior matured forecasts (all resolved before t0)
        for ($i = 0; $i < 29; $i++) {
            $triggered = $this->t0->modify('-'.(35 - $i).' hours');
            LevelInteractionForecastRecord::query()->create([
                'id' => Uuid::uuid4()->toString(),
                'exchange' => 'binance',
                'category' => 'linear',
                'symbol' => 'BTCUSDT',
                'timeframe' => '15',
                'event' => 'support_rejection',
                'side' => 'BUY',
                'triggered_at' => $triggered,
                'horizon_ends_at' => $triggered->modify('+3 hours'),
                'model_version' => '0.9.0',
                'configuration_hash' => str_repeat('a', 64),
                'sample_size' => $i,
                'validation_status' => 'INSUFFICIENT_DATA',
                'outcome' => $i < 20 ? 'SUCCESS' : 'FAILURE',
                'outcome_at' => $triggered->modify('+1 hour'),
                'features' => ['reference_price' => '100', 'atr' => '1'],
            ]);
        }

        // At sample size = 29, strategy MUST still SKIP
        $estimate29 = $probabilities->estimateFor($input, 'support_rejection', []);
        self::assertSame(29, $estimate29['sample_size']);
        self::assertSame('INSUFFICIENT_DATA', $estimate29['validation_status']);

        $bounceStrategy = $registry->get('support_resistance_bounce');
        $decision29 = $bounceStrategy->evaluate($input);
        self::assertSame(SignalSide::Skip, $decision29->side);
        self::assertContains('level_probability_insufficient_data', $decision29->reasons);

        // Add the 30th matured forecast
        $triggered30 = $this->t0->modify('-4 hours');
        LevelInteractionForecastRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'exchange' => 'binance',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15',
            'event' => 'support_rejection',
            'side' => 'BUY',
            'triggered_at' => $triggered30,
            'horizon_ends_at' => $triggered30->modify('+3 hours'),
            'model_version' => '0.9.0',
            'configuration_hash' => str_repeat('a', 64),
            'sample_size' => 29,
            'validation_status' => 'INSUFFICIENT_DATA',
            'outcome' => 'SUCCESS',
            'outcome_at' => $triggered30->modify('+1 hour'),
            'features' => ['reference_price' => '100', 'atr' => '1'],
        ]);

        // At sample size = 30, validation_status transitions to RESEARCH_BASELINE
        $estimate30 = $probabilities->estimateFor($input, 'support_rejection', []);
        self::assertSame(30, $estimate30['sample_size']);
        self::assertSame('RESEARCH_BASELINE', $estimate30['validation_status']);
        self::assertNotNull($estimate30['success_probability']);

        // Now strategy evaluates math expectancy and EMITS BUY
        $decision30 = $bounceStrategy->evaluate($input);
        self::assertSame(SignalSide::Buy, $decision30->side);
        self::assertContains('support_rejection', $decision30->reasons);
        self::assertContains('positive_expected_value_after_costs', $decision30->reasons);
    }

    public function test_exchange_isolation_prevents_cross_contamination_of_sample_populations(): void
    {
        $probabilities = $this->app->make(LevelProbabilityService::class);
        $registry = $this->app->make(StrategyRegistry::class);

        // Seed 35 matured forecasts strictly on BYBIT
        for ($i = 0; $i < 35; $i++) {
            $triggered = $this->t0->modify('-'.(40 - $i).' hours');
            LevelInteractionForecastRecord::query()->create([
                'id' => Uuid::uuid4()->toString(),
                'exchange' => 'bybit',
                'category' => 'linear',
                'symbol' => 'BTCUSDT',
                'timeframe' => '15',
                'event' => 'support_rejection',
                'side' => 'BUY',
                'triggered_at' => $triggered,
                'horizon_ends_at' => $triggered->modify('+3 hours'),
                'model_version' => '0.9.0',
                'configuration_hash' => str_repeat('a', 64),
                'sample_size' => $i,
                'validation_status' => 'INSUFFICIENT_DATA',
                'outcome' => 'SUCCESS',
                'outcome_at' => $triggered->modify('+1 hour'),
                'features' => ['reference_price' => '100', 'atr' => '1'],
            ]);
        }

        // Seed only 5 matured forecasts on BINANCE
        for ($i = 0; $i < 5; $i++) {
            $triggered = $this->t0->modify('-'.(10 - $i).' hours');
            LevelInteractionForecastRecord::query()->create([
                'id' => Uuid::uuid4()->toString(),
                'exchange' => 'binance',
                'category' => 'linear',
                'symbol' => 'BTCUSDT',
                'timeframe' => '15',
                'event' => 'support_rejection',
                'side' => 'BUY',
                'triggered_at' => $triggered,
                'horizon_ends_at' => $triggered->modify('+3 hours'),
                'model_version' => '0.9.0',
                'configuration_hash' => str_repeat('a', 64),
                'sample_size' => $i,
                'validation_status' => 'INSUFFICIENT_DATA',
                'outcome' => 'SUCCESS',
                'outcome_at' => $triggered->modify('+1 hour'),
                'features' => ['reference_price' => '100', 'atr' => '1'],
            ]);
        }

        $bybitInput = $this->createBounceInput('bybit', $this->t0);
        $binanceInput = $this->createBounceInput('binance', $this->t0);

        // Bybit has 35 samples -> RESEARCH_BASELINE -> BUY
        $bybitEstimate = $probabilities->estimateFor($bybitInput, 'support_rejection', []);
        self::assertSame(35, $bybitEstimate['sample_size']);
        self::assertSame('RESEARCH_BASELINE', $bybitEstimate['validation_status']);

        $bounceStrategy = $registry->get('support_resistance_bounce');
        $bybitDecision = $bounceStrategy->evaluate($bybitInput);
        self::assertSame(SignalSide::Buy, $bybitDecision->side);

        // Binance has 5 samples -> INSUFFICIENT_DATA -> SKIP
        $binanceEstimate = $probabilities->estimateFor($binanceInput, 'support_rejection', []);
        self::assertSame(5, $binanceEstimate['sample_size']);
        self::assertSame('INSUFFICIENT_DATA', $binanceEstimate['validation_status']);

        $binanceDecision = $bounceStrategy->evaluate($binanceInput);
        self::assertSame(SignalSide::Skip, $binanceDecision->side);
        self::assertContains('level_probability_insufficient_data', $binanceDecision->reasons);
    }

    public function test_forecast_accumulation_proceeds_identically_when_paper_execution_is_unapproved(): void
    {
        // Explicitly set execution unapproved (the codebase local default)
        config(['exchanges.binance.paper_execution_approved' => false]);

        $levels = $this->app->make(LevelInteractionAnalyzer::class);
        $probabilities = $this->app->make(LevelProbabilityService::class);

        $input = $this->createBounceInput('binance', $this->t0);

        // Analyze and record forecast
        $interaction = $levels->analyze($input);
        $forecast = $probabilities->record($input, $interaction);

        self::assertNotNull($forecast);
        self::assertSame('INSUFFICIENT_DATA', $forecast['validation_status']);
        self::assertSame(0, $forecast['sample_size']);

        // Assert record exists in DB
        self::assertDatabaseHas('level_interaction_forecasts', [
            'exchange' => 'binance',
            'symbol' => 'BTCUSDT',
            'event' => 'support_rejection',
            'side' => 'BUY',
        ]);
    }

    private function createBounceInput(string $exchange, DateTimeImmutable $at): StrategyInput
    {
        $entryPrev = StrategyFixture::bar($at->modify('-30 minutes')->format(DATE_ATOM), 15, [
            'close' => '101',
            'support' => '100',
            'resistance' => null,
            'atr' => '1',
            'emaEntry' => '100',
        ]);

        $entryNow = StrategyFixture::bar($at->modify('-15 minutes')->format(DATE_ATOM), 15, [
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

        $base = StrategyFixture::input();
        $context = new MarketContext($at, '101.4', '101.5', '100000000', '0.1', '0.0001', '1000000', '0.01');

        return new StrategyInput(
            exchange: $exchange,
            category: 'linear',
            symbol: 'BTCUSDT',
            decisionTime: $at,
            trend: $base->trend,
            setup: $base->setup,
            entry: new TimeframeState('15', [$entryPrev, $entryNow], true),
            confirmation: $base->confirmation,
            marketContext: $context,
            validationErrors: [],
        );
    }

    private function seedCandles(string $exchange, string $symbol, array $bars): void
    {
        foreach ($bars as $bar) {
            [$time, $open, $high, $low, $close] = $bar;
            CandleRecord::query()->create([
                'exchange' => $exchange,
                'category' => 'linear',
                'symbol' => $symbol,
                'interval' => '15',
                'open_time' => new DateTimeImmutable("2026-10-15T{$time}:00Z"),
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
}
