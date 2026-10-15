<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy;

use App\Strategy\Domain\SignalSide;
use App\Strategy\Domain\TimeframeState;
use App\Strategy\Research\BollingerMeanReversionStrategy;
use App\Strategy\Research\DonchianBreakoutStrategy;
use App\Strategy\Research\MarketRegimeClassifier;
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
        self::assertSame(SignalSide::Buy, $strategy->evaluate(StrategyFixture::input())->side);
        self::assertSame(SignalSide::Sell, $strategy->evaluate(StrategyFixture::input(SignalSide::Sell))->side);
    }

    public function test_mean_reversion_requires_prior_outside_close_and_current_reentry(): void
    {
        $previous = StrategyFixture::bar('2026-10-13T09:45:00Z', 15, ['close' => '94', 'bollingerLower' => '95', 'bollingerUpper' => '105', 'rsi' => '25']);
        $now = StrategyFixture::bar('2026-10-13T10:00:00Z', 15, ['close' => '96', 'bollingerLower' => '95', 'bollingerUpper' => '105', 'rsi' => '30']);
        $trend = new TimeframeState('60', [StrategyFixture::bar('2026-10-13T09:00:00Z', 60, ['adx' => '10'])], true);
        $input = StrategyFixture::input(overrides: ['entry' => new TimeframeState('15', [$previous, $now], true), 'trend' => $trend]);
        self::assertSame(SignalSide::Buy, (new BollingerMeanReversionStrategy($this->config['strategies']['bollinger_mean_reversion']))->evaluate($input)->side);
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
}
