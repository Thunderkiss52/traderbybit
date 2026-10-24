<?php

declare(strict_types=1);

namespace Tests\Unit\Research;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\Research\Application\CounterfactualAnalyzerService;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CounterfactualAnalyzerServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['research.counterfactual.legacy_analysis_enabled' => true]);
    }

    public function test_creates_and_resolves_counterfactual_evaluation(): void
    {
        $service = new CounterfactualAnalyzerService;

        // Seed as-of closed candle at decision time
        $t0 = now('UTC')->subHours(5);
        CandleRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '15',
            'open_time' => $t0,
            'open' => '60000',
            'high' => '60500',
            'low' => '59800',
            'close' => '60000',
            'volume' => '100',
            'turnover' => '6000000',
            'is_closed' => true,
        ]);

        // Seed observation with SKIP due to ADX filter
        $obs = StrategyObservationRecord::query()->create([
            'id' => (string) Str::uuid(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'configuration_hash' => 'hash_test',
            'side' => 'SKIP',
            'score' => 60,
            'decision_time' => $t0,
            'reasons' => ['adx_below_threshold_20', 'trend_bullish'],
        ]);

        $eval = $service->createFromObservation($obs);
        self::assertNotNull($eval);
        self::assertSame('ADX', $eval->filter_family);
        self::assertSame('BUY', $eval->candidate_direction);
        self::assertSame('PENDING_MATURITY', $eval->status);

        // Seed subsequent closed candles hitting TP (+3%)
        CandleRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '15',
            'open_time' => $t0->addMinutes(15),
            'open' => '60100',
            'high' => '62000', // hits 61800 TP
            'low' => '59900',
            'close' => '61900',
            'volume' => '150',
            'turnover' => '9000000',
            'is_closed' => true,
        ]);

        $resolved = $service->resolveHypotheticalOutcome($eval);
        self::assertTrue($resolved);

        $eval->refresh();
        self::assertSame('RESOLVED_TP', $eval->status);
        self::assertEquals(2.87, (float) $eval->net_return_percent);
        self::assertEquals(round(2.87 / 1.5, 4), round((float) $eval->r_multiple, 4));

        // Report
        $report = $service->filterEffectivenessReport('trend_pullback');
        self::assertSame(1, $report['total_evaluated']);
        self::assertSame(1, $report['filters'][0]['restrictive_skips']);
    }

    public function test_analyze_and_resolve_is_idempotent(): void
    {
        $service = new CounterfactualAnalyzerService;

        $t0 = now('UTC')->subHours(6);
        CandleRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '15',
            'open_time' => $t0,
            'open' => '60000',
            'high' => '60500',
            'low' => '59800',
            'close' => '60000',
            'volume' => '100',
            'turnover' => '6000000',
            'is_closed' => true,
        ]);

        $obs = StrategyObservationRecord::query()->create([
            'id' => (string) Str::uuid(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'configuration_hash' => 'hash_test_idemp',
            'side' => 'SKIP',
            'score' => 60,
            'decision_time' => $t0,
            'reasons' => ['adx_below_threshold_20'],
        ]);

        $firstRun = $service->analyzeAndResolve(50);
        self::assertSame(1, $firstRun['analyzed_count']);

        // Second run must find zero un-analyzed observations
        $secondRun = $service->analyzeAndResolve(50);
        self::assertSame(0, $secondRun['analyzed_count']);
    }

    public function test_counterfactual_links_to_matching_signal_id_when_signal_exists(): void
    {
        $service = new CounterfactualAnalyzerService;
        $t0 = now('UTC')->subHours(6);

        CandleRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '15',
            'open_time' => $t0,
            'open' => '60000',
            'high' => '60500',
            'low' => '59800',
            'close' => '60000',
            'volume' => '100',
            'turnover' => '6000000',
            'is_closed' => true,
        ]);

        $signalId = (string) Str::uuid();
        SignalRecord::query()->create([
            'id' => $signalId,
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'timeframe' => '15',
            'side' => 'SKIP',
            'quality_class' => 'B',
            'score' => 65,
            'decision_time' => $t0,
            'candle_close_time' => $t0,
            'expires_at' => $t0->addHour(),
            'maximum_holding_minutes' => 240,
            'reasons' => ['adx_below_threshold_20'],
            'risks' => [],
            'indicator_snapshot' => [],
            'contract' => [],
            'schema_version' => '1.0.0',
        ]);

        $obs = StrategyObservationRecord::query()->create([
            'id' => (string) Str::uuid(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'configuration_hash' => 'hash_test_signal_link',
            'side' => 'SKIP',
            'score' => 65,
            'decision_time' => $t0,
            'reasons' => ['adx_below_threshold_20'],
        ]);

        $eval = $service->createFromObservation($obs);
        self::assertNotNull($eval);
        self::assertSame($signalId, $eval->signal_id);
        self::assertSame((string) $obs->id, $eval->decision_snapshot['observation_id']);
        self::assertFalse($eval->decision_snapshot['ambiguous_signal_match']);
    }

    public function test_counterfactual_rejects_ambiguous_signal_matches_and_leaves_signal_id_null(): void
    {
        $service = new CounterfactualAnalyzerService;
        $t0 = now('UTC')->subHours(6);

        CandleRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '15',
            'open_time' => $t0,
            'open' => '60000',
            'high' => '60500',
            'low' => '59800',
            'close' => '60000',
            'volume' => '100',
            'turnover' => '6000000',
            'is_closed' => true,
        ]);

        // Seed TWO duplicate/ambiguous signals with identical lookup attributes
        $signalId1 = (string) Str::uuid();
        SignalRecord::query()->create([
            'id' => $signalId1,
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'timeframe' => '15',
            'side' => 'SKIP',
            'quality_class' => 'B',
            'score' => 60,
            'decision_time' => $t0,
            'candle_close_time' => $t0,
            'expires_at' => $t0->copy()->addHour(),
            'maximum_holding_minutes' => 240,
            'reasons' => ['adx_below_threshold_20'],
            'risks' => [],
            'indicator_snapshot' => [],
            'contract' => [],
            'schema_version' => '1.0.0',
        ]);

        $signalId2 = (string) Str::uuid();
        SignalRecord::query()->create([
            'id' => $signalId2,
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'timeframe' => '15',
            'side' => 'SKIP',
            'quality_class' => 'B',
            'score' => 65,
            'decision_time' => $t0,
            'candle_close_time' => $t0->copy()->addSecond(),
            'expires_at' => $t0->copy()->addHour(),
            'maximum_holding_minutes' => 240,
            'reasons' => ['adx_below_threshold_20'],
            'risks' => [],
            'indicator_snapshot' => [],
            'contract' => [],
            'schema_version' => '1.0.0',
        ]);

        $obs = StrategyObservationRecord::query()->create([
            'id' => (string) Str::uuid(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'configuration_hash' => 'hash_test_ambig_signal_link',
            'side' => 'SKIP',
            'score' => 65,
            'decision_time' => $t0,
            'reasons' => ['adx_below_threshold_20'],
        ]);

        $eval = $service->createFromObservation($obs);
        self::assertNotNull($eval);
        self::assertNull($eval->signal_id, 'Ambiguous signals must be rejected rather than attaching an incorrect signal.');
        self::assertTrue($eval->decision_snapshot['ambiguous_signal_match']);
        self::assertCount(2, $eval->decision_snapshot['candidate_signal_ids']);
    }

    public function test_counterfactual_leaves_signal_id_null_when_no_signal_record_exists(): void
    {
        $service = new CounterfactualAnalyzerService;
        $t0 = now('UTC')->subHours(6);

        CandleRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '15',
            'open_time' => $t0,
            'open' => '60000',
            'high' => '60500',
            'low' => '59800',
            'close' => '60000',
            'volume' => '100',
            'turnover' => '6000000',
            'is_closed' => true,
        ]);

        $obs = StrategyObservationRecord::query()->create([
            'id' => (string) Str::uuid(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'configuration_hash' => 'hash_test_no_signal_link',
            'side' => 'SKIP',
            'score' => 65,
            'decision_time' => $t0,
            'reasons' => ['adx_below_threshold_20'],
        ]);

        $eval = $service->createFromObservation($obs);
        self::assertNotNull($eval);
        self::assertNull($eval->signal_id);
        self::assertFalse($eval->decision_snapshot['ambiguous_signal_match']);
    }
}
