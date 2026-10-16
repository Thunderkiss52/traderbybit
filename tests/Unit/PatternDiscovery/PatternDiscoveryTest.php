<?php

declare(strict_types=1);

namespace Tests\Unit\PatternDiscovery;

use App\PatternDiscovery\Application\BarrierOutcomeLabeler;
use App\PatternDiscovery\Application\MarketFeatureExtractor;
use App\PatternDiscovery\Application\PatternMatcher;
use App\PatternDiscovery\Application\PatternMiner;
use App\PatternDiscovery\Domain\MarketObservation;
use PHPUnit\Framework\TestCase;

final class PatternDiscoveryTest extends TestCase
{
    private array $config = ['stop_atr' => '1', 'target_atr' => '1.5', 'holding_bars_5m' => 2, 'taker_fee_rate' => '0.00055', 'slippage_rate' => '0.0002'];

    public function test_future_features_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new MarketFeatureExtractor)->extract(['15m' => ['close_time' => '2026-10-15T12:15:00Z', 'rsi_14' => '55']], strtotime('2026-10-15T12:00:00Z'));
    }

    public function test_missing_values_do_not_become_artificial_features(): void
    {
        $items = (new MarketFeatureExtractor)->extract(['15m' => ['close_time' => '2026-10-15T12:00:00Z', 'rsi_14' => null]], strtotime('2026-10-15T12:00:00Z'));
        self::assertSame(['regime:UNCERTAIN'], $items['features']);
    }

    public function test_previous_signal_can_only_influence_later_decision(): void
    {
        $extractor = new MarketFeatureExtractor;
        $current = strtotime('2026-10-15T12:15:00Z');
        $earlier = $extractor->extract([], $current, ['decision_timestamp' => $current - 900, 'reason' => 'five_minute_confirmation_conflicts']);
        $future = $extractor->extract([], $current, ['decision_timestamp' => $current + 900, 'reason' => 'five_minute_confirmation_conflicts']);
        self::assertContains('prior15m:reason:five_minute_confirmation_conflicts', $earlier['features']);
        self::assertCount(1, $future['features']);
    }

    public function test_same_bar_tp_and_sl_is_ambiguously_scored_as_stop(): void
    {
        $bar = ['open_time' => 1000, 'open' => '100', 'high' => '103', 'low' => '98', 'close' => '101'];
        $result = (new BarrierOutcomeLabeler)->label('BUY', '100', '1', 1000, [$bar], $this->config);
        self::assertSame('SL', $result['outcome']);
        self::assertTrue($result['ambiguous']);
        self::assertLessThan(-1, $result['net_r']);
    }

    public function test_adverse_gap_uses_the_worse_open_price(): void
    {
        $bar = ['open_time' => 1000, 'open' => '97', 'high' => '99', 'low' => '96', 'close' => '98'];
        $result = (new BarrierOutcomeLabeler)->label('BUY', '100', '1', 1000, [$bar], $this->config);
        self::assertSame('SL', $result['outcome']);
        self::assertLessThan(-3, $result['net_r']);
        self::assertTrue($result['ambiguous']);
    }

    public function test_missing_candle_is_not_an_execution(): void
    {
        $result = (new BarrierOutcomeLabeler)->label('SELL', '100', '1', 1000, [['open_time' => 1300, 'open' => '100', 'high' => '101', 'low' => '99', 'close' => '100']], $this->config);
        self::assertNull($result);
    }

    public function test_conservative_timeout_includes_costs(): void
    {
        $bars = [
            ['open_time' => 1000, 'open' => '100', 'high' => '100.5', 'low' => '99.5', 'close' => '100'],
            ['open_time' => 1300, 'open' => '100', 'high' => '100.5', 'low' => '99.5', 'close' => '100'],
        ];
        $result = (new BarrierOutcomeLabeler)->label('BUY', '100', '1', 1000, $bars, $this->config);
        self::assertSame('TIMEOUT', $result['outcome']);
        self::assertFalse($result['won']);
        self::assertLessThan(0, $result['net_r']);
    }

    public function test_insufficient_independent_holdouts_never_validate(): void
    {
        $items = [];
        for ($i = 0; $i < 90; $i++) {
            $items[] = new MarketObservation((string) $i, 'BTCUSDT', 'BUY', $i * 1000 + 1000,
                $i * 1000 + 1100, ['rsi:HIGH', 'adx:TREND'], $i % 3 !== 0, $i % 3 === 0 ? -1.1 : 1.2, false, 'TREND');
        }
        $cfg = ['minimum_train' => 20, 'minimum_validation' => 10, 'minimum_test' => 10, 'maximum_terms' => 2,
            'maximum_patterns' => 10, 'confidence_level_z' => 1.96, 'multiple_testing_alpha' => 0.05,
            'minimum_out_of_sample_expectancy_r' => '0'];
        $found = (new PatternMiner)->discover($items, $cfg);
        self::assertNotEmpty($found);
        self::assertSame('REJECTED', $found[0]['status']);
        self::assertSame(53, $found[0]['train']['count']);
        self::assertSame(['UNKNOWN' => 18], $found[0]['test']['anchor_decisions']);
        self::assertArrayHasKey('profit_factor', $found[0]['test']);
        self::assertCount(3, $found[0]['walk_forward']);
    }

    public function test_sequence_and_context_use_only_past_information(): void
    {
        $now = strtotime('2026-10-15T12:15:00Z');
        $snapshot = ['15m' => ['close_time' => '2026-10-15T12:15:00Z', 'rsi_14' => '56', 'macd_histogram' => '0.1', 'relative_volume' => '1.6']];
        $past = ['decision_timestamp' => $now - 900, 'reason' => 'no_entry_trigger', 'snapshot' => [
            '15m' => ['rsi_14' => '45', 'macd_histogram' => '-0.1', 'relative_volume' => '0.8'],
        ]];
        $features = (new MarketFeatureExtractor)->extract($snapshot, $now, $past,
            ['observed_at' => $now - 10, 'funding_rate' => '-0.0005', 'open_interest_change' => '0.05']);
        self::assertContains('sequence:15m:macd_bullish_zero_cross', $features['features']);
        self::assertContains('context:funding:NEG', $features['features']);
        self::assertContains('context:open_interest_change:POS', $features['features']);
        $future = (new MarketFeatureExtractor)->extract($snapshot, $now, $past,
            ['observed_at' => $now + 10, 'funding_rate' => '-0.0005']);
        self::assertNotContains('context:funding:NEG', $future['features']);
    }

    public function test_pattern_matching_does_not_emit_order(): void
    {
        self::assertTrue((new PatternMatcher)->matches(['A', 'B'], ['A', 'B', 'C']));
        self::assertFalse((new PatternMatcher)->matches(['A', 'X'], ['A', 'B', 'C']));
    }
}
