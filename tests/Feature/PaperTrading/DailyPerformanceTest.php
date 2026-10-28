<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\PaperTrading\Application\DailyPerformanceService;
use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Infrastructure\Persistence\EquitySnapshotRecord;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DailyPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_equity_returns_are_paginated_and_use_the_selected_timezone(): void
    {
        $account = $this->app->make(PaperAccountService::class)->default();
        EquitySnapshotRecord::query()->where('paper_account_id', $account->id)->delete();
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
            ->assertJsonPath('data.days.data.0.net_pnl', fn ($value) => is_string($value) && BigDecimal::of($value)->isEqualTo('-50'))
            ->assertJsonPath('data.days.data.0.realized_pnl', fn ($value) => is_string($value) && BigDecimal::of($value)->isEqualTo('-10'))
            ->assertJsonPath('data.days.data.0.unrealized_pnl_change', fn ($value) => is_string($value) && BigDecimal::of($value)->isEqualTo('-40'))
            ->assertJsonPath('data.days.data.0.complete', true)
            ->assertJsonPath('data.days.data.1.date', '2026-10-11')
            ->assertJsonPath('data.days.data.1.net_pnl', fn ($value) => is_string($value) && BigDecimal::of($value)->isEqualTo('100'));
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
        EquitySnapshotRecord::query()->where('paper_account_id', $account->id)->delete();
        $at = new DateTimeImmutable('2023-01-01T23:59:30Z');
        for ($index = 0; $index < 1000; $index++) {
            $this->snapshot($account->id, $at->modify('+'.$index.' days')->format(DATE_ATOM), (string) (10000 + $index), (string) $index, '0', 'large-'.$index);
        }

        $response = $this->getJson('/api/v1/paper/daily-performance?account='.$account->id.'&timezone=UTC');
        $response->assertOk()->assertJsonCount(10, 'data.days.data')->assertJsonPath('data.days.meta.total', 1000)->assertJsonPath('data.days.meta.per_page', 10);
        self::assertLessThan(100_000, strlen($response->getContent()));
    }

    public function test_aggregates_exact_fees_funding_windows_and_symbol_metrics_without_n_plus_one(): void
    {
        $this->travelTo(new DateTimeImmutable('2026-10-26T12:00:00Z'));
        $account = $this->app->make(PaperAccountService::class)->default();
        EquitySnapshotRecord::query()->where('paper_account_id', $account->id)->delete();
        $this->snapshot($account->id, '2026-10-24T23:59:30Z', '10000', '0', '0', 'prior');
        $this->snapshot($account->id, '2026-10-25T23:59:30Z', '10010.5', '10.5', '0', 'yesterday');
        $this->snapshot($account->id, '2026-10-26T11:59:30Z', '10123.5', '123.5', '0', 'today');

        $prior = $this->position($account->id, 'bybit', 'BTCUSDT', '2026-10-25T10:00:00Z', '2026-10-25T11:00:00Z', '10', '0.1');
        $winner = $this->position($account->id, 'bybit', 'BTCUSDT', '2026-10-26T09:00:00Z', '2026-10-26T10:00:00Z', '120', '1.2');
        $loser = $this->position($account->id, 'bybit', 'ETHUSDT', '2026-10-26T09:30:00Z', '2026-10-26T11:00:00Z', '-20', '-0.2');
        $this->position($account->id, 'bybit', 'BTCUSDT', '2026-10-26T10:30:00Z', null, '0', null);

        $this->execution($prior, 'ENTRY', '2026-10-25T10:00:00Z', '0', '1', 'prior-entry');
        $this->execution($prior, 'EXIT', '2026-10-25T11:00:00Z', '11', '0', 'prior-exit');
        $this->execution($winner, 'ENTRY', '2026-10-26T09:00:00Z', '0', '1', 'winner-entry');
        $this->execution($winner, 'EXIT', '2026-10-26T10:00:00Z', '130', '1', 'winner-exit');
        $this->execution($loser, 'ENTRY', '2026-10-26T09:30:00Z', '0', '1', 'loser-entry');
        $this->execution($loser, 'EXIT', '2026-10-26T11:00:00Z', '-15', '1', 'loser-exit');
        $this->funding($prior, 'bybit', 'BTCUSDT', '2026-10-25T10:30:00Z', '0.5');
        $this->funding($winner, 'bybit', 'BTCUSDT', '2026-10-26T10:30:00Z', '3');
        $this->funding($loser, 'bybit', 'ETHUSDT', '2026-10-26T11:30:00Z', '-1');

        $service = $this->app->make(DailyPerformanceService::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $report = $service->report($account->id, 'UTC', null, 1, 10);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        self::assertLessThanOrEqual(8, $queryCount);
        $today = $report['summary']['today'];
        self::assertTrue($today['complete']);
        self::assertSame('113.000000000000', $today['net_pnl']);
        self::assertSame('113.000000000000', $today['realized_pnl']);
        self::assertSame('4.000000000000', $today['commissions']);
        self::assertSame('2.000000000000', $today['funding']);
        self::assertSame(3, $today['opened_positions']);
        self::assertSame(2, $today['closed_positions']);
        self::assertSame('50.00000000', $today['win_rate']);
        self::assertSame('0.50000000000000000000', $today['average_r']);
        self::assertSame('113.000000000000', $report['days']['data'][0]['net_pnl']);

        $yesterday = $report['summary']['yesterday'];
        self::assertTrue($yesterday['complete']);
        self::assertSame('10.500000000000', $yesterday['net_pnl']);
        self::assertSame(1, $yesterday['closed_positions']);
        self::assertSame('100.00000000', $yesterday['win_rate']);

        $btc = $service->report($account->id, 'UTC', 'BTCUSDT', 1, 10);
        $btcToday = $btc['summary']['today'];
        self::assertNull($btcToday['net_pnl']);
        self::assertSame('131.000000000000', $btcToday['realized_pnl']);
        self::assertSame('2.000000000000', $btcToday['commissions']);
        self::assertSame('3.000000000000', $btcToday['funding']);
        self::assertSame(2, $btcToday['opened_positions']);
        self::assertSame(1, $btcToday['closed_positions']);
        self::assertSame('1.20000000000000000000', $btcToday['average_r']);
        self::assertContains('symbol_mark_to_market_history_unavailable', $btcToday['incomplete_reasons']);
    }

    public function test_missing_opening_is_not_cumulative_daily_profit_and_csv_semantics_match(): void
    {
        $this->travelTo(new DateTimeImmutable('2026-10-20T23:59:45Z'));
        $account = app(PaperAccountService::class)->default();
        $account->update(['created_at' => new DateTimeImmutable('2026-10-01T00:00:00Z')]);
        EquitySnapshotRecord::query()->where('paper_account_id', $account->id)->delete();
        $this->snapshot($account->id, '2026-10-20T23:59:30Z', '12345', '2345', '0', 'closing-only');
        $service = app(DailyPerformanceService::class);
        $report = $service->report($account->id, 'UTC', null, 1, 10);
        $day = $report['days']['data'][0];
        self::assertNull($day['net_pnl']);
        self::assertNull($day['realized_pnl']);
        self::assertFalse($day['complete']);
        self::assertContains('opening_snapshot_unavailable', $day['incomplete_reasons']);
        self::assertNotContains('closing_equity_snapshot_not_near_period_end', $day['incomplete_reasons']);
        $csv = $service->calendarDays($account->id, 'UTC', CarbonImmutable::parse('2026-10-20T00:00:00Z'), CarbonImmutable::parse('2026-10-21T00:00:00Z'));
        self::assertNull($csv[0]['net_pnl']);
        self::assertFalse($csv[0]['complete']);
        self::assertDecimalSame('2345', $report['summary']['all_time']['net_pnl']);
    }

    public function test_account_inception_is_a_justified_opening_boundary(): void
    {
        $this->travelTo(new DateTimeImmutable('2026-10-20T12:00:00Z'));
        $account = app(PaperAccountService::class)->default();
        $this->snapshot($account->id, '2026-10-20T12:00:00Z', '10005', '5', '0', 'inception-close');
        $day = app(DailyPerformanceService::class)->report($account->id, 'UTC', null, 1, 10)['summary']['today'];
        self::assertTrue($day['complete']);
        self::assertSame('ACCOUNT_INCEPTION', $day['opening_boundary']);
        self::assertDecimalSame('5', $day['net_pnl']);
    }

    public function test_f23_pagination_across_pages_guarantees_stable_order_and_zero_loss(): void
    {
        $account = app(PaperAccountService::class)->default();
        EquitySnapshotRecord::query()->where('paper_account_id', $account->id)->delete();

        // Seed 25 distinct daily snapshots
        $baseDate = new DateTimeImmutable('2026-01-01T23:59:30Z');
        for ($i = 0; $i < 25; $i++) {
            $dt = $baseDate->modify("+{$i} days");
            $this->snapshot(
                $account->id,
                $dt->format(DATE_ATOM),
                (string) (10000 + ($i * 100)),
                (string) ($i * 50),
                '0',
                "f23-page-{$i}"
            );
        }

        $allDates = [];

        // Request Page 1 (per_page = 10)
        $res1 = $this->getJson('/api/v1/paper/daily-performance?account='.$account->id.'&timezone=UTC&per_page=10&page=1');
        $res1->assertOk()
            ->assertJsonPath('data.days.meta.current_page', 1)
            ->assertJsonPath('data.days.meta.per_page', 10)
            ->assertJsonPath('data.days.meta.last_page', 3)
            ->assertJsonPath('data.days.meta.total', 25)
            ->assertJsonCount(10, 'data.days.data');

        $page1Dates = collect($res1->json('data.days.data'))->pluck('date')->all();
        $allDates = array_merge($allDates, $page1Dates);

        // Request Page 2 (per_page = 10)
        $res2 = $this->getJson('/api/v1/paper/daily-performance?account='.$account->id.'&timezone=UTC&per_page=10&page=2');
        $res2->assertOk()
            ->assertJsonPath('data.days.meta.current_page', 2)
            ->assertJsonPath('data.days.meta.per_page', 10)
            ->assertJsonPath('data.days.meta.last_page', 3)
            ->assertJsonPath('data.days.meta.total', 25)
            ->assertJsonCount(10, 'data.days.data');

        $page2Dates = collect($res2->json('data.days.data'))->pluck('date')->all();
        $allDates = array_merge($allDates, $page2Dates);

        // Request Page 3 (per_page = 10)
        $res3 = $this->getJson('/api/v1/paper/daily-performance?account='.$account->id.'&timezone=UTC&per_page=10&page=3');
        $res3->assertOk()
            ->assertJsonPath('data.days.meta.current_page', 3)
            ->assertJsonPath('data.days.meta.per_page', 10)
            ->assertJsonPath('data.days.meta.last_page', 3)
            ->assertJsonPath('data.days.meta.total', 25)
            ->assertJsonCount(5, 'data.days.data');

        $page3Dates = collect($res3->json('data.days.data'))->pluck('date')->all();
        $allDates = array_merge($allDates, $page3Dates);

        // Assert all 25 rows retrieved without omission or truncation
        self::assertCount(25, $allDates);
        self::assertCount(25, array_unique($allDates), 'All 25 paginated dates across pages must be unique (no duplicates)');

        // Assert strictly monotonic descending stable order across page boundaries
        $sortedDates = $allDates;
        rsort($sortedDates);
        self::assertSame($sortedDates, $allDates, 'Pagination must maintain stable chronological descending order across pages');
    }

    private function position(string $accountId, string $exchange, string $symbol, string $openedAt, ?string $closedAt, string $netPnl, ?string $rMultiple): string
    {
        $id = (string) Str::uuid();
        $signalId = (string) Str::uuid();
        $at = new DateTimeImmutable($openedAt);
        DB::table('signals')->insert([
            'id' => $signalId, 'exchange' => $exchange, 'category' => 'linear', 'symbol' => $symbol,
            'timeframe' => '15', 'side' => 'BUY', 'quality_class' => 'A', 'score' => 90,
            'decision_time' => $at, 'candle_close_time' => $at, 'expires_at' => $at->modify('+1 hour'),
            'maximum_holding_minutes' => 240, 'reasons' => json_encode([]), 'risks' => json_encode([]),
            'indicator_snapshot' => json_encode([]), 'contract' => json_encode([]),
            'strategy_version' => 'test-v1', 'schema_version' => '1.0.0', 'strategy_id' => 'momentum',
            'created_at' => $at, 'updated_at' => $at,
        ]);
        DB::table('virtual_positions')->insert([
            'id' => $id, 'paper_account_id' => $accountId,
            'signal_id' => $signalId, 'symbol' => $symbol, 'side' => 'BUY', 'quality_class' => 'A',
            'status' => $closedAt === null ? 'OPEN' : 'CLOSED', 'entry_price' => '100', 'stop_loss' => '90',
            'take_profit_1' => '110', 'take_profit_2' => '120', 'initial_quantity' => '1', 'remaining_quantity' => '1',
            'qty_step' => '0.1', 'risk_capital' => '100', 'gross_pnl' => $netPnl, 'net_pnl' => $netPnl,
            'unrealized_pnl' => '0', 'fees' => '0', 'funding' => '0', 'r_multiple' => $rMultiple,
            'opened_at' => $at, 'maximum_holding_until' => $at->modify('+4 hours'),
            'closed_at' => $closedAt === null ? null : new DateTimeImmutable($closedAt),
            'execution_model' => 'test', 'strategy_version' => 'test-v1', 'risk_version' => 'test-v1',
            'execution_version' => 'test-v1', 'schema_version' => '1.0.0', 'created_at' => $at, 'updated_at' => $at,
        ]);

        return $id;
    }

    private function execution(string $positionId, string $type, string $at, string $gross, string $commission, string $key): void
    {
        $timestamp = new DateTimeImmutable($at);
        DB::table('virtual_executions')->insert([
            'id' => (string) Str::uuid(), 'position_id' => $positionId, 'type' => $type, 'side' => 'BUY',
            'quantity' => '1', 'price' => '100', 'gross_pnl' => $gross, 'commission' => $commission, 'slippage' => '0',
            'market_event_at' => $timestamp, 'registered_at' => $timestamp, 'price_source' => 'test',
            'idempotency_key' => $key, 'execution_version' => 'test-v1', 'schema_version' => '1.0.0',
            'created_at' => $timestamp, 'updated_at' => $timestamp,
        ]);
    }

    private function funding(string $positionId, string $exchange, string $symbol, string $at, string $amount): void
    {
        $timestamp = new DateTimeImmutable($at);
        $marketId = DB::table('market_funding_events')->insertGetId([
            'exchange' => $exchange, 'category' => 'linear', 'symbol' => $symbol, 'funding_at' => $timestamp,
            'rate' => '0.0001', 'mark_price' => '100', 'created_at' => $timestamp, 'updated_at' => $timestamp,
        ]);
        DB::table('position_funding_events')->insert([
            'id' => (string) Str::uuid(), 'position_id' => $positionId, 'market_funding_event_id' => $marketId,
            'quantity' => '1', 'mark_price' => '100', 'rate' => '0.0001', 'amount' => $amount,
            'funding_at' => $timestamp, 'created_at' => $timestamp, 'updated_at' => $timestamp,
        ]);
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
