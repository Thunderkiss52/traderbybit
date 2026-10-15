<?php

declare(strict_types=1);

namespace Tests\Feature\Backtest;

use App\Backtest\Application\BacktestRunService;
use App\Backtest\Infrastructure\Persistence\BacktestRunRecord;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class BacktestRunServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_run_is_persisted_idempotently_without_touching_paper_state(): void
    {
        $this->seedCompleteHour();
        config()->set('backtest.warmup_bars', 0);
        config()->set('backtest.optimization.grid', ['trend.adx_min' => ['20']]);
        config()->set('backtest.optimization.minimum_trades', 0);
        config()->set('backtest.stress.fee_multipliers', []);
        config()->set('backtest.stress.slippage_multipliers', []);
        $service = app(BacktestRunService::class);
        $from = new DateTimeImmutable('2026-01-01T00:00:00Z');
        $to = new DateTimeImmutable('2026-01-01T01:00:00Z');
        $first = $service->run(['BTCUSDT'], $from, $to);
        $second = $service->run(['BTCUSDT'], $from, $to);

        self::assertSame('COMPLETED', $first->status);
        self::assertSame($first->id, $second->id);
        self::assertSame(1, BacktestRunRecord::query()->count());
        self::assertSame(3, DB::table('backtest_segments')->count());
        self::assertSame(0, DB::table('signals')->count());
        self::assertSame(0, DB::table('paper_accounts')->count());
        $first->update(['status' => 'RUNNING']);
        self::assertTrue($service->requestCancellation($first->id));
        self::assertSame('CANCEL_REQUESTED', $first->fresh()->status);
    }

    private function seedCompleteHour(): void
    {
        foreach ([5, 15, 60] as $minutes) {
            for ($offset = 0; $offset < 60; $offset += $minutes) {
                CandleRecord::query()->create([
                    'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'interval' => (string) $minutes,
                    'open_time' => (new DateTimeImmutable('2026-01-01T00:00:00Z'))->modify("+{$offset} minutes"),
                    'open' => '100', 'high' => '101', 'low' => '99', 'close' => '100', 'volume' => '1', 'turnover' => '100', 'is_closed' => true,
                ]);
            }
        }
    }
}
