<?php

declare(strict_types=1);

namespace Tests\Feature\Backtest;

use App\Backtest\Application\DatasetInspector;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Infrastructure\Persistence\MarketFundingEventRecord;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class DatasetInspectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_checksum_is_deterministic_for_complete_closed_history(): void
    {
        $this->completeHour();
        $service = new DatasetInspector;
        $first = $this->inspectHour($service);
        $second = $this->inspectHour($service);
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

    public function test_binance_does_not_use_bybit_history(): void
    {
        $this->completeHour();
        $service = new DatasetInspector;
        $from = new DateTimeImmutable('2026-01-01T00:00:00Z');
        $to = new DateTimeImmutable('2026-01-01T01:00:00Z');
        self::assertSame($service->inspect(['BTCUSDT'], $from, $to, 0), $service->inspect(['BTCUSDT'], $from, $to, 0, 'bybit'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No closed 5m history');
        $service->inspect(['BTCUSDT'], $from, $to, 0, 'binance');
    }

    public function test_configured_thirty_minute_history_is_required_and_hashed(): void
    {
        $this->completeHour(false);
        $service = new DatasetInspector;
        try {
            $this->inspectHour($service);
            self::fail('Missing 30m candles were accepted.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('30m', $error->getMessage());
        }
        $this->candle('30', '2026-01-01 00:00:00+00:00');
        $this->candle('30', '2026-01-01 00:30:00+00:00');
        $before = $this->inspectHour($service)['checksum'];
        CandleRecord::query()->where('interval', '30')->where('open_time', '2026-01-01 00:30:00+00:00')->update(['close' => '101']);
        self::assertNotSame($before, $this->inspectHour($service)['checksum']);
    }

    public function test_prior_context_and_subsecond_observation_time_affect_checksum(): void
    {
        $this->completeHour();
        $service = new DatasetInspector;
        $baseline = $this->inspectHour($service)['checksum'];
        $context = $this->context('2025-12-31 23:59:59.100+00:00');
        $first = $this->inspectHour($service)['checksum'];
        self::assertNotSame($baseline, $first);
        $context->update(['observed_at' => '2025-12-31 23:59:59.200+00:00']);
        self::assertNotSame($first, $this->inspectHour($service)['checksum']);
    }

    public function test_close_boundary_context_and_funding_are_hashed_but_later_events_are_not(): void
    {
        $this->completeHour();
        $service = new DatasetInspector;
        $baseline = $this->inspectHour($service)['checksum'];
        $this->context('2026-01-01 01:00:00+00:00');
        $this->funding('2026-01-01 01:00:00+00:00');
        $atClose = $this->inspectHour($service)['checksum'];
        self::assertNotSame($baseline, $atClose);
        $this->context('2026-01-01 01:00:00.001+00:00');
        $this->funding('2026-01-01 01:00:00.001+00:00');
        self::assertSame($atClose, $this->inspectHour($service)['checksum']);
    }

    public function test_subsecond_right_boundary_does_not_pass_as_complete(): void
    {
        $this->completeHour();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid historical dataset scope');
        (new DatasetInspector)->inspect(['BTCUSDT'], new DateTimeImmutable('2026-01-01T00:00:00Z'), new DateTimeImmutable('2026-01-01T01:00:00.001Z'), 0);
    }

    public function test_partial_higher_timeframe_end_and_non_hour_start_use_only_fully_closed_bars(): void
    {
        $this->completeHour();
        foreach (['01:00', '01:05', '01:10'] as $time) {
            $this->candle('5', "2026-01-01 {$time}:00+00:00");
        }
        $this->candle('15', '2026-01-01 01:00:00+00:00');
        $service = new DatasetInspector;
        $to = new DateTimeImmutable('2026-01-01T01:15:00Z');
        $full = $service->inspect(['BTCUSDT'], new DateTimeImmutable('2026-01-01T00:00:00Z'), $to, 0);
        $shifted = $service->inspect(['BTCUSDT'], new DateTimeImmutable('2026-01-01T00:15:00Z'), $to, 0);
        self::assertSame([], $full['warnings']);
        self::assertSame([], $shifted['warnings']);
        self::assertNotSame($full['checksum'], $shifted['checksum']);
    }

    public function test_utc_offsets_resolve_to_the_same_window_and_warmup_is_hashed(): void
    {
        $this->completeHour();
        foreach (['5' => '23:55', '15' => '23:45', '30' => '23:30', '60' => '23:00'] as $interval => $time) {
            $this->candle((string) $interval, "2025-12-31 {$time}:00+00:00");
        }
        $service = new DatasetInspector;
        $utc = $service->inspect(['BTCUSDT'], new DateTimeImmutable('2026-01-01T00:00:00Z'), new DateTimeImmutable('2026-01-01T01:00:00Z'), 1);
        $offset = $service->inspect(['BTCUSDT'], new DateTimeImmutable('2026-01-01T03:00:00+03:00'), new DateTimeImmutable('2026-01-01T04:00:00+03:00'), 1);
        self::assertSame($utc, $offset);
        self::assertSame([], $utc['warnings']);

        CandleRecord::query()->where('interval', '30')->where('open_time', '2025-12-31 23:30:00+00:00')->update(['close' => '102']);
        self::assertNotSame($utc['checksum'], $service->inspect(['BTCUSDT'], new DateTimeImmutable('2026-01-01T00:00:00Z'), new DateTimeImmutable('2026-01-01T01:00:00Z'), 1)['checksum']);
    }

    public function test_prior_hourly_bar_is_hashed_even_with_zero_requested_warmup(): void
    {
        $this->completeHour();
        $service = new DatasetInspector;
        $baseline = $this->inspectHour($service)['checksum'];
        $this->candle('60', '2025-12-31 23:00:00+00:00');
        $withPrior = $this->inspectHour($service)['checksum'];
        self::assertNotSame($baseline, $withPrior);
        CandleRecord::query()->where('interval', '60')->where('open_time', '2025-12-31 23:00:00+00:00')->update(['close' => '102']);
        self::assertNotSame($withPrior, $this->inspectHour($service)['checksum']);
    }

    public function test_candle_at_to_is_excluded_and_missing_from_is_rejected(): void
    {
        $this->completeHour();
        $service = new DatasetInspector;
        $baseline = $this->inspectHour($service)['checksum'];
        $this->candle('5', '2026-01-01 01:00:00+00:00');
        self::assertSame($baseline, $this->inspectHour($service)['checksum']);
        CandleRecord::query()->where('interval', '5')->where('open_time', '2026-01-01 00:00:00+00:00')->delete();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Incomplete requested range');
        $this->inspectHour($service);
    }

    public function test_context_and_funding_preserve_offset_and_millisecond_precision(): void
    {
        $context = $this->context('2026-01-01 04:00:00.001+03:00');
        $funding = $this->funding('2026-01-01 04:00:00.001+03:00');
        self::assertSame('2026-01-01 01:00:00.001', $context->fresh()->observed_at->utc()->format('Y-m-d H:i:s.v'));
        self::assertSame('2026-01-01 01:00:00.001', $funding->fresh()->funding_at->utc()->format('Y-m-d H:i:s.v'));
    }

    private function completeHour(bool $includeThirty = true): void
    {
        foreach ([5, 15, 30, 60] as $minutes) {
            if ($minutes === 30 && ! $includeThirty) {
                continue;
            }
            for ($offset = 0; $offset < 60; $offset += $minutes) {
                $this->candle((string) $minutes, (new DateTimeImmutable('2026-01-01T00:00:00Z'))->modify("+{$offset} minutes")->format('Y-m-d H:i:sP'));
            }
        }
    }

    private function inspectHour(DatasetInspector $service): array
    {
        return $service->inspect(['BTCUSDT'], new DateTimeImmutable('2026-01-01T00:00:00Z'), new DateTimeImmutable('2026-01-01T01:00:00Z'), 0);
    }

    private function context(string $time): MarketContextRecord
    {
        return MarketContextRecord::query()->create(['exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'observed_at' => $time, 'bid' => '100', 'ask' => '101', 'turnover_24h' => '100000000', 'tick_size' => '0.01']);
    }

    private function funding(string $time): MarketFundingEventRecord
    {
        return MarketFundingEventRecord::query()->create(['exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'funding_at' => $time, 'rate' => '0.0001', 'mark_price' => '100']);
    }

    private function candle(string $interval, string $time): void
    {
        CandleRecord::query()->create(['exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'interval' => $interval, 'open_time' => $time, 'open' => '100', 'high' => '101', 'low' => '99', 'close' => '100', 'volume' => '1', 'turnover' => '100', 'is_closed' => true]);
    }
}
