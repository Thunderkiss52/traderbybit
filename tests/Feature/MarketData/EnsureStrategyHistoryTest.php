<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData;

use App\MarketData\Jobs\EnsureStrategyHistoryJob;
use App\MarketData\Jobs\SyncCandlesJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class EnsureStrategyHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_history_is_queued_for_every_symbol_and_timeframe(): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-10-15T12:05:00Z'));
        Queue::fake();

        (new EnsureStrategyHistoryJob)->handle();

        Queue::assertPushed(SyncCandlesJob::class, 8);
        Queue::assertPushed(SyncCandlesJob::class, static fn (SyncCandlesJob $job): bool => $job->symbol === 'BTCUSDT' && $job->interval === '60'
            && ($job->toMs - $job->fromMs) >= 220 * 3_600_000
        );
    }

    public function test_a_contiguous_warmup_window_is_not_queued_again(): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-10-15T12:05:00Z'));
        Queue::fake();
        config()->set('research.automatic.enabled', false);
        $last = new \DateTimeImmutable('2026-10-15T11:00:00Z');
        $rows = [];
        for ($i = 0; $i < 220; $i++) {
            $rows[] = [
                'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT',
                'interval' => '60', 'open_time' => $last->modify("-{$i} hours"),
                'open' => '100', 'high' => '101', 'low' => '99', 'close' => '100',
                'volume' => '100', 'turnover' => '10000', 'is_closed' => true,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        // Small batches work on both SQLite and PostgreSQL.
        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table('market_candles')->insert($chunk);
        }

        (new EnsureStrategyHistoryJob)->handle();

        Queue::assertPushed(SyncCandlesJob::class, 7);
        Queue::assertNotPushed(SyncCandlesJob::class, static fn (SyncCandlesJob $job): bool => $job->symbol === 'BTCUSDT' && $job->interval === '60'
        );
    }

    public function test_automatic_research_requests_evaluation_history_plus_warmup(): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-10-15T12:05:00Z'));
        Queue::fake();
        config()->set('market_data.symbols', ['BTCUSDT']);
        config()->set('market_data.intervals', ['60']);
        config()->set('research.automatic.evaluation_days', 14);
        config()->set('backtest.warmup_bars', 220);

        (new EnsureStrategyHistoryJob)->handle();

        Queue::assertPushed(SyncCandlesJob::class, static fn (SyncCandlesJob $job): bool => $job->symbol === 'BTCUSDT'
            && $job->interval === '60'
            && ($job->toMs - $job->fromMs) >= (220 + 14 * 24) * 3_600_000
        );
    }
}
