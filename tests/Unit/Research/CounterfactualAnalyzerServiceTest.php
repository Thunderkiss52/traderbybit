<?php

declare(strict_types=1);

namespace Tests\Unit\Research;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\Research\Application\CounterfactualAnalyzerService;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CounterfactualAnalyzerServiceTest extends TestCase
{
    use RefreshDatabase;

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
        self::assertTrue(BigDecimal::of($eval->r_multiple)->isEqualTo('2'));
        self::assertTrue(BigDecimal::of($eval->net_return_percent)->isEqualTo('3'));

        // Report
        $report = $service->filterEffectivenessReport('trend_pullback');
        self::assertSame(1, $report['total_evaluated']);
        self::assertSame(1, $report['filters'][0]['restrictive_skips']);
    }
}
