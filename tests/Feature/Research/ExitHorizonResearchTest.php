<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\Research\Application\ExitHorizonResearchService;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class ExitHorizonResearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_exit_horizon_analysis_evaluates_fixed_intervals_and_benchmark(): void
    {
        $base = CarbonImmutable::parse('2026-10-15T12:00:00Z');

        // Create a test BUY signal
        SignalRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 90,
            'decision_time' => $base,
            'candle_close_time' => $base,
            'entry_low' => '100',
            'entry_high' => '100',
            'stop_loss' => '95',
            'take_profit_1' => '110',
            'risk_reward_1' => '2.0',
            'model_risk_percent' => '0.50',
            'expires_at' => $base->addMinutes(30),
            'maximum_holding_minutes' => 240,
            'reasons' => ['test_setup'],
            'risks' => [],
            'indicator_snapshot' => [],
            'contract' => ['research_mode' => true],
            'strategy_version' => '1.0.0',
            'schema_version' => '1.0.0',
            'strategy_id' => 'baseline',
        ]);

        // Seed 5m candles moving upward
        for ($i = 0; $i < 50; $i++) {
            $time = $base->addMinutes($i * 5);
            $price = 100 + ($i * 0.25);
            CandleRecord::query()->create([
                'exchange' => 'bybit',
                'category' => 'linear',
                'symbol' => 'BTCUSDT',
                'interval' => '5',
                'open_time' => $time,
                'open' => (string) $price,
                'high' => (string) ($price + 0.5),
                'low' => (string) ($price - 0.2),
                'close' => (string) ($price + 0.3),
                'volume' => '100',
                'turnover' => '10000',
                'is_closed' => true,
            ]);
        }

        $service = $this->app->make(ExitHorizonResearchService::class);
        $result = $service->analyze('BTCUSDT', [15, 30, 60, 120, 240]);

        self::assertSame('BTCUSDT', $result['symbol']);
        self::assertSame(1, $result['evaluated_signals_count']);
        self::assertCount(5, $result['fixed_horizons']);
        self::assertArrayHasKey('benchmark_tp_sl', $result);
        self::assertArrayHasKey('recommended_horizon', $result);
    }

    public function test_exit_horizon_endpoint_returns_json(): void
    {
        $this->getJson('/api/v1/research/exit-horizons?symbol=BTCUSDT')
            ->assertOk()
            ->assertJsonStructure([
                'schema_version',
                'research_mode',
                'data' => [
                    'symbol',
                    'evaluated_signals_count',
                    'benchmark_tp_sl',
                    'fixed_horizons',
                    'recommended_horizon',
                ],
            ]);
    }
}
