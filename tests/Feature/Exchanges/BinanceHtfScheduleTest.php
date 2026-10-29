<?php

declare(strict_types=1);

namespace Tests\Feature\Exchanges;

use App\MarketData\Application\MarketCandleConflictService;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schedule;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CleansCandleConflictEvidence;
use Tests\TestCase;

final class BinanceHtfScheduleTest extends TestCase
{
    use CleansCandleConflictEvidence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePostgresTestDatabaseReady('market_candle_conflicts');
        $this->registerConflictCleanup();
        $this->beforeApplicationDestroyed(function (): void {
            DB::table('market_candles')->where('exchange', 'binance')->whereIn('symbol', ['BTCUSDT', 'ETHUSDT'])->delete();
            putenv('CANDLE_TEST_CASE');
            putenv('CANDLE_TEST_JOURNAL');
        });
    }

    #[DataProvider('outcomes')]
    public function test_real_scheduler_reports_command_failures_and_preserves_logging_and_mutex(string $scenario, int $exit): void
    {
        $events = collect(Schedule::events());
        $htf = $events->filter(fn ($e) => str_starts_with($e->description ?? '', 'binance:htf:'));
        self::assertCount(8, $htf);
        self::assertCount(8, $htf->map(fn ($e) => $e->mutexName())->unique());
        $target = $htf->first(fn ($e) => $e->description === 'binance:htf:BTCUSDT:30m');
        self::assertNotInstanceOf(CallbackEvent::class, $target);
        self::assertStringContainsString('binance:ingest-htf', $target->command);
        self::assertStringContainsString('--limit=10', str_replace("'", '', $target->command));
        self::assertStringContainsString('--quarantine', $target->command);
        self::assertTrue($target->withoutOverlapping);
        self::assertSame('1,16,31,46 * * * *', $target->expression);
        foreach ($events as $event) {
            if ($event !== $target) {
                $event->skip(fn () => true);
            }
        }
        $target->cron('* * * * *');
        // Only the child's HTTP boundary is faked; scheduler events and Artisan are real.
        $target->command = str_replace('artisan', 'tests/Fixtures/binance_scheduled_command.php', $target->command);
        $output = storage_path('logs/test-schedule-'.bin2hex(random_bytes(6)).'.log');
        $target->appendOutputTo($output);
        $target->command = 'CANDLE_TEST_CASE='.escapeshellarg($scenario).' CANDLE_TEST_JOURNAL='.escapeshellarg(config('market_data.conflict_journal_path')).' '.$target->command;
        if (in_array($scenario, ['partial', 'persistence'], true)) {
            DB::table('market_candles')->insert(['exchange' => 'binance', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'interval' => '30', 'open_time' => '2026-01-01T00:30:00Z', 'open' => '100', 'high' => '105', 'low' => '99', 'close' => '102', 'volume' => '10', 'turnover' => '1010', 'is_closed' => true]);
        }
        $failures = [];
        Event::listen(ScheduledTaskFailed::class, function ($e) use (&$failures): void {
            $failures[] = $e;
        });
        if ($scenario === 'persistence') {
            DB::statement("ALTER TABLE market_candle_conflicts ADD CONSTRAINT test_schedule_reject CHECK (symbol <> 'BTCUSDT')");
        }
        try {
            Artisan::call('schedule:run', ['--no-ansi' => true]);
            self::assertSame($exit, $target->exitCode);
            self::assertCount($exit === 0 ? 0 : 1, $failures);
            if ($exit !== 0) {
                self::assertStringContainsString('exit code ['.$exit.']', $failures[0]->exception->getMessage());
            }
            self::assertFileExists($output);
            $log = file_get_contents($output);
            self::assertStringContainsString(match ($scenario) {
                'success' => 'SUCCESS', 'partial' => 'PARTIAL_INGESTION_WITH_CONFLICTS',
                'persistence' => 'CANDLE_INTEGRITY_UNAVAILABLE', default => 'failed',
            }, $log);
            if ($scenario === 'persistence') {
                self::assertStringContainsString('"new_closed_candles":2', $log);
                self::assertSame(3, DB::table('market_candles')->where('exchange', 'binance')->where('symbol', 'BTCUSDT')->count());
                self::assertSame('UNAVAILABLE', app(MarketCandleConflictService::class)->integrityStatus('binance', 'BTCUSDT', '30'));
            }
            self::assertFalse($target->mutex->exists($target));
            self::assertTrue($target->mutex->create($target));
            $before = file_get_contents($output);
            $target->run(app());
            self::assertSame($before, file_get_contents($output));
            $target->mutex->forget($target);
        } finally {
            if ($scenario === 'persistence') {
                DB::statement('ALTER TABLE market_candle_conflicts DROP CONSTRAINT test_schedule_reject');
            }
            @unlink($output);
        }
        self::assertSame(0, DB::table('signals')->count());
        self::assertSame(0, DB::table('virtual_positions')->count());
    }

    public static function outcomes(): array
    {
        return [['success', 0], ['partial', 2], ['failed', 1], ['persistence', 3]];
    }

    public function test_one_failed_htf_scope_does_not_prevent_other_scheduled_intervals(): void
    {
        $targets = [];
        foreach (Schedule::events() as $event) {
            if (! str_starts_with($event->description ?? '', 'binance:htf:')) {
                $event->skip(fn () => true);

                continue;
            }
            $event->cron('* * * * *');
            $event->command = 'CANDLE_TEST_CASE=failed_scope CANDLE_TEST_JOURNAL='.escapeshellarg(config('market_data.conflict_journal_path')).' '.str_replace('artisan', 'tests/Fixtures/binance_scheduled_command.php', $event->command);
            $targets[] = $event;
        }
        $failures = [];
        Event::listen(ScheduledTaskFailed::class, function ($event) use (&$failures): void {
            $failures[] = $event;
        });
        Artisan::call('schedule:run', ['--no-ansi' => true]);
        self::assertCount(1, $failures);
        self::assertSame('binance:htf:BTCUSDT:15m', $failures[0]->task->description);
        foreach ($targets as $event) {
            self::assertSame($event->description === 'binance:htf:BTCUSDT:15m' ? 1 : 0, $event->exitCode);
        }
        self::assertSame(21, DB::table('market_candles')->where('exchange', 'binance')->count());
        self::assertSame(0, DB::table('signals')->count());
        self::assertSame(0, DB::table('virtual_positions')->count());
    }
}
