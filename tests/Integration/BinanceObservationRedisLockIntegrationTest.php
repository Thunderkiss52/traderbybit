<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Trading\Application\TradingPipelineEventService;
use App\Trading\Application\TradingPipelineRecoveryService;
use App\Trading\Infrastructure\Persistence\TradingPipelineEventRecord;
use App\Trading\Jobs\ProcessClosedCandleEventJob;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class BinanceObservationRedisLockIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow(null);
        parent::tearDown();
    }

    public function test_killed_worker_lock_expires_and_pending_event_is_recoverable(): void
    {
        config(['cache.default' => 'redis']);
        Cache::purge('redis');
        CarbonImmutable::setTestNow('2026-10-31T09:00:01Z');
        Queue::fake();
        Http::preventStrayRequests();
        $at = new DateTimeImmutable('2026-10-31T09:00:00Z');
        $events = app(TradingPipelineEventService::class);
        $record = $events->recordCandleClosed('binance', 'BTCUSDT', '15', $at);
        self::assertNotNull($record);
        $key = 'strategy-observations:binance:linear:BTCUSDT:'.$at->format('U.u');

        $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/hold_observation_redis_lock.php'), $key, '1'],
            base_path(), ['APP_ENV' => 'testing', 'CACHE_STORE' => 'redis', 'REDIS_HOST' => '127.0.0.1', 'REDIS_PORT' => '6379']);
        $worker->setTimeout(10);
        try {
            $worker->start();
            self::assertTrue($worker->waitUntil(static fn ($type, $output): bool => str_contains($output, 'READY')), $worker->getErrorOutput());
            (new ProcessClosedCandleEventJob($record->id, 'binance', 'BTCUSDT', '15', $at->format(DATE_ATOM)))->handle($events);
            self::assertSame(TradingPipelineEventRecord::STATUS_PENDING, $record->fresh()->status);
            self::assertStringContainsString('already running', (string) $record->fresh()->last_error);
            self::assertSame(0, DB::table('virtual_executions')->count());

            $worker->signal(SIGKILL);
            $worker->wait();
            self::assertFalse(Cache::lock($key, 1)->get(), 'Killed worker lock must remain fenced until expiry.');
            usleep(1_200_000);
            $recoveredLock = Cache::lock($key, 1);
            self::assertTrue($recoveredLock->get(), 'Expired lock must not persist indefinitely.');
            $recoveredLock->release();

            CarbonImmutable::setTestNow('2026-10-31T09:00:30Z');
            self::assertGreaterThanOrEqual(1, app(TradingPipelineRecoveryService::class)->recover()['recovered_events']);
            self::assertSame(TradingPipelineEventRecord::STATUS_DISPATCHED, $record->fresh()->status);
            Queue::assertPushed(ProcessClosedCandleEventJob::class, 2);
        } finally {
            if ($worker->isRunning()) {
                $worker->signal(SIGKILL);
                $worker->wait();
            }
        }
    }
}
