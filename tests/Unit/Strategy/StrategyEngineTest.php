<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy;

use App\Strategy\Application\StrategyEngine;
use App\Strategy\Domain\MarketContext;
use App\Strategy\Domain\QualityClass;
use App\Strategy\Domain\SignalSide;
use App\Strategy\Domain\TimeframeState;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\Strategy\StrategyFixture;

final class StrategyEngineTest extends TestCase
{
    public function test_buy_contains_all_triggers_prices_and_a_quality(): void
    {
        $decision = $this->engine()->evaluate(StrategyFixture::input());

        self::assertSame(SignalSide::Buy, $decision->side);
        self::assertSame(QualityClass::A, $decision->qualityClass);
        self::assertSame(['bullish_hourly_trend', 'thirty_minute_setup', 'five_minute_confirmation', 'level_resistance_breakout', 'ema_pullback', 'macd_crossover', 'confirmed_breakout'], $decision->reasons);
        self::assertArrayHasKey('30m', $decision->indicatorSnapshot);
        self::assertSame('resistance_breakout', $decision->indicatorSnapshot['level_interaction']['selected_event']);
        self::assertSame(69, $decision->indicatorSnapshot['level_interaction']['selected_probability_percent']);
        self::assertSame(100, $decision->indicatorSnapshot['level_interaction']['resistance_breakout_probability_percent']
            + $decision->indicatorSnapshot['level_interaction']['resistance_rejection_probability_percent']);
        self::assertSame('103.1', $decision->pricePlan?->entryLow);
        self::assertSame('103.3', $decision->pricePlan?->entryHigh);
        self::assertSame('101.7', $decision->pricePlan?->stopLoss);
        self::assertSame('105.7', $decision->pricePlan?->takeProfit1);
        self::assertSame('107.3', $decision->pricePlan?->takeProfit2);
    }

    public function test_sell_is_mirrored_and_rounded_to_tick(): void
    {
        $decision = $this->engine()->evaluate(StrategyFixture::input(SignalSide::Sell));

        self::assertSame(SignalSide::Sell, $decision->side);
        self::assertSame(QualityClass::A, $decision->qualityClass);
        self::assertSame('96.7', $decision->pricePlan?->entryLow);
        self::assertSame('96.9', $decision->pricePlan?->entryHigh);
        self::assertSame('98.3', $decision->pricePlan?->stopLoss);
        self::assertSame('94.3', $decision->pricePlan?->takeProfit1);
        self::assertSame('92.7', $decision->pricePlan?->takeProfit2);
    }

    public function test_neutral_trend_warmup_gap_and_staleness_are_specific_skips(): void
    {
        $neutral = new TimeframeState('60', [StrategyFixture::bar('2026-10-13T09:00:00Z', 60, ['emaFast' => '100', 'emaSlow' => '100'])], true);
        $bars = StrategyFixture::input()->entry->bars;
        $bars[0] = StrategyFixture::bar('2026-10-13T09:45:00Z', 15, ['resistance' => null, 'support' => null]);
        self::assertSame(['neutral_hourly_trend'], $this->engine()->evaluate(StrategyFixture::input(overrides: [
            'trend' => $neutral,
            'entry' => new TimeframeState('15', $bars, true),
        ]))->reasons);

        $cold = new TimeframeState('15', [], false);
        self::assertSame(['insufficient_warmup'], $this->engine()->evaluate(StrategyFixture::input(overrides: ['entry' => $cold]))->reasons);
        self::assertSame(['non_contiguous_entry_history'], $this->engine()->evaluate(StrategyFixture::input(overrides: ['validationErrors' => ['non_contiguous_entry_history']]))->reasons);
        self::assertSame(['stale_confirmation_data'], $this->engine()->evaluate(StrategyFixture::input(overrides: ['validationErrors' => ['stale_confirmation_data']]))->reasons);
    }

    public function test_strong_support_rejection_with_momentum_can_enter_during_neutral_hourly_trend(): void
    {
        $neutral = new TimeframeState('60', [StrategyFixture::bar('2026-10-13T09:00:00Z', 60, ['emaFast' => '100', 'emaSlow' => '100'])], true);
        $bars = StrategyFixture::input()->entry->bars;
        $bars[0] = StrategyFixture::bar('2026-10-13T09:45:00Z', 15, ['support' => '100', 'resistance' => '104', 'macdHistogram' => '0.05']);
        $bars[1] = StrategyFixture::bar('2026-10-13T10:00:00Z', 15, [
            'open' => '100.2', 'high' => '101.5', 'low' => '99.9', 'close' => '101.2', 'support' => '100',
            'resistance' => null, 'rsi' => '60', 'macdHistogram' => '0.2', 'relativeVolume' => '1.8',
        ]);

        $decision = $this->engine()->evaluate(StrategyFixture::input(overrides: [
            'trend' => $neutral,
            'entry' => new TimeframeState('15', $bars, true),
        ]));

        self::assertSame(SignalSide::Buy, $decision->side);
        self::assertContains('level_structure_direction', $decision->reasons);
        self::assertContains('level_support_rejection', $decision->reasons);
        self::assertSame('BUY', $decision->indicatorSnapshot['level_interaction']['momentum_side']);
    }

