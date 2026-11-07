<?php

declare(strict_types=1);

namespace Tests\Unit\Exchanges;

use App\Exchanges\Bybit\BybitAdapter;
use App\MarketData\Contracts\BybitMarketDataClient;
use App\MarketData\Domain\CandleQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BybitDiagnosticIntervalsTest extends TestCase
{
    public static function intervals(): array
    {
        return [['1m', '1'], ['3m', '3'], ['15m', '15'], ['30m', '30']];
    }

    #[DataProvider('intervals')]
    public function test_native_diagnostic_intervals_keep_bounded_public_candle_queries(string $alias, string $interval): void
    {
        $client = $this->createMock(BybitMarketDataClient::class);
        $client->expects(self::once())->method('candles')->with(self::callback(
            static fn (CandleQuery $query): bool => $query->category === 'linear'
                && $query->symbol === 'BTCUSDT' && $query->interval->value === $interval
                && $query->fromMs === 0 && $query->toMs === 1_800_000,
        ))->willReturn([]);
        self::assertSame([], (new BybitAdapter($client))->fetchCandles('BTCUSDT', $alias, 10, 0, 1_800_000));
    }
}
