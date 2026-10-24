<?php

declare(strict_types=1);

namespace Tests\Unit\Research;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\Research\Application\CounterfactualAnalyzerService;
use App\Research\Infrastructure\Persistence\CounterfactualEvaluationRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CounterfactualAccurateRCalculationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['research.counterfactual.legacy_analysis_enabled' => true]);
    }

    public function test_buy_take_profit_calculates_accurate_net_r_with_execution_costs(): void
    {
        $service = new CounterfactualAnalyzerService;
        $now = CarbonImmutable::parse('2026-03-01 10:00:00');

        // Entry: 100.00, SL: 98.00 (Risk: 2.00 = 2.0%), TP: 106.00 (Reward: 6.00 = 6.0%)
        // Gross return on TP = +6.0%
        // Round-trip cost = 0.13%
        // Net return = 6.0 - 0.13 = +5.87%
        // Net R-multiple = 5.87 / 2.0 = +2.935 R
        $evaluation = CounterfactualEvaluationRecord::query()->create([
            'id' => '67a29d35-b39c-5e4e-bb7e-055e78f6650f',
            'signal_id' => '1ef4ca1a-9694-5294-94d1-1a1d6ee70d1a',
            'strategy_id' => 'ema_pullback',
            'strategy_version' => 'v1.0.0',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15',
            'decision_time' => $now,
            'skip_reason' => 'adx_too_weak',
            'filter_family' => 'trend_strength',
            'candidate_direction' => 'BUY',
            'hypothetical_entry' => '100.00000000',
            'hypothetical_sl' => '98.00000000',
            'hypothetical_tp' => '106.00000000',
            'status' => 'PENDING_MATURITY',
            'decision_snapshot' => [],
        ]);

        // Future candle hits TP
        CandleRecord::query()->create([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'interval' => '15',
            'open_time' => $now->addMinutes(15),
            'open' => '100.00', 'high' => '107.00', 'low' => '99.50', 'close' => '106.50',
            'volume' => '10', 'turnover' => '1000', 'is_closed' => true,
        ]);

        $resolved = $service->resolveHypotheticalOutcome($evaluation);
        $this->assertTrue($resolved);

        $evaluation->refresh();
        $this->assertSame('RESOLVED_TP', $evaluation->status);
        // Net return = 5.87%
        $this->assertEqualsWithDelta(5.87, (float) $evaluation->net_return_percent, 0.001);
        // Net R = 5.87 / 2.0 = 2.935
        $this->assertEqualsWithDelta(2.935, (float) $evaluation->r_multiple, 0.001);
    }

    public function test_sell_stop_loss_calculates_accurate_net_r_with_execution_costs(): void
    {
        $service = new CounterfactualAnalyzerService;
        $now = CarbonImmutable::parse('2026-03-01 10:00:00');

        // Direction: SELL
        // Entry: 100.00, SL: 102.00 (Risk: 2.00 = 2.0%), TP: 95.00 (Reward: 5.00 = 5.0%)
        // Gross return on SL = -2.0%
        // Round-trip cost = 0.13%
        // Net return = -2.0 - 0.13 = -2.13%
        // Net R-multiple = -2.13 / 2.0 = -1.065 R
        $evaluation = CounterfactualEvaluationRecord::query()->create([
            'id' => '9b32da84-0bf2-5694-b558-7e45dec32e8e',
            'signal_id' => '080847b0-c3c3-5556-8b62-d44682d4c8fe',
            'strategy_id' => 'ema_pullback',
            'strategy_version' => 'v1.0.0',
            'symbol' => 'ETHUSDT',
            'timeframe' => '15',
            'decision_time' => $now,
            'skip_reason' => 'funding_rate_adverse',
            'filter_family' => 'cost_and_funding',
            'candidate_direction' => 'SELL',
            'hypothetical_entry' => '100.00000000',
            'hypothetical_sl' => '102.00000000',
            'hypothetical_tp' => '95.00000000',
            'status' => 'PENDING_MATURITY',
            'decision_snapshot' => [],
        ]);

        // Future candle hits SL (high >= 102.00)
        CandleRecord::query()->create([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'ETHUSDT', 'interval' => '15',
            'open_time' => $now->addMinutes(15),
            'open' => '100.00', 'high' => '102.50', 'low' => '99.00', 'close' => '101.50',
            'volume' => '10', 'turnover' => '1000', 'is_closed' => true,
        ]);

        $resolved = $service->resolveHypotheticalOutcome($evaluation);
        $this->assertTrue($resolved);

        $evaluation->refresh();
        $this->assertSame('RESOLVED_SL', $evaluation->status);
        // Net return = -2.13%
        $this->assertEqualsWithDelta(-2.13, (float) $evaluation->net_return_percent, 0.001);
        // Net R = -2.13 / 2.0 = -1.065
        $this->assertEqualsWithDelta(-1.065, (float) $evaluation->r_multiple, 0.001);
    }

    public function test_timeout_resolution_uses_last_candle_close(): void
    {
        $service = new CounterfactualAnalyzerService;
        $now = CarbonImmutable::parse('2026-03-01 10:00:00');

        // Entry: 100.00, SL: 95.00 (Risk: 5.0%), TP: 110.00
        $evaluation = CounterfactualEvaluationRecord::query()->create([
            'id' => '07c2513c-3618-5f65-8b93-464b61a856a1',
            'signal_id' => '1ba15b36-994a-53f3-b4fc-1e1ecfb218aa',
            'strategy_id' => 'ema_pullback',
            'strategy_version' => 'v1.0.0',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15',
            'decision_time' => $now,
            'skip_reason' => 'regime_ranging',
            'filter_family' => 'market_regime',
            'candidate_direction' => 'BUY',
            'hypothetical_entry' => '100.00000000',
            'hypothetical_sl' => '95.00000000',
            'hypothetical_tp' => '110.00000000',
            'status' => 'PENDING_MATURITY',
            'decision_snapshot' => [],
        ]);

        // Seed 17 candles of 15m (16 * 15m = 240m = 4h horizon)
        for ($i = 1; $i <= 16; $i++) {
            CandleRecord::query()->create([
                'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'interval' => '15',
                'open_time' => $now->addMinutes($i * 15),
                'open' => '100.00', 'high' => '102.00', 'low' => '99.00',
                // Last candle closes at 101.00 (+1.0% gross return)
                'close' => $i === 16 ? '101.00' : '100.50',
                'volume' => '10', 'turnover' => '1000', 'is_closed' => true,
            ]);
        }

        $resolved = $service->resolveHypotheticalOutcome($evaluation);
        $this->assertTrue($resolved);

        $evaluation->refresh();
        $this->assertSame('RESOLVED_TIMEOUT', $evaluation->status);
        // Gross return = (101.0 - 100.0) / 100.0 * 100 = +1.0%
        // Net return = 1.0 - 0.13 = +0.87%
        $this->assertEqualsWithDelta(0.87, (float) $evaluation->net_return_percent, 0.001);
        // Net R = 0.87 / 5.0 = 0.174
        $this->assertEqualsWithDelta(0.174, (float) $evaluation->r_multiple, 0.001);
    }
}
