<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Application\PaperProcessingService;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Process\Process;
use Tests\Concerns\SeedsValidatedPaperEvidence;
use Tests\TestCase;

final class PaperPipelineConcurrentWorkersIntegrationTest extends TestCase
{
    use SeedsValidatedPaperEvidence;

    public function test_two_workers_commit_one_fill_and_one_financial_journal_sequence(): void
    {
        $this->ensurePostgresTestDatabaseReady('paper_accounts');
        config(['cache.default' => 'redis', 'trading.pipeline_mode' => 'EVENT_DRIVEN', 'trading.freshness_seconds' => 3600]);
        Cache::purge('redis');

        $processTime = CarbonImmutable::now('UTC')->startOfMinute();
        $processTime = $processTime->subMinutes($processTime->minute % 5);
        $decisionTime = $processTime->subMinutes(5);
        $strategy = app(StrategyRegistry::class)->get('baseline');
        $account = app(PaperAccountService::class)->default();
        $accountOriginalData = (array) DB::table('paper_accounts')->where('id', $account->id)->first();
        DB::table('paper_accounts')->where('id', $account->id)->update([
            'created_at' => $decisionTime->subMinutes(10),
            'sizing_settings' => json_encode(['mode' => 'fixed_notional', 'amount' => '100']),
        ]);
        $signalId = Uuid::uuid4()->toString();
        $barrier = sys_get_temp_dir().'/vector-paper-race-'.bin2hex(random_bytes(8));
        $workers = [];
        $contextId = null;
        $candleId = null;
        $evidenceAccount = null;

        try {
            CarbonImmutable::setTestNow($decisionTime);
            $contextId = MarketContextRecord::query()->create([
                'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT',
                'observed_at' => $decisionTime, 'funding_rate' => '0.0001',
                'open_interest' => '50000', 'open_interest_change' => '0.01',
                'turnover_24h' => '100000000', 'tick_size' => '0.1',
                'bid' => '100.0', 'ask' => '100.1', 'qty_step' => '0.001',
                'min_order_qty' => '0.001', 'max_order_qty' => '100.0',
                'min_notional_value' => '5.0',
            ])->id;
            $signal = SignalRecord::query()->create([
                'id' => $signalId, 'exchange' => 'bybit', 'category' => 'linear',
                'symbol' => 'BTCUSDT', 'timeframe' => '15', 'strategy_id' => 'baseline',
                'strategy_version' => $strategy->version(), 'schema_version' => '1.0.0',
                'side' => 'BUY', 'quality_class' => 'A', 'score' => 85,
                'model_risk_percent' => '0.50', 'entry_low' => '100.0',
                'entry_high' => '101.0', 'stop_loss' => '95.0',
                'take_profit_1' => '108.0', 'take_profit_2' => '115.0',
                'risk_reward_1' => '1.5', 'risk_reward_2' => '2.5',
                'decision_time' => $decisionTime, 'candle_close_time' => $decisionTime,
                'maximum_holding_minutes' => 240,
                'expires_at' => $decisionTime->addMinutes(30),
                'reasons' => ['trend' => true], 'risks' => [],
                'indicator_snapshot' => ['strategy_id' => 'baseline', '15m' => ['atr_14' => '2.0', 'close' => '100.0']],
                'contract' => ['version' => '1.0.0'],
            ]);
            $evidenceAccount = $this->seedValidatedPaperEvidence($signal);
            $existingOtherAccounts = DB::table('paper_accounts')->where('id', '!=', $account->id)->orderBy('id')->get()->toJson();
            $candleId = CandleRecord::query()->create([
                'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT',
                'interval' => '5', 'open_time' => $decisionTime,
                'open' => '100.5', 'high' => '101.2', 'low' => '100.2',
                'close' => '100.8', 'volume' => '100.0', 'turnover' => '10000.0',
                'is_closed' => true,
            ])->id;
            app(PaperProcessingService::class)->process($account->id, 'BTCUSDT', $decisionTime, historical: false);
            self::assertSame(0, $this->executionCount($account->id));
            CarbonImmutable::setTestNow(null);

            $environment = [
                'APP_ENV' => 'testing',
                'CACHE_STORE' => 'redis',
                'REDIS_HOST' => (string) (env('REDIS_HOST') ?: config('database.redis.default.host', '127.0.0.1')),
                'REDIS_PORT' => (string) (env('REDIS_PORT') ?: config('database.redis.default.port', '6379')),
                'REDIS_PASSWORD' => (string) (env('REDIS_PASSWORD') ?: config('database.redis.default.password')),
                'DB_CONNECTION' => 'pgsql',
                'DB_HOST' => (string) DB::connection()->getConfig('host'),
                'DB_PORT' => (string) DB::connection()->getConfig('port'),
                'DB_DATABASE' => 'bybit_test',
                'DB_USERNAME' => (string) DB::connection()->getConfig('username'),
                'DB_PASSWORD' => (string) DB::connection()->getConfig('password'),
                'TRADING_FRESHNESS_SECONDS' => '3600',
            ];
            for ($index = 0; $index < 2; $index++) {
                $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/run_paper_pipeline_at_barrier.php'),
                    $processTime->toIso8601String(), $account->id, $barrier], base_path(), $environment);
                $worker->setTimeout(20);
                $worker->start();
                $workers[] = $worker;
                self::assertTrue($worker->waitUntil(static fn ($type, $output): bool => str_contains($output, 'READY')), $worker->getErrorOutput());
            }
            touch($barrier);
            foreach ($workers as $worker) {
                $worker->wait();
                self::assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                self::assertStringContainsString('DONE', $worker->getOutput());
            }

