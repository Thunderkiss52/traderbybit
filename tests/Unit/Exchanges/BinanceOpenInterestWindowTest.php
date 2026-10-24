<?php

declare(strict_types=1);

namespace Tests\Unit\Exchanges;

use App\Exchanges\Binance\BinanceOpenInterestWindow;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DomainException;
use Tests\TestCase;

final class BinanceOpenInterestWindowTest extends TestCase
{
    public function test_only_published_contiguous_samples_contribute_to_oi_change(): void
    {
        $knownAt = new DateTimeImmutable('2026-10-23T12:12:00Z');
        $result = BinanceOpenInterestWindow::derive([
            $this->point('2026-10-23T12:00:00Z', '100'),
            $this->point('2026-10-23T12:05:00Z', '110'),
            $this->point('2026-10-23T12:20:00Z', '9999'), // Future-only mutation.
        ], 'BTCUSDT', $knownAt);

        self::assertSame('VERIFIED_SOURCE_WINDOW', $result['status']);
        self::assertTrue(BigDecimal::of($result['open_interest'])->isEqualTo('110'));
        self::assertTrue(BigDecimal::of($result['open_interest_change'])->isEqualTo('0.1'));
        self::assertSame('2026-10-23T12:05:00Z', $result['source_timestamp_utc']);
        self::assertSame(str_repeat('a', 64), $result['raw_source_hash']);
    }

    public function test_gapped_stale_missing_and_zero_denominator_remain_unavailable(): void
    {
        $at = new DateTimeImmutable('2026-10-23T12:12:00Z');
        foreach ([
            [[$this->point('2026-10-23T11:40:00Z', '100'), $this->point('2026-10-23T11:45:00Z', '110')], 'STALE_OPEN_INTEREST'],
            [[$this->point('2026-10-23T12:00:00Z', '100'), $this->point('2026-10-23T12:07:00Z', '110')], 'GAPPED_OPEN_INTEREST_HISTORY'],
            [[$this->point('2026-10-23T12:05:00Z', '110')], 'INSUFFICIENT_OPEN_INTEREST_HISTORY'],
            [[$this->point('2026-10-23T12:00:00Z', '0'), $this->point('2026-10-23T12:05:00Z', '110')], 'ZERO_OPEN_INTEREST_DENOMINATOR'],
        ] as [$rows, $reason]) {
            $result = BinanceOpenInterestWindow::derive($rows, 'BTCUSDT', $at);
            self::assertSame($reason, $result['status']);
            self::assertNull($result['open_interest_change']);
        }
    }

    public function test_cross_exchange_rows_are_never_reused(): void
    {
        $bad = $this->point('2026-10-23T12:00:00Z', '100');
        $bad['exchange_id'] = 'bybit';
        $this->expectException(DomainException::class);
        BinanceOpenInterestWindow::derive([$bad], 'BTCUSDT', new DateTimeImmutable('2026-10-23T12:10:00Z'));
    }

    private function point(string $timestamp, string $value): array
    {
        return ['exchange_id' => 'binance', 'symbol' => 'BTCUSDT', 'timestamp_utc' => $timestamp,
            'open_interest' => $value, 'raw_source_hash' => str_repeat('a', 64)];
    }
}
