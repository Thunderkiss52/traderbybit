<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData;

use App\MarketData\Application\ClosedCandleIngestor;
use App\MarketData\Domain\Candle;
use App\MarketData\Domain\Interval;
use App\MarketData\Infrastructure\Bybit\BybitWebSocketMessageParser;
use App\MarketData\Infrastructure\Persistence\EloquentCandleStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CandlePersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_upsert_prevents_duplicate_candles(): void
    {
        $store = new EloquentCandleStore;
        $candle = $this->candle();

        $store->upsert([$candle]);
        $store->upsert([$candle]);

        $this->assertDatabaseCount('market_candles', 1);
        $this->assertDatabaseHas('market_candles', [
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'interval' => '5',
            'is_closed' => true,
        ]);
    }

    public function test_duplicate_websocket_events_remain_idempotent(): void
    {
        $ingestor = new ClosedCandleIngestor(new BybitWebSocketMessageParser, new EloquentCandleStore);
        $event = json_encode([
            'topic' => 'kline.5.BTCUSDT',
            'data' => [[
                'start' => 1704067200000,
                'open' => '10', 'high' => '12', 'low' => '9', 'close' => '11',
                'volume' => '1', 'turnover' => '11', 'confirm' => true,
            ]],
        ], JSON_THROW_ON_ERROR);

        self::assertTrue($ingestor->ingest($event));
        self::assertTrue($ingestor->ingest($event));
        $this->assertDatabaseCount('market_candles', 1);
    }

    public function test_large_history_is_upserted_in_database_safe_batches(): void
    {
        $candles = [];

        for ($index = 0; $index < 1201; $index++) {
            $candles[] = new Candle(
                'bybit', 'linear', 'BTCUSDT', Interval::FiveMinutes,
                1704067200000 + ($index * 300000),
                '10', '12', '9', '11', '1.5', '16.5', true,
            );
        }

        $stored = (new EloquentCandleStore)->upsert($candles);

        self::assertSame(1201, $stored);
        $this->assertDatabaseCount('market_candles', 1201);
    }

    private function candle(): Candle
    {
        return new Candle(
            'bybit', 'linear', 'BTCUSDT', Interval::FiveMinutes, 1704067200000,
            '10', '12', '9', '11', '1.5', '16.5', true,
        );
    }
}
