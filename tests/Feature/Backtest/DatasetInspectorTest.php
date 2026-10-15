<?php

declare(strict_types=1);

namespace Tests\Feature\Backtest;

use App\Backtest\Application\DatasetInspector;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class DatasetInspectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_checksum_is_deterministic_for_complete_closed_history(): void
    {
        $this->candle('5', '2026-01-01 00:00:00+00:00');
        $this->candle('5', '2026-01-01 00:05:00+00:00');
        $this->candle('5', '2026-01-01 00:10:00+00:00');
        $this->candle('15', '2026-01-01 00:00:00+00:00');
        $this->candle('60', '2026-01-01 00:00:00+00:00');
        $service = new DatasetInspector;
        $first = $service->inspect(['BTCUSDT'], new DateTimeImmutable('2026-01-01T00:00:00Z'), new DateTimeImmutable('2026-01-01T00:15:00Z'), 0);
        $second = $service->inspect(['BTCUSDT'], new DateTimeImmutable('2026-01-01T00:00:00Z'), new DateTimeImmutable('2026-01-01T00:15:00Z'), 0);
        self::assertSame($first['checksum'], $second['checksum']);
        self::assertSame([], $first['warnings']);
    }

    public function test_gap_is_rejected_instead_of_fabricating_a_price(): void
    {
        $this->candle('5', '2026-01-01 00:00:00+00:00');
        $this->candle('5', '2026-01-01 00:10:00+00:00');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('History gap');
        (new DatasetInspector)->inspect(['BTCUSDT'], new DateTimeImmutable('2026-01-01T00:00:00Z'), new DateTimeImmutable('2026-01-01T00:15:00Z'), 0);
    }

    private function candle(string $interval, string $time): void
    {
        CandleRecord::query()->create(['exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'interval' => $interval, 'open_time' => $time, 'open' => '100', 'high' => '101', 'low' => '99', 'close' => '100', 'volume' => '1', 'turnover' => '100', 'is_closed' => true]);
    }
}