    public function test_conflicting_confirmation_spread_and_extreme_atr_are_skips(): void
    {
        $setupAgainst = new TimeframeState('30', [
            StrategyFixture::bar('2026-10-13T09:30:00Z', 30, ['close' => '99', 'emaFast' => '100', 'emaSlow' => '101']),
        ], true);
        self::assertSame(['thirty_minute_setup_conflicts'], $this->engine()->evaluate(StrategyFixture::input(overrides: ['setup' => $setupAgainst]))->reasons);

        $against = new TimeframeState('5', [
            StrategyFixture::bar('2026-10-13T10:05:00Z', 5, ['macdHistogram' => '0.2']),
            StrategyFixture::bar('2026-10-13T10:10:00Z', 5, ['close' => '99', 'emaEntry' => '100', 'rsi' => '45', 'macdHistogram' => '0.1']),
        ], true);
        self::assertSame(['five_minute_confirmation_conflicts'], $this->engine()->evaluate(StrategyFixture::input(overrides: ['confirmation' => $against]))->reasons);

        $wide = new MarketContext(new DateTimeImmutable('2026-10-13T10:15:00Z'), '100', '101', '100000000', '0.1');
        self::assertSame(['spread_above_maximum'], $this->engine()->evaluate(StrategyFixture::input(overrides: ['marketContext' => $wide]))->reasons);

        $bars = StrategyFixture::input()->entry->bars;
        $bars[1] = StrategyFixture::bar('2026-10-13T10:00:00Z', 15, ['close' => '103.2', 'emaEntry' => '101', 'rsi' => '60', 'macd' => '0.2', 'macdSignal' => '0', 'macdHistogram' => '0.2', 'relativeVolume' => '1.8', 'atr' => '10']);
        self::assertSame(['extreme_volatility'], $this->engine()->evaluate(StrategyFixture::input(overrides: ['entry' => new TimeframeState('15', $bars, true)]))->reasons);
    }

    public function test_opposite_level_rejects_insufficient_risk_reward(): void
    {
        $bars = StrategyFixture::input()->entry->bars;
        $bars[1] = StrategyFixture::bar('2026-10-13T10:00:00Z', 15, ['open' => '102.2', 'close' => '103.2', 'low' => '102', 'high' => '104', 'emaEntry' => '101', 'rsi' => '60', 'macd' => '0.2', 'macdSignal' => '0', 'macdHistogram' => '0.2', 'relativeVolume' => '1.8', 'support' => '101.8', 'resistance' => '105']);
        $decision = $this->engine()->evaluate(StrategyFixture::input(overrides: ['entry' => new TimeframeState('15', $bars, true)]));
        self::assertSame(SignalSide::Skip, $decision->side);
        self::assertSame(['invalid_or_insufficient_risk_reward'], $decision->reasons);
    }

    public function test_quality_classes_follow_configured_thresholds_without_duplicate_points(): void
    {
        $config = StrategyFixture::config();
        $config['scoring']['classes'] = ['A' => 101, 'B' => 101, 'C' => 0];
        self::assertSame(QualityClass::C, (new StrategyEngine($config))->evaluate(StrategyFixture::input())->qualityClass);
        $config['scoring']['classes'] = ['A' => 101, 'B' => 0, 'C' => 0];
        self::assertSame(QualityClass::B, (new StrategyEngine($config))->evaluate(StrategyFixture::input())->qualityClass);
        $config['scoring']['classes'] = ['A' => 0, 'B' => 0, 'C' => 0];
        self::assertSame(QualityClass::A, (new StrategyEngine($config))->evaluate(StrategyFixture::input())->qualityClass);
    }

    public function test_json_contract_is_deterministic_and_research_only(): void
    {
        $first = $this->engine()->evaluate(StrategyFixture::input());
        $second = $this->engine()->evaluate(StrategyFixture::input());
        self::assertSame($first->id, $second->id);
        self::assertSame(json_encode($first->toContractArray()), json_encode($second->toContractArray()));
        self::assertTrue($first->toContractArray()['research_mode']);
        self::assertArrayNotHasKey('order', $first->toContractArray());
    }

    public function test_missing_historical_context_and_tick_size_cannot_emit_signal(): void
    {
        self::assertSame(['historical_market_context_unavailable'], $this->engine()->evaluate(StrategyFixture::input(overrides: ['marketContext' => null]))->reasons);
        $context = new MarketContext(new DateTimeImmutable('2026-10-13T10:15:00Z'), '103.1', '103.2', '100000000', '0');
        self::assertSame(['tick_size_unavailable'], $this->engine()->evaluate(StrategyFixture::input(overrides: ['marketContext' => $context]))->reasons);
    }

    public function test_future_candle_is_rejected_by_the_pure_engine(): void
    {
        $future = new TimeframeState('5', [
            StrategyFixture::bar('2026-10-13T10:10:00Z', 5),
            StrategyFixture::bar('2026-10-13T10:15:00Z', 5),
        ], true);
        $decision = $this->engine()->evaluate(StrategyFixture::input(overrides: ['confirmation' => $future]));
        self::assertSame(SignalSide::Skip, $decision->side);
        self::assertSame(['future_candle_rejected'], $decision->reasons);
    }

    private function engine(): StrategyEngine
    {
        return new StrategyEngine(StrategyFixture::config());
    }
}
