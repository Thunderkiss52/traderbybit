<?php

declare(strict_types=1);

namespace Tests\Unit\MarketData;

use App\MarketData\Infrastructure\Bybit\BybitWebSocketMessageParser;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class WebSocketParserTest extends TestCase
{
    public function test_it_ignores_unconfirmed_candles_and_control_messages(): void
    {
        $parser = new BybitWebSocketMessageParser;

        self::assertNull($parser->parseClosedCandle('{"op":"ping","success":true}'));
        self::assertNull($parser->parseClosedCandle($this->event(false)));
    }

    public function test_it_parses_only_confirmed_closed_candles(): void
    {
        $candle = (new BybitWebSocketMessageParser)->parseClosedCandle($this->event(true));

        self::assertNotNull($candle);
        self::assertTrue($candle->isClosed);
        self::assertSame('BTCUSDT', $candle->symbol);
        self::assertSame(1704067200000, $candle->openTimeMs());
    }

    public function test_it_parses_30m_closed_candles(): void
    {
        $payload = json_encode([
            'topic' => 'kline.30.ETHUSDT',
            'type' => 'snapshot',
            'ts' => 1704069000000,
            'data' => [[
                'start' => 1704067200000,
                'end' => 1704068999999,
                'interval' => '30',
                'open' => '2200.1',
                'high' => '2250.2',
                'low' => '2190.3',
                'close' => '2240.4',
                'volume' => '500',
                'turnover' => '1110000',
                'confirm' => true,
                'timestamp' => 1704068999000,
            ]],
        ], JSON_THROW_ON_ERROR);

        $candle = (new BybitWebSocketMessageParser)->parseClosedCandle($payload);
        self::assertNotNull($candle);
        self::assertTrue($candle->isClosed);
        self::assertSame('ETHUSDT', $candle->symbol);
        self::assertSame('30', $candle->interval->value);
    }

    public function test_it_rejects_incomplete_events(): void
    {
        $this->expectException(UnexpectedValueException::class);
        (new BybitWebSocketMessageParser)->parseClosedCandle('{"topic":"kline.5.BTCUSDT","data":[{"confirm":true}]}');
    }

    private function event(bool $confirmed): string
    {
        return json_encode([
            'topic' => 'kline.5.BTCUSDT',
            'type' => 'snapshot',
            'ts' => 1704067500000,
            'data' => [[
                'start' => 1704067200000,
                'end' => 1704067499999,
                'interval' => '5',
                'open' => '42000.1',
                'high' => '42100.2',
                'low' => '41900.3',
                'close' => '42050.4',
                'volume' => '12.5',
                'turnover' => '525630',
                'confirm' => $confirmed,
                'timestamp' => 1704067499000,
            ]],
        ], JSON_THROW_ON_ERROR);
    }
}
