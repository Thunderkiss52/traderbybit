<?php

declare(strict_types=1);

namespace Tests\Feature\Exchanges;

use App\MarketData\Jobs\RecoverCandleGapsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

final class BinanceGapRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private string $btcHigh = '105';

    protected function setUp(): void
    {
        parent::setUp();
        config(['market_data.symbols' => ['BTCUSDT', 'ETHUSDT']]);
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(['https://fapi.binance.com/fapi/v1/klines*' => function ($request) {
            $duration = $request['interval'] === '5m' ? 300000 : 900000;
            $open = 1767225600000; // 2026-01-01 00:00:00 UTC; aligned to both intervals.
            $high = $request['symbol'] === 'BTCUSDT' && $request['interval'] === '5m' ? $this->btcHigh : '105';

            return Http::response([[$open, '100', $high, '99', '102', '10', $open + $duration - 1, '1010']]);
        }]);
    }

    public function test_failed_scope_does_not_skip_other_scopes_and_retry_preserves_completed_rows(): void
    {
        $this->btcHigh = '90'; // Invalid OHLC fails before persistence.
        $job = new RecoverCandleGapsJob('binance');
        $this->assertRecoveryFailure($job);
        self::assertSame(3, DB::table('market_candles')->count());
        self::assertSame(1, DB::table('market_candles')->where('symbol', 'BTCUSDT')->where('interval', '15')->count());
        $completed = DB::table('market_candles')->orderBy('id')->get();
        $this->btcHigh = '105';
        $job->handle();
        $job->handle();
        self::assertSame(4, DB::table('market_candles')->where('exchange', 'binance')->count());
        self::assertEquals($completed, DB::table('market_candles')->whereIn('id', $completed->pluck('id'))->orderBy('id')->get());
        self::assertSame(0, DB::table('signals')->count());
        self::assertSame(0, DB::table('virtual_positions')->count());
        Queue::assertNothingPushed();
    }

    public function test_immutable_conflict_remains_failed_on_retry_without_rewriting_history(): void
    {
        $job = new RecoverCandleGapsJob('binance');
        $job->handle();
        $before = DB::table('market_candles')->orderBy('id')->get()->toJson();
        $this->btcHigh = '106';
        $this->assertRecoveryFailure($job);
        $this->assertRecoveryFailure($job);
        self::assertSame($before, DB::table('market_candles')->orderBy('id')->get()->toJson());
        Queue::assertNothingPushed();
    }

    private function assertRecoveryFailure(RecoverCandleGapsJob $job): void
    {
        try {
            $job->handle();
        } catch (RuntimeException $exception) {
            self::assertSame('Binance candle gap recovery incomplete: BTCUSDT:5m', $exception->getMessage());

            return;
        }
        self::fail('Failed recovery was reported as success.');
    }
}
