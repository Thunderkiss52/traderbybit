<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\Research\Application\AdaptiveTimeframeResearchService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdaptiveTimeframeResearchTest extends TestCase
{
    use RefreshDatabase;

    private AdaptiveTimeframeResearchService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AdaptiveTimeframeResearchService;
    }

    public function test_all_seven_supported_intervals_are_analyzed_causally(): void
    {
        $symbol = 'BTCUSDT';
        $baseTime = CarbonImmutable::parse('2026-10-01T00:00:00Z');

        // Seed candles for all 7 intervals: 1, 5, 15, 30, 60, 240, D
        $intervals = AdaptiveTimeframeResearchService::SUPPORTED_INTERVALS;
        self::assertCount(7, $intervals);

        foreach ($intervals as $interval) {
            for ($i = 0; $i < 20; $i++) {
                $openTime = $baseTime->addMinutes($i * 15);
                CandleRecord::query()->create([
                    'exchange' => 'bybit',
                    'category' => 'linear',
                    'symbol' => $symbol,
                    'interval' => $interval,
                    'open_time' => $openTime,
                    'open' => '50000.0',
                    'high' => '50500.0',
                    'low' => '49800.0',
                    'close' => (string) (50000.0 + ($i * 10)),
                    'volume' => '100.0',
                    'turnover' => '5000000.0',
                    'is_closed' => true,
                ]);
            }
        }

        $analysis = $this->service->analyzeTimeframes($symbol, lookbackBars: 20);

        self::assertSame($symbol, $analysis['symbol']);
        self::assertCount(7, $analysis['intervals']);

        foreach ($intervals as $interval) {
            $data = $analysis['intervals'][$interval];
            self::assertSame('ACTIVE_RESEARCH', $data['status']);
            self::assertSame(20, $data['sample_bars']);
            self::assertGreaterThan(0.0, $data['mean_atr_pct']);
            self::assertGreaterThan(0.0, $data['mean_turnover']);
            self::assertNotEmpty($data['recommended_role']);
        }
    }

    public function test_timeframe_hierarchy_validation(): void
    {
        // Valid hierarchy: 240m context >= 30m setup >= 15m decision >= 5m trigger
        $validConfig = $this->service->evaluateConfiguration('trend_pullback', 'BTCUSDT', [
            'context_tf' => '240',
            'setup_tf' => '30',
            'decision_tf' => '15',
            'trigger_tf' => '5',
        ]);

        self::assertTrue($validConfig['hierarchy_valid']);
        self::assertSame('CONFIG_VERIFIED', $validConfig['evaluation_status']);
        self::assertTrue($validConfig['causality_enforced']);

        // Invalid hierarchy: decision TF (60m) is larger than context TF (15m)
        $invalidConfig = $this->service->evaluateConfiguration('trend_pullback', 'BTCUSDT', [
            'context_tf' => '15',
            'setup_tf' => '30',
            'decision_tf' => '60',
            'trigger_tf' => '5',
        ]);

        self::assertFalse($invalidConfig['hierarchy_valid']);
        self::assertSame('INVALID_HIERARCHY', $invalidConfig['evaluation_status']);
    }
}
