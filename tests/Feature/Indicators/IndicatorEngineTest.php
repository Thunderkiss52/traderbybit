<?php

declare(strict_types=1);

namespace Tests\Feature\Indicators;

use App\Indicators\Application\IndicatorEngine;
use App\Indicators\Exceptions\InvalidSeriesException;
use App\MarketData\Domain\Candle;
use App\MarketData\Domain\Interval;
use Tests\TestCase;

final class IndicatorEngineTest extends TestCase
{
    public function test_warmup_blocks_signal_readiness_until_all_mandatory_indicators_are_ready(): void
    {
        $engine = app(IndicatorEngine::class);

        $insufficient = $engine->calculate($this->candles(199));
        self::assertSame(200, $insufficient->warmup->requiredCandles);
        self::assertSame(1, $insufficient->warmup->missingCandles());
        self::assertFalse($insufficient->isReadyForSignals());
        self::assertNull($insufficient->emaSlow[198]);

        $ready = $engine->calculate($this->candles(200));
        self::assertTrue($ready->warmup->isReady());
        self::assertTrue($ready->isReadyForSignals());
        self::assertNotNull($ready->emaSlow[199]);
        self::assertNotNull($ready->adx->adx[199]);
    }

    public function test_same_closed_history_produces_byte_identical_indicator_values(): void
    {
        $engine = app(IndicatorEngine::class);
        $candles = $this->candles(220);

        $first = serialize($engine->calculate($candles));
        $second = serialize($engine->calculate($candles));

        self::assertSame($first, $second);
    }

    public function test_engine_rejects_an_open_candle(): void
    {
        $candles = $this->candles(2);
        $last = $candles[1];
        $candles[1] = new Candle(
            $last->exchange,
            $last->category,
            $last->symbol,
            $last->interval,
            $last->openTimeMs(),
            $last->open,
            $last->high,
            $last->low,
            $last->close,
            $last->volume,
            $last->turnover,
            false,
        );

        $this->expectException(InvalidSeriesException::class);
        app(IndicatorEngine::class)->calculate($candles);
    }

    public function test_engine_rejects_gaps_or_unsorted_history(): void
    {
        $candles = $this->candles(3);
        [$candles[1], $candles[2]] = [$candles[2], $candles[1]];

        $this->expectException(InvalidSeriesException::class);
        app(IndicatorEngine::class)->calculate($candles);
    }

    /** @return list<Candle> */
    private function candles(int $count): array
    {
        $candles = [];
        for ($index = 0; $index < $count; $index++) {
            $close = 100 + $index;
            $candles[] = new Candle(
                'bybit',
                'linear',
                'BTCUSDT',
                Interval::FiveMinutes,
                $index * 300_000,
                (string) ($close - 1),
                (string) ($close + 2),
                (string) ($close - 2),
                (string) $close,
                (string) (10 + ($index % 7)),
                (string) ($close * (10 + ($index % 7))),
                true,
            );
        }

        return $candles;
    }
}
