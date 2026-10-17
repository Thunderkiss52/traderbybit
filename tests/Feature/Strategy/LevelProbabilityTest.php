<?php

declare(strict_types=1);

namespace Tests\Feature\Strategy;

use App\Strategy\Application\LevelProbabilityService;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Domain\SignalSide;
use App\Strategy\Infrastructure\Persistence\LevelInteractionForecastRecord;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Tests\Fixtures\Strategy\StrategyFixture;
use Tests\TestCase;

final class LevelProbabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_forecast_uses_only_prior_resolved_observations_and_reports_smoothed_probabilities(): void
    {
        $at = new DateTimeImmutable('2026-10-13T10:15:00Z');
        for ($index = 0; $index < 31; $index++) {
            $future = $index === 30;
            $triggered = $future ? $at->modify('+15 minutes') : $at->modify('-'.(31 - $index).' hours');
            $outcome = $index < 18 ? 'SUCCESS' : ($index < 27 ? 'FAILURE' : 'TIMEOUT');
            LevelInteractionForecastRecord::query()->create([
                'id' => Uuid::uuid4()->toString(), 'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT',
                'timeframe' => '15', 'event' => 'resistance_breakout', 'side' => 'BUY', 'triggered_at' => $triggered,
                'horizon_ends_at' => $triggered->modify('+3 hours'), 'model_version' => '0.9.0', 'configuration_hash' => str_repeat('a', 64),
                'sample_size' => 0, 'validation_status' => 'INSUFFICIENT_DATA', 'outcome' => $outcome,
                'outcome_at' => $triggered->modify('+1 hour'), 'features' => ['reference_price' => '100', 'atr' => '1'],
            ]);
        }

        // An apparently completed forecast is not usable if its outcome occurred after T.
        LevelInteractionForecastRecord::query()->create([
            'id' => Uuid::uuid4()->toString(), 'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT',
            'timeframe' => '15', 'event' => 'resistance_breakout', 'side' => 'BUY',
            'triggered_at' => $at->modify('-1 hour'), 'horizon_ends_at' => $at->modify('+1 hour'),
            'model_version' => '0.9.0', 'configuration_hash' => str_repeat('a', 64),
            'sample_size' => 0, 'validation_status' => 'INSUFFICIENT_DATA', 'outcome' => 'SUCCESS',
            'outcome_at' => $at->modify('+30 minutes'), 'features' => ['reference_price' => '100', 'atr' => '1'],
        ]);

        $forecast = $this->app->make(LevelProbabilityService::class)->record(StrategyFixture::input(), [
            'selected_event' => 'resistance_breakout', 'selected_side' => 'BUY', 'resistance' => '101',
            'momentum_side' => 'BUY', 'resistance_zone' => ['strength' => 3],
        ]);

        self::assertNotNull($forecast);
        self::assertSame(30, $forecast['sample_size']);
        self::assertSame('RESEARCH_BASELINE', $forecast['validation_status']);
        self::assertEqualsWithDelta(1.0, (float) $forecast['success_probability'] + (float) $forecast['failure_probability'] + (float) $forecast['unresolved_probability'], 0.00000002);
        self::assertSame('0.57575758', $forecast['success_probability']);
        $decision = $this->app->make(StrategyRegistry::class)->get('support_resistance_breakout')->evaluate(StrategyFixture::input());
        self::assertSame(SignalSide::Buy, $decision->side);
        self::assertContains('positive_expected_value_after_costs', $decision->reasons);
    }
}
