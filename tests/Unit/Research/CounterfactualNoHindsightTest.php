<?php

declare(strict_types=1);

namespace Tests\Unit\Research;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\Research\Application\CounterfactualAnalyzerService;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CounterfactualNoHindsightTest extends TestCase
{
    use RefreshDatabase;

    private CounterfactualAnalyzerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CounterfactualAnalyzerService::class);
    }

    public function test_non_evaluable_skip_is_flagged_as_non_evaluable(): void
    {
        $t0 = CarbonImmutable::parse('2026-10-18 10:00:00');

        CandleRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '15',
            'open_time' => $t0,
            'open' => '65000.00',
            'high' => '65200.00',
            'low' => '64900.00',
            'close' => '65100.00',
            'volume' => '100.0',
            'turnover' => '6510000.0',
            'is_closed' => true,
        ]);

        $obs = StrategyObservationRecord::query()->create([
            'id' => 'obs-non-evaluable',
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'configuration_hash' => 'hash123',
            'side' => 'SKIP',
            'score' => 0,
            'decision_time' => $t0,
            'reasons' => ['insufficient_warmup'],
            'analysis' => ['diagnostics' => []],
        ]);

        $record = $this->service->createFromObservation($obs);
        $this->assertNotNull($record);
        $this->assertSame('NON_EVALUABLE', $record->status);

        // Verification: resolveHypotheticalOutcome returns false and does not process non-evaluables
        $resolved = $this->service->resolveHypotheticalOutcome($record);
        $this->assertFalse($resolved);
        $this->assertSame('NON_EVALUABLE', $record->fresh()->status);
    }

    public function test_direction_is_strictly_derived_at_decision_time_without_future_knowledge(): void
    {
        $t0 = CarbonImmutable::parse('2026-10-18 10:00:00');

        CandleRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '15',
            'open_time' => $t0,
            'open' => '65000.00',
            'high' => '65200.00',
            'low' => '64900.00',
            'close' => '65000.00',
            'volume' => '100.0',
            'turnover' => '6500000.0',
            'is_closed' => true,
        ]);

        // Bearish observation should strictly produce SELL
        $obsBearish = StrategyObservationRecord::query()->create([
            'id' => 'obs-bearish',
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'configuration_hash' => 'hash_bearish',
            'side' => 'SKIP',
            'score' => 40,
            'decision_time' => $t0,
            'reasons' => ['bearish_hourly_trend', 'adx_filter_rejected'],
            'analysis' => ['level_interaction' => ['selected_side' => 'SELL']],
        ]);

        $eval = $this->service->createFromObservation($obsBearish);
        $this->assertNotNull($eval);
        $this->assertSame('SELL', $eval->candidate_direction);
        $this->assertSame('PENDING_MATURITY', $eval->status);
        $this->assertTrue(BigDecimal::of($eval->hypothetical_sl)->isGreaterThan(BigDecimal::of($eval->hypothetical_entry)));
        $this->assertTrue(BigDecimal::of($eval->hypothetical_tp)->isLessThan(BigDecimal::of($eval->hypothetical_entry)));
    }

    public function test_resolved_tp_incorporates_realistic_trading_costs(): void
    {
        $t0 = CarbonImmutable::parse('2026-10-18 10:00:00');

        // Reference closed candle at decision time
        CandleRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '15',
            'open_time' => $t0,
            'open' => '60000.00',
            'high' => '60200.00',
            'low' => '59800.00',
            'close' => '60000.00',
            'volume' => '50.0',
            'turnover' => '3000000.0',
            'is_closed' => true,
        ]);

        $obs = StrategyObservationRecord::query()->create([
            'id' => 'obs-cost-test',
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'configuration_hash' => 'hash_cost',
            'side' => 'SKIP',
            'score' => 50,
            'decision_time' => $t0,
            'reasons' => ['thirty_minute_setup_conflicts'],
            'analysis' => ['level_interaction' => ['selected_side' => 'BUY']],
        ]);

        $eval = $this->service->createFromObservation($obs);
        $this->assertNotNull($eval);

        // Future candle that hits TP (TP is 60000 * 1.030 = 61800)
        CandleRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '15',
            'open_time' => $t0->addMinutes(15),
            'open' => '60000.00',
            'high' => '62000.00',
            'low' => '59900.00',
            'close' => '61900.00',
            'volume' => '150.0',
            'turnover' => '9000000.0',
            'is_closed' => true,
        ]);

        $resolved = $this->service->resolveHypotheticalOutcome($eval);
        $this->assertTrue($resolved);

        $fresh = $eval->fresh();
        $this->assertSame('RESOLVED_TP', $fresh->status);
        // Gross return 3.0% - 0.13% costs = 2.87% net return
        $this->assertEquals(2.87, (float) $fresh->net_return_percent);
        // Net R = 2.87 / 1.5 = 1.91333333
        $this->assertEquals(round(2.87 / 1.5, 4), round((float) $fresh->r_multiple, 4));
    }
}