            self::assertSame(1, DB::table('virtual_positions')->where('paper_account_id', $account->id)->count());
            self::assertSame(1, $this->executionCount($account->id));
            self::assertSame(1, DB::table('trading_journal_events')->where('paper_account_id', $account->id)->where('event_type', 'POSITION_OPENED')->count());
            self::assertSame(1, DB::table('trading_pipeline_events')->where('stage', 'PAPER_PROCESSED')->where('symbol', 'BTCUSDT')->where('candle_close_at', $processTime)->count());
            self::assertSame(0, DB::table('trading_pipeline_events')->where('stage', 'PAPER_PROCESSED')->where('status', 'FAILED')->where('candle_close_at', $processTime)->count());
            self::assertSame($existingOtherAccounts, DB::table('paper_accounts')->where('id', '!=', $account->id)->orderBy('id')->get()->toJson());
        } finally {
            CarbonImmutable::setTestNow(null);
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(0, SIGKILL);
                }
            }
            @unlink($barrier);
            // Child processes need committed fixtures. Remove only this test's rows.
            DB::table('trading_pipeline_events')->where('symbol', 'BTCUSDT')->where('candle_close_at', $processTime)->delete();
            $positionIds = DB::table('virtual_positions')->where('paper_account_id', $account->id)->pluck('id');
            DB::table('virtual_executions')->whereIn('position_id', $positionIds)->delete();
            DB::table('virtual_positions')->where('paper_account_id', $account->id)->delete();
            DB::table('paper_signal_states')->where('paper_account_id', $account->id)->where('signal_id', $signalId)->delete();
            DB::table('trading_journal_events')->where('paper_account_id', $account->id)->delete();
            DB::table('paper_accounts')->where('id', $account->id)->update($accountOriginalData);
            SignalRecord::query()->whereKey($signalId)->delete();
            if ($candleId !== null) {
                CandleRecord::query()->whereKey($candleId)->delete();
            }
            if ($contextId !== null) {
                MarketContextRecord::query()->whereKey($contextId)->delete();
            }
            if ($evidenceAccount !== null) {
                $evidenceSignalIds = DB::table('virtual_positions')->where('paper_account_id', $evidenceAccount->id)->pluck('signal_id');
                $evidenceAccount->delete();
                SignalRecord::query()->whereIn('id', $evidenceSignalIds)->delete();
            }
        }
    }

    private function executionCount(string $accountId): int
    {
        return DB::table('virtual_executions')->join('virtual_positions', 'virtual_executions.position_id', '=', 'virtual_positions.id')
            ->where('virtual_positions.paper_account_id', $accountId)->count();
    }
}
