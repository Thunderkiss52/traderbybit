<?php

declare(strict_types=1);

namespace Tests\Feature\Backtest;

use App\Backtest\Application\BacktestRunService;
use App\Backtest\Infrastructure\Persistence\BacktestRunRecord;
use App\Exchanges\Infrastructure\Persistence\ExchangeRecord;
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

    public function test_binance_run_optimization_and_reports_are_isolated_and_idempotent(): void
    {
        $this->seedCompleteHour();
        $this->seedCompleteHour('binance');
        config()->set('backtest.warmup_bars', 0);
        config()->set('backtest.optimization.grid', ['trend.adx_min' => ['20']]);
        config()->set('backtest.optimization.minimum_trades', 0);
        config()->set('backtest.stress.fee_multipliers', ['2']);
        config()->set('backtest.stress.slippage_multipliers', ['2']);
        $this->seedBinanceResearchCosts();
        $service = app(BacktestRunService::class);
        $from = new DateTimeImmutable('2026-01-01T00:00:00Z');
        $to = new DateTimeImmutable('2026-01-01T01:00:00Z');
        $bybit = $service->run(['BTCUSDT'], $from, $to);
        DB::enableQueryLog();
        try {
            $first = $service->run(['BTCUSDT'], $from, $to, exchange: 'binance');
            $second = $service->run(['BTCUSDT'], $from, $to, exchange: 'binance');
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $marketReads = 0;
        foreach ($queries as $query) {
            if (preg_match('/from "(market_candles|market_context_snapshots|market_funding_events)"/', $query['query'])) {
                $marketReads++;
                self::assertContains('binance', $query['bindings']);
                self::assertNotContains('bybit', $query['bindings']);
            }
        }
        self::assertGreaterThan(0, $marketReads);
        self::assertSame('COMPLETED', $first->status);
        self::assertSame($first->id, $second->id);
        self::assertNotSame($bybit->id, $first->id);
        self::assertSame('binance', $first->strategy_config['_exchange']);
        self::assertSame('0.0004', $first->execution_config['fees']['taker_rate']);
        self::assertSame('0.0002', $first->execution_config['slippage_rate']);
        self::assertSame(0, DB::table('paper_accounts')->count());
        self::assertSame(0, DB::table('virtual_positions')->count());
    }

    public function test_binance_run_rejects_missing_costs_before_persisting_a_run(): void
    {
        $this->seedCompleteHour('binance');
        config()->set('backtest.warmup_bars', 0);
        $this->seedBinanceResearchCosts(['taker_rate' => null]);
        try {
            app(BacktestRunService::class)->run(['BTCUSDT'], new DateTimeImmutable('2026-01-01T00:00:00Z'), new DateTimeImmutable('2026-01-01T01:00:00Z'), exchange: 'binance');
            self::fail('Missing costs accepted.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Historical execution cost unavailable for binance: taker_rate.', $exception->getMessage());
        }
        self::assertSame(0, BacktestRunRecord::query()->count());
        self::assertSame(0, DB::table('paper_accounts')->count());
        self::assertSame(0, DB::table('virtual_positions')->count());
    }

    public function test_binance_walk_forward_run_persists_exchange_and_walk_type(): void
    {
        $this->seedCompleteHour('binance');
        config()->set('backtest.warmup_bars', 0);
        config()->set('backtest.walk_forward', ['train_minutes' => 30, 'validation_minutes' => 15, 'test_minutes' => 15, 'step_minutes' => 15]);
        config()->set('backtest.optimization.grid', ['trend.adx_min' => ['20']]);
        config()->set('backtest.optimization.minimum_trades', 0);
        $this->seedBinanceResearchCosts();

        $from = new DateTimeImmutable('2026-01-01T00:00:00Z');
        $to = new DateTimeImmutable('2026-01-01T01:00:00Z');

        $service = app(BacktestRunService::class);
        $run = $service->run(['BTCUSDT'], $from, $to, walkForward: true, exchange: 'binance');

        self::assertSame('COMPLETED', $run->status);
        self::assertSame('walk_forward', $run->type);
        self::assertSame('binance', $run->strategy_config['_exchange']);
        self::assertSame(1, BacktestRunRecord::query()->where('type', 'walk_forward')->count());
        self::assertSame(3, DB::table('backtest_segments')->where('backtest_run_id', $run->id)->count());
    }

    public function test_lead_lag_run_rejects_missing_peer_history_before_persisting(): void
    {
        $this->seedCompleteHour();
        config()->set('backtest.warmup_bars', 0);
        $strategy = (array) config('strategy');
        $strategy['_research_strategy_id'] = 'btc_eth_lead_lag';

        try {
            app(BacktestRunService::class)->run(['BTCUSDT'], new DateTimeImmutable('2026-01-01T00:00:00Z'), new DateTimeImmutable('2026-01-01T01:00:00Z'), strategyOverride: $strategy);
            self::fail('Unbound peer history was accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('ETHUSDT', $error->getMessage());
        }
        self::assertSame(0, BacktestRunRecord::query()->count());
    }

    public function test_changed_source_during_run_fails_before_completion(): void
    {
        $this->seedCompleteHour();
        config()->set('backtest.warmup_bars', 0);
        config()->set('backtest.optimization.grid', ['trend.adx_min' => ['20']]);
        config()->set('backtest.optimization.minimum_trades', 0);
        config()->set('backtest.stress.fee_multipliers', []);
        config()->set('backtest.stress.slippage_multipliers', []);
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION vector_test_mutate_backtest_source() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                UPDATE market_candles SET close = 102
                WHERE symbol = 'BTCUSDT' AND interval = '30' AND open_time = '2026-01-01 00:30:00+00';
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER vector_test_mutate_backtest_source
            AFTER INSERT ON backtest_segments FOR EACH ROW EXECUTE FUNCTION vector_test_mutate_backtest_source();
            SQL);

        try {
            app(BacktestRunService::class)->run(['BTCUSDT'], new DateTimeImmutable('2026-01-01T00:00:00Z'), new DateTimeImmutable('2026-01-01T01:00:00Z'));
            self::fail('Changed source was published as completed.');
        } catch (\RuntimeException $error) {
            self::assertSame('Historical inputs changed during backtest.', $error->getMessage());
        }
        self::assertSame('FAILED', BacktestRunRecord::query()->firstOrFail()->status);
    }

    /** @param array<string, string|null> $overrides */
    private function seedBinanceResearchCosts(array $overrides = []): void
    {
        // Synthetic research assumptions only; these do not verify Binance fees.
        ExchangeRecord::query()->findOrFail('binance')->update([
            'market_type' => 'linear_perpetual',
            'cost_model' => array_replace([
                'version' => 'synthetic-binance-backtest-test-v1',
                'maker_rate' => '0.00013', 'taker_rate' => '0.0004', 'slippage_rate' => '0.0002',
            ], $overrides),
        ]);
        config()->set('exchanges.binance.cost_model', null);
        config()->set('exchanges.binance.paper_taker_rate', null);
        config()->set('exchanges.binance.paper_slippage_rate', null);
    }

    private function seedCompleteHour(string $exchange = 'bybit'): void
    {
        foreach ([5, 15, 30, 60] as $minutes) {
            for ($offset = 0; $offset < 60; $offset += $minutes) {
                CandleRecord::query()->create([
                    'exchange' => $exchange, 'category' => 'linear', 'symbol' => 'BTCUSDT', 'interval' => (string) $minutes,
                    'open_time' => (new DateTimeImmutable('2026-01-01T00:00:00Z'))->modify("+{$offset} minutes"),
                    'open' => '100', 'high' => '101', 'low' => '99', 'close' => '100', 'volume' => '1', 'turnover' => '100', 'is_closed' => true,
                ]);
            }
        }
    }
}
