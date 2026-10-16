<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy;

use App\Strategy\Domain\SignalSide;
use App\Strategy\Domain\TimeframeState;
use App\Strategy\Research\BollingerMeanReversionStrategy;
use App\Strategy\Research\DonchianBreakoutStrategy;
use App\Strategy\Research\MarketRegimeClassifier;
use App\Strategy\Research\MomentumStrategy;
use App\Strategy\Research\TrendPullbackStrategy;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\Strategy\StrategyFixture;

final class ResearchStrategiesTest extends TestCase
{
    private array $config;

    protected function setUp(): void
    {
        $this->config = require dirname(__DIR__, 3).'/config/research.php';
    }

    public function test_trend_pullback_emits_buy_and_sell_without_changing_baseline_rules(): void
    {
        $strategy = new TrendPullbackStrategy($this->config['strategies']['trend_pullback']);
        $buy = $strategy->evaluate(StrategyFixture::input());
        self::assertSame(SignalSide::Buy, $buy->side);
        foreach (['1h', '30m', '15m', '5m'] as $timeframe) {
            self::assertArrayHasKey($timeframe, $buy->indicatorSnapshot);
        }
        self::assertSame(SignalSide::Sell, $strategy->evaluate(StrategyFixture::input(SignalSide::Sell))->side);

        $confirmation = new TimeframeState('5', [
            StrategyFixture::bar('2026-10-13T10:05:00Z', 5, ['macdHistogram' => '0.2']),
            StrategyFixture::bar('2026-10-13T10:10:00Z', 5, ['close' => '99', 'emaEntry' => '100', 'rsi' => '45', 'macdHistogram' => '0.1']),
        ], true);
        self::assertSame(SignalSide::Buy, $strategy->evaluate(StrategyFixture::input(overrides: ['confirmation' => $confirmation]))->side);
        $strict = new TrendPullbackStrategy(array_replace($this->config['strategies']['trend_pullback'], ['confirmation_mode' => 'STRICT']));
        self::assertSame(['five_minute_confirmation_conflicts'], $strict->evaluate(StrategyFixture::input(overrides: ['confirmation' => $confirmation]))->reasons);
    }

    public function test_mean_reversion_requires_prior_outside_close_and_current_reentry(): void
    {
        $previous = StrategyFixture::bar('2026-10-13T09:45:00Z', 15, ['close' => '94', 'bollingerLower' => '95', 'bollingerUpper' => '105', 'rsi' => '25']);
        $now = StrategyFixture::bar('2026-10-13T10:00:00Z', 15, ['close' => '96', 'bollingerLower' => '95', 'bollingerUpper' => '105', 'rsi' => '30']);
        $trend = new TimeframeState('60', [StrategyFixture::bar('2026-10-13T09:00:00Z', 60, ['adx' => '10'])], true);
        $setup = new TimeframeState('30', [StrategyFixture::bar('2026-10-13T09:30:00Z', 30, ['adx' => '10'])], true);
        $input = StrategyFixture::input(overrides: ['entry' => new TimeframeState('15', [$previous, $now], true), 'trend' => $trend, 'setup' => $setup]);
        self::assertSame(SignalSide::Buy, (new BollingerMeanReversionStrategy($this->config['strategies']['bollinger_mean_reversion']))->evaluate($input)->side);

        $confirmation = new TimeframeState('5', [
            StrategyFixture::bar('2026-10-13T10:05:00Z', 5, ['macdHistogram' => '0.2']),
            StrategyFixture::bar('2026-10-13T10:10:00Z', 5, ['close' => '99', 'emaEntry' => '100', 'rsi' => '45', 'macdHistogram' => '0.1']),
        ], true);
        $rejected = StrategyFixture::input(overrides: ['entry' => new TimeframeState('15', [$previous, $now], true), 'trend' => $trend, 'setup' => $setup, 'confirmation' => $confirmation]);
        self::assertSame(SignalSide::Buy, (new BollingerMeanReversionStrategy($this->config['strategies']['bollinger_mean_reversion']))->evaluate($rejected)->side);
        $strict = new BollingerMeanReversionStrategy(array_replace($this->config['strategies']['bollinger_mean_reversion'], ['confirmation_mode' => 'STRICT']));
        self::assertSame(['five_minute_confirmation_conflicts'], $strict->evaluate($rejected)->reasons);
    }

