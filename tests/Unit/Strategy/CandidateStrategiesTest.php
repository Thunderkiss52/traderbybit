<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy;

use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Domain\BarState;
use App\Strategy\Domain\MarketContext;
use App\Strategy\Domain\SignalSide;
use App\Strategy\Domain\StrategyInput;
use App\Strategy\Domain\TimeframeState;
use DateTimeImmutable;
use Tests\Fixtures\Strategy\StrategyFixture;
use Tests\TestCase;

final class CandidateStrategiesTest extends TestCase
{
    private DateTimeImmutable $at;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at = new DateTimeImmutable('2026-10-13T10:15:00Z');
    }

    public function test_registry_exposes_all_thirteen_primary_strategies_as_execution_eligible(): void
    {
        $registry = $this->app->make(StrategyRegistry::class);
        $metadata = collect($registry->metadata())->keyBy('id');

        self::assertCount(13, $metadata);
        foreach (['funding_open_interest_squeeze', 'liquidity_sweep_reversal', 'volatility_compression_breakout', 'btc_eth_lead_lag', 'multi_timeframe_trend_following'] as $id) {
            self::assertTrue($metadata->has($id), $id);
        }
        self::assertTrue($metadata['btc_eth_lead_lag']['execution_eligible']);
        self::assertTrue($metadata['liquidity_sweep_reversal']['execution_eligible']);
    }

    public function test_funding_open_interest_squeeze_emits_reversal_in_both_directions_and_skips_without_crowding(): void
    {
        $strategy = $this->app->make(StrategyRegistry::class)->get('funding_open_interest_squeeze');

        $down = $this->series(80, static fn (int $i): array => $i === 79 ? ['close' => '99.5'] : []);
        $up = $this->series(80, static fn (int $i): array => $i === 79 ? ['close' => '100.5'] : []);

        $buy = $strategy->evaluate($this->input(bars: $down, context: $this->context('0.0002', '0.01')));
        self::assertSame(SignalSide::Buy, $buy->side);
        self::assertContains('FUNDING_CROWD_LONG_FAILED_SQUEEZE', $buy->reasons);

        $sell = $strategy->evaluate($this->input(bars: $up, context: $this->context('-0.0002', '0.01')));
        self::assertSame(SignalSide::Sell, $sell->side);
        self::assertContains('FUNDING_CROWD_SHORT_FAILED_SQUEEZE', $sell->reasons);

        $skip = $strategy->evaluate($this->input(bars: $down, context: $this->context('0.00001', '0.01')));
        self::assertSame(SignalSide::Skip, $skip->side);
        self::assertContains('crowding_conditions_not_met', $skip->reasons);
    }

    public function test_liquidity_sweep_reversal_requires_a_sweep_and_close_recapture(): void
    {
        $strategy = $this->app->make(StrategyRegistry::class)->get('liquidity_sweep_reversal');

        $buyBars = $this->series(80, static fn (int $i): array => $i === 79
            ? ['open' => '99.4', 'high' => '99.9', 'low' => '98.5', 'close' => '99.6']
            : ['open' => '100', 'high' => '101', 'low' => '99', 'close' => '100']);
        $buy = $strategy->evaluate($this->input(bars: $buyBars));
        self::assertSame(SignalSide::Buy, $buy->side);
        self::assertContains('PRIOR_SUPPORT_SWEEP_RECAPTURE', $buy->reasons);

        $sellBars = $this->series(80, static fn (int $i): array => $i === 79
            ? ['open' => '100.6', 'high' => '101.5', 'low' => '100.1', 'close' => '100.4']
            : ['open' => '100', 'high' => '101', 'low' => '99', 'close' => '100']);
        $sell = $strategy->evaluate($this->input(bars: $sellBars));
        self::assertSame(SignalSide::Sell, $sell->side);
        self::assertContains('PRIOR_RESISTANCE_SWEEP_REJECTION', $sell->reasons);

        $skip = $strategy->evaluate($this->input(bars: $this->series(80)));
        self::assertSame(SignalSide::Skip, $skip->side);
        self::assertContains('no_prior_level_sweep_reversal', $skip->reasons);
    }

    public function test_volatility_compression_breakout_requires_prior_compression_and_close_breakout(): void
    {
        $strategy = $this->app->make(StrategyRegistry::class)->get('volatility_compression_breakout');

        $bars = $this->series(80, static function (int $i): array {
            if ($i >= 79) {
                return ['open' => '100', 'high' => '101', 'low' => '100', 'close' => '101'];
            }
            if ($i >= 59) {
                return ['open' => '100', 'high' => '100.05', 'low' => '99.95', 'close' => '100'];
            }

            return ['open' => '100', 'high' => '100.6', 'low' => '99.4', 'close' => '100'];
        });
        $buy = $strategy->evaluate($this->input(bars: $bars));
        self::assertSame(SignalSide::Buy, $buy->side);
        self::assertContains('COMPRESSION_BREAKOUT_UP', $buy->reasons);

        $skip = $strategy->evaluate($this->input(bars: $this->series(80)));
        self::assertSame(SignalSide::Skip, $skip->side);
        self::assertContains('volatility_not_compressed', $skip->reasons);
    }

    public function test_multi_timeframe_trend_following_requires_htf_direction_and_ltf_cross(): void
    {
        $strategy = $this->app->make(StrategyRegistry::class)->get('multi_timeframe_trend_following');

        $bars = $this->series(80, static fn (int $i): array => $i === 79
            ? ['open' => '100', 'high' => '131', 'low' => '100', 'close' => '130']
            : ['open' => '100', 'high' => '100.5', 'low' => '99.5', 'close' => '100']);
        $buy = $strategy->evaluate($this->input(bars: $bars, trend: ['emaEntry' => '110', 'emaFast' => '105', 'adx' => '30']));
        self::assertSame(SignalSide::Buy, $buy->side);
        self::assertContains('HTF_UPTREND_LTF_EMA_CROSS_UP', $buy->reasons);

        $skip = $strategy->evaluate($this->input(bars: $bars, trend: ['emaEntry' => '100', 'emaFast' => '105']));
        self::assertSame(SignalSide::Skip, $skip->side);
        self::assertContains('no_ltf_confirmation_in_htf_direction', $skip->reasons);
    }

    public function test_btc_eth_lead_lag_uses_only_known_peer_history_and_skips_without_peer(): void
    {
        $strategy = $this->app->make(StrategyRegistry::class)->get('btc_eth_lead_lag');

        $local = $this->series(80);
        $peer = $this->series(80, static fn (int $i): array => $i === 79
            ? ['open' => '100', 'high' => '101', 'low' => '100', 'close' => '101']
            : ['open' => '100', 'high' => '100.2', 'low' => '99.8', 'close' => '100']);
        $buy = $strategy->evaluate($this->input(bars: $local, peerBars: $peer));
        self::assertSame(SignalSide::Buy, $buy->side);
        self::assertContains('PEER_UP_LOCAL_LAGGING', $buy->reasons);

        $skip = $strategy->evaluate($this->input(bars: $local));
        self::assertSame(SignalSide::Skip, $skip->side);
        self::assertContains('peer_history_unavailable', $skip->reasons);
    }

    public function test_candidate_rejects_a_future_candle(): void
    {
        $strategy = $this->app->make(StrategyRegistry::class)->get('liquidity_sweep_reversal');
        $bars = $this->series(80);
        $bars[] = StrategyFixture::bar('2026-10-13T10:15:00Z', 15, ['close' => '101']);

        $decision = $strategy->evaluate($this->input(bars: $bars));

        self::assertSame(SignalSide::Skip, $decision->side);
        self::assertContains('future_candle_rejected', $decision->reasons);
    }

    /** @param list<BarState>|null $bars @param list<BarState>|null $peerBars */
    private function input(?array $bars = null, ?MarketContext $context = null, ?array $peerBars = null, array $trend = []): StrategyInput
    {
        $bars ??= $this->series(80);
        $trendBar = StrategyFixture::bar('2026-10-13T09:00:00Z', 60, array_replace([
            'close' => '105', 'emaEntry' => '110', 'emaFast' => '105', 'emaSlow' => '100', 'adx' => '35',
        ], $trend));
        $state = new TimeframeState('15', $bars, true);

        return new StrategyInput(
            exchange: 'bybit', category: 'linear', symbol: 'BTCUSDT', decisionTime: $this->at,
            trend: new TimeframeState('60', [$trendBar], true),
            setup: new TimeframeState('30', [$trendBar], true),
            entry: $state,
            confirmation: new TimeframeState('5', [$trendBar], true),
            marketContext: $context ?? $this->context('0.0002', '0.01'),
            validationErrors: [],
            peerEntry: $peerBars === null ? null : new TimeframeState('15', $peerBars, true),
        );
    }

    private function context(string $funding, string $openInterestChange): MarketContext
    {
        return new MarketContext($this->at, '100.1', '100.2', '100000000', '0.1', $funding, '1000000', $openInterestChange);
    }

    /** @return list<BarState> */
    private function series(int $count, ?callable $values = null): array
    {
        $bars = [];
        $start = $this->at->modify('-'.($count * 15).' minutes');
        for ($i = 0; $i < $count; $i++) {
            $open = $start->modify('+'.($i * 15).' minutes');
            $bars[] = StrategyFixture::bar($open->format('Y-m-d\TH:i:s\Z'), 15, array_replace([
                'open' => '100', 'high' => '100.5', 'low' => '99.5', 'close' => '100',
                'atr' => '1', 'relativeVolume' => '1.2',
            ], $values === null ? [] : $values($i)));
        }

        return $bars;
    }
}
