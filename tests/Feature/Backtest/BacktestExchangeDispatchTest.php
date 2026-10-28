<?php

declare(strict_types=1);

namespace Tests\Feature\Backtest;

use App\Backtest\Application\BacktestRunService;
use App\Backtest\Infrastructure\Persistence\BacktestRunRecord;
use App\Backtest\Jobs\RunBacktestJob;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

final class BacktestExchangeDispatchTest extends TestCase
{
    public function test_worker_passes_exchange_to_run_service(): void
    {
        $service = \Mockery::mock(BacktestRunService::class);
        $service->shouldReceive('run')->once()->withArgs(fn ($symbols, $from, $to, $walk, $override, $exchange): bool => $symbols === ['BTCUSDT'] && $exchange === 'binance' && $walk === true && $override === null)->andReturn(new BacktestRunRecord);
        (new RunBacktestJob(['BTCUSDT'], '2026-01-01', '2026-01-02', true, 'binance'))->handle($service);
    }

    public function test_commands_preserve_exchange_and_walk_forward_selection(): void
    {
        foreach (['backtest:run' => false, 'backtest:walk-forward' => true] as $command => $walk) {
            foreach (['bybit', 'binance'] as $exchange) {
                Bus::fake();
                $this->artisan($command, ['--from' => '2026-01-01', '--to' => '2026-01-02', '--exchange' => $exchange, '--queue' => true])->assertSuccessful();
                Bus::assertDispatched(RunBacktestJob::class, fn ($job): bool => $job->exchange === $exchange && $job->walkForward === $walk && $job->queue === 'backtests');
            }
        }
    }

    public function test_default_and_legacy_queued_jobs_remain_bybit(): void
    {
        $job = new RunBacktestJob(['BTCUSDT'], '2026-01-01', '2026-01-02');
        self::assertSame('bybit', $job->exchange);
        $payload = (array) $job;
        unset($payload['exchange']);
        $legacy = (new \ReflectionClass(RunBacktestJob::class))->newInstanceWithoutConstructor();
        foreach ($payload as $name => $value) {
            (new \ReflectionProperty($legacy, $name))->setValue($legacy, $value);
        }
        self::assertSame('bybit', unserialize(serialize($legacy))->exchange);
    }

    public function test_invalid_exchange_is_rejected_before_dispatch(): void
    {
        Bus::fake();
        $this->artisan('backtest:run', ['--from' => '2026-01-01', '--to' => '2026-01-02', '--exchange' => 'other', '--queue' => true])->assertFailed();
        Bus::assertNothingDispatched();
    }
}