    public function test_donchian_excludes_breakout_candle_and_rejects_future_data(): void
    {
        $bars = [];
        $start = new \DateTimeImmutable('2026-10-13T05:00:00Z');
        for ($i = 0; $i < 20; $i++) {
            $bars[] = StrategyFixture::bar($start->modify('+'.($i * 15).' minutes')->format(DATE_ATOM), 15, ['high' => '101', 'low' => '99']);
        }
        $bars[] = StrategyFixture::bar('2026-10-13T10:00:00Z', 15, ['close' => '102', 'high' => '500', 'relativeVolume' => '2']);
        $strategy = new DonchianBreakoutStrategy($this->config['strategies']['donchian_atr_breakout']);
        self::assertSame(SignalSide::Buy, $strategy->evaluate(StrategyFixture::input(overrides: ['entry' => new TimeframeState('15', $bars, true)]))->side);
        $neutral = new TimeframeState('60', [StrategyFixture::bar('2026-10-13T09:00:00Z', 60, ['close' => '100', 'emaFast' => '100', 'emaSlow' => '100'])], true);
        self::assertSame(SignalSide::Buy, $strategy->evaluate(StrategyFixture::input(overrides: ['entry' => new TimeframeState('15', $bars, true), 'trend' => $neutral]))->side);
        $strict = new DonchianBreakoutStrategy(array_replace($this->config['strategies']['donchian_atr_breakout'], ['confirmation_mode' => 'STRICT']));
        self::assertSame(['hourly_trend_conflicts'], $strict->evaluate(StrategyFixture::input(overrides: ['entry' => new TimeframeState('15', $bars, true), 'trend' => $neutral]))->reasons);
        $bars[array_key_last($bars)] = StrategyFixture::bar('2026-10-13T10:15:00Z', 15, ['close' => '102', 'relativeVolume' => '2']);
        $decision = $strategy->evaluate(StrategyFixture::input(overrides: ['entry' => new TimeframeState('15', $bars, true)]));
        self::assertSame(SignalSide::Skip, $decision->side);
        self::assertSame(['future_candle_rejected'], $decision->reasons);
    }

    public function test_regime_classifier_is_causal_and_identifies_trend_range_and_high_volatility(): void
    {
        $cfg = $this->config['strategies']['regime_switching'];
        $classifier = new MarketRegimeClassifier($cfg);
        $states = ['TREND' => ['adx' => '25', 'atr' => '1', 'emaFast' => '103', 'emaSlow' => '100'], 'RANGE' => ['adx' => '10', 'atr' => '1', 'emaFast' => '100', 'emaSlow' => '100'], 'HIGH_VOLATILITY' => ['adx' => '25', 'atr' => '10', 'emaFast' => '103', 'emaSlow' => '100']];
        foreach ($states as $expected => $values) {
            $trend = new TimeframeState('60', [StrategyFixture::bar('2026-10-13T08:00:00Z', 60, ['emaFast' => '99']), StrategyFixture::bar('2026-10-13T09:00:00Z', 60, $values)], true);
            self::assertSame($expected, $classifier->classify(StrategyFixture::input(overrides: ['trend' => $trend])));
        }
    }

    public function test_momentum_strategy_operates_independently_for_long_and_short_setups(): void
    {
        $strategy = new MomentumStrategy($this->config['strategies']['momentum']);

        $buy = $strategy->evaluate(StrategyFixture::input());
        self::assertSame(SignalSide::Buy, $buy->side);
        self::assertContains('fifteen_minute_macd_acceleration', $buy->reasons);
        self::assertContains('five_minute_momentum_timing', $buy->reasons);
        self::assertSame(SignalSide::Sell, $strategy->evaluate(StrategyFixture::input(SignalSide::Sell))->side);
    }
}
