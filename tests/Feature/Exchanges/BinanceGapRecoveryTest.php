<?php

declare(strict_types=1);

namespace Tests\Feature\Exchanges;

use App\MarketData\Jobs\RecoverCandleGapsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\CleansCandleConflictEvidence;
use Tests\TestCase;

final class BinanceGapRecoveryTest extends TestCase
{
    use CleansCandleConflictEvidence;
    use RefreshDatabase;

    private string $btcHigh = '105';

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->registerConflictCleanup();
        config([
            'market_data.symbols' => ['BTCUSDT', 'ETHUSDT'],
            'market_data.sync.backfill_history_ms' => 4 * 60 * 60 * 1000,
            'exchanges.binance.automatic_candle_recovery_enabled' => true,
            // This fixture verifies historical repair. Fresh 15m bars can also
            // dispatch forward pipeline work depending on wall-clock alignment.
            'trading.pipeline_mode' => 'SCHEDULED',
        ]);
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(['https://fapi.binance.com/fapi/v1/klines*' => function ($request) {
            $duration = ['5m' => 300000, '15m' => 900000, '30m' => 1800000, '1h' => 3600000, '4h' => 14400000][$request['interval']];
            $high = $request['symbol'] === 'BTCUSDT' && $request['interval'] === '5m' ? $this->btcHigh : '105';
            $rows = [];
            for ($open = (int) $request['startTime']; $open <= (int) $request['endTime']; $open += $duration) {
                $rows[] = [$open, '100', $high, '99', '102', '10', $open + $duration - 1, '1010'];
            }

            return Http::response($rows);
        }]);
    }

    public function test_automatic_binance_recovery_requires_explicit_opt_in(): void
    {
        config(['exchanges.binance.automatic_candle_recovery_enabled' => false]);

        (new RecoverCandleGapsJob('binance'))->handle();

        self::assertSame(0, DB::table('market_candles')->count());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_failed_scope_does_not_skip_other_scopes_and_retry_preserves_completed_rows(): void
    {
        $this->btcHigh = '90'; // Invalid OHLC fails before persistence.
        $job = new RecoverCandleGapsJob('binance');
        $this->assertRecoveryFailure($job);
        self::assertGreaterThan(3, DB::table('market_candles')->count());
        self::assertSame(16, DB::table('market_candles')->where('symbol', 'BTCUSDT')->where('interval', '15')->count());
        $completed = DB::table('market_candles')->orderBy('id')->get();
        $this->btcHigh = '105';
        $job->handle();
        $job->handle();
        self::assertSame(48, DB::table('market_candles')->where('exchange', 'binance')->where('symbol', 'BTCUSDT')->where('interval', '5')->count());
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
