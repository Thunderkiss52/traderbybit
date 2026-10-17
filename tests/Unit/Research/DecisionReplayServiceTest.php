<?php

declare(strict_types=1);

namespace Tests\Unit\Research;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\Research\Application\DecisionReplayService;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DecisionReplayServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_replays_decision_deterministically(): void
    {
        $service = new DecisionReplayService;
        $t0 = now('UTC')->subHours(2);

        // Seed as-of closed candle
        CandleRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '15',
            'open_time' => $t0,
            'open' => '65000',
            'high' => '65500',
            'low' => '64800',
            'close' => '65200',
            'volume' => '200',
            'turnover' => '13000000',
            'is_closed' => true,
        ]);

        $obs = StrategyObservationRecord::query()->create([
            'id' => (string) Str::uuid(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'strategy_id' => 'baseline',
            'strategy_version' => '1.0.0',
            'configuration_hash' => 'hash_test',
            'side' => 'BUY',
            'score' => 85,
            'decision_time' => $t0,
            'reasons' => ['trend_aligned'],
        ]);

        $result = $service->replay($obs->id, 'AS_OF_DECISION');

        self::assertSame('COMPLETED', $result['status']);
        self::assertTrue($result['is_reproducible']);
        self::assertEmpty($result['diff']);
        self::assertSame('BUY', $result['recomputed_decision']['side']);
        self::assertNotEmpty($result['as_of_candles']);
    }
}
