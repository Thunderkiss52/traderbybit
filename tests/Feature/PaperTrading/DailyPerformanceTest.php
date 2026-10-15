<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Infrastructure\Persistence\EquitySnapshotRecord;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DailyPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_equity_returns_are_paginated_and_use_the_selected_timezone(): void
    {
        $account = $this->app->make(PaperAccountService::class)->default();
        $this->snapshot($account->id, '2026-10-10T20:59:30Z', '10000', '0', '0', 'day-one');
        $this->snapshot($account->id, '2026-10-11T20:59:30Z', '10100', '80', '20', 'day-two');
        $this->snapshot($account->id, '2026-10-12T20:59:30Z', '10050', '70', '-20', 'day-three');

        $response = $this->getJson('/api/v1/paper/daily-performance?account='.$account->id.'&timezone=Europe/Moscow&per_page=2&page=1');

        $response->assertOk()
            ->assertJsonPath('data.mode', 'FORWARD_PAPER')
            ->assertJsonPath('data.timezone', 'Europe/Moscow')
            ->assertJsonPath('data.days.meta.per_page', 2)
            ->assertJsonPath('data.days.meta.total', 3)
            ->assertJsonPath('data.days.meta.last_page', 2)
            ->assertJsonPath('data.days.data.0.date', '2026-10-12')
            ->assertJsonPath('data.days.data.0.net_pnl', '-50')
            ->assertJsonPath('data.days.data.0.realized_pnl', '-10')
            ->assertJsonPath('data.days.data.0.unrealized_pnl_change', '-40')
            ->assertJsonPath('data.days.data.0.complete', true)
            ->assertJsonPath('data.days.data.1.date', '2026-10-11')
            ->assertJsonPath('data.days.data.1.net_pnl', '100');
    }

    public function test_symbol_filter_does_not_fabricate_historical_mark_to_market_returns(): void
    {
        $account = $this->app->make(PaperAccountService::class)->default();
        $this->snapshot($account->id, '2026-10-14T23:59:30Z', '10100', '100', '0', 'one');

        $response = $this->getJson('/api/v1/paper/daily-performance?account='.$account->id.'&timezone=UTC&symbol=BTCUSDT');

        $response->assertOk()
            ->assertJsonPath('data.symbol', 'BTCUSDT')
            ->assertJsonPath('data.days.data.0.net_pnl', null)
            ->assertJsonPath('data.days.data.0.complete', false)
            ->assertJsonPath('data.days.data.0.incomplete_reasons.0', 'symbol_mark_to_market_history_unavailable')
            ->assertJsonPath('data.data_quality.uses_equity_snapshots', false);
    }

    public function test_invalid_timezone_is_rejected(): void
    {
        $account = $this->app->make(PaperAccountService::class)->default();

        $this->getJson('/api/v1/paper/daily-performance?account='.$account->id.'&timezone=local')->assertUnprocessable();
    }

    public function test_large_snapshot_history_returns_only_ten_server_aggregated_days_under_payload_limit(): void
    {
        $account = $this->app->make(PaperAccountService::class)->default();
        $at = new DateTimeImmutable('2023-01-01T23:59:30Z');
        for ($index = 0; $index < 1000; $index++) {
            $this->snapshot($account->id, $at->modify('+'.$index.' days')->format(DATE_ATOM), (string) (10000 + $index), (string) $index, '0', 'large-'.$index);
        }

        $response = $this->getJson('/api/v1/paper/daily-performance?account='.$account->id.'&timezone=UTC');
        $response->assertOk()->assertJsonCount(10, 'data.days.data')->assertJsonPath('data.days.meta.total', 1000)->assertJsonPath('data.days.meta.per_page', 10);
        self::assertLessThan(100_000, strlen($response->getContent()));
    }

    private function snapshot(string $account, string $at, string $equity, string $realized, string $unrealized, string $key): void
    {
        EquitySnapshotRecord::query()->create([
            'paper_account_id' => $account,
            'recorded_at' => new DateTimeImmutable($at),
            'equity' => $equity,
            'realized_pnl' => $realized,
            'unrealized_pnl' => $unrealized,
            'drawdown_percent' => '0',
            'source_event_key' => $key,
        ]);
    }
}
