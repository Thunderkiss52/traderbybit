<?php

declare(strict_types=1);

namespace Tests\Feature\PatternDiscovery;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PatternDiscovery\Application\PatternOccurrenceOutcomeService;
use App\PatternDiscovery\Infrastructure\Persistence\CandlestickPatternOccurrenceRecord;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class PatternOccurrenceOutcomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_outcome_is_resolved_only_after_complete_contiguous_future_horizon(): void
    {
        $detected = new DateTimeImmutable('2026-10-15T10:15:00Z');
        $occurrence = CandlestickPatternOccurrenceRecord::query()->create([
            'id' => Uuid::uuid4()->toString(), 'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT',
            'timeframe' => '15', 'pattern_name' => 'BULLISH_ENGULFING', 'pattern_type' => 'CANDLESTICK',
            'direction' => 'BULLISH', 'version' => '1.0.0', 'bar_count' => 2,
            'formation_time' => $detected->modify('-30 minutes'), 'detected_at' => $detected,
            'evidence' => ['bars' => [['close' => '99'], ['close' => '100']]],
        ]);
        for ($index = 0; $index < 12; $index++) {
            $close = (string) (100 + (($index + 1) / 6));
            CandleRecord::query()->create([
                'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'interval' => '15',
                'open_time' => $detected->modify('+'.($index * 15).' minutes'), 'open' => '100',
                'high' => (string) ((float) $close + 0.2), 'low' => '99.8', 'close' => $close,
                'volume' => '10', 'turnover' => '1000', 'is_closed' => true,
            ]);
        }
        $service = new PatternOccurrenceOutcomeService(['horizon_bars' => 12, 'minimum_directional_return' => '0.001']);

        self::assertSame(0, $service->resolve('BTCUSDT', $detected->modify('+165 minutes')));
        self::assertNull($occurrence->refresh()->outcome);
        self::assertSame(1, $service->resolve('BTCUSDT', $detected->modify('+180 minutes')));
        self::assertSame('SUCCESS', $occurrence->refresh()->outcome);
        self::assertEqualsWithDelta(0.02, (float) $occurrence->forward_return, 0.0000000001);
        $this->getJson('/api/v1/research/candlestick-patterns?symbol=BTCUSDT')->assertOk()
            ->assertJsonPath('meta.statistics.0.resolved', 1)
            ->assertJsonPath('meta.statistics.0.success_rate', 1)
            ->assertJsonPath('meta.statistics.0.validation_status', 'INSUFFICIENT_DATA');
    }
}
