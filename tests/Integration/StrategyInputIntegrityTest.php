<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exchanges\Binance\BinancePaperReadiness;
use App\Exchanges\Binance\BinanceResearchCycle;
use App\MarketData\Application\CandleConflictRecovery;
use App\MarketData\Application\MarketCandleConflictService;
use App\MarketData\Domain\CandleIntegrityUnavailable;
use App\MarketData\Infrastructure\Persistence\CandleConflictJournal;
use App\PaperTrading\Application\PaperAccountService;
use App\Research\Application\SystemFreshnessService;
use App\Strategy\Application\SignalGenerator;
use App\Strategy\Application\StrategyInputFactory;
use App\Strategy\Contracts\MarketContextRepository;
use App\Strategy\Domain\StrategyInputIntegrityException;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Concerns\CleansCandleConflictEvidence;
use Tests\Fixtures\CandleConflictEvidenceFixture;
use Tests\TestCase;

final class StrategyInputIntegrityTest extends TestCase
{
    use CleansCandleConflictEvidence;

    private DateTimeImmutable $at;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePostgresTestDatabaseReady('market_candle_conflict_journal');
        $this->registerConflictCleanup();
        $this->at = new DateTimeImmutable('2026-01-01T01:00:00Z');
        CarbonImmutable::setTestNow($this->at);
        Http::preventStrayRequests();
        $this->seedHistory();
        $this->beforeApplicationDestroyed(fn () => DB::table('market_candles')->where('exchange', 'binance')->whereIn('symbol', ['BTCUSDT', 'ETHUSDT'])->delete());
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_pending_recovery_blocks_current_and_genuinely_fresh_php_processes(): void
    {
        app(CandleConflictJournal::class)->begin(CandleConflictEvidenceFixture::make());
        $this->assertBlocked('CANDLE_JOURNAL_PENDING');
        $worker = $this->worker('inspect');
        self::assertSame(3, $worker->getExitCode(), $worker->getErrorOutput());
        $result = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('UNAVAILABLE', $result['status']);
        self::assertSame('CANDLE_JOURNAL_PENDING', $result['reason_code']);
        self::assertNotSame(getmypid(), $result['pid']);
        self::assertNotSame(DB::selectOne('SELECT pg_backend_pid() AS pid')->pid, $result['db_pid']);
        fwrite(STDOUT, json_encode(['fresh_process_integrity' => 'VERIFIED', 'parent_pid' => getmypid(), 'worker' => $result], JSON_THROW_ON_ERROR)."\n");
    }

    public function test_recovery_is_not_resolution_and_input_requires_independent_verified_clearance(): void
    {
        $path = app(CandleConflictJournal::class)->begin(CandleConflictEvidenceFixture::make());
        $this->assertBlocked('CANDLE_JOURNAL_PENDING');
        app(CandleConflictRecovery::class)->recover($path);
        $this->assertBlocked('CANDLE_HISTORY_INTEGRITY_CONFLICT');
        DB::table('market_candle_conflicts')->update(['status' => 'RESOLVED', 'resolved_at' => now(), 'resolution_notes' => 'Isolated fixture owner verification; no candle changes']);
        $input = app(StrategyInputFactory::class)->make('binance', 'linear', 'BTCUSDT', $this->at);
        self::assertSame([], $input->validationErrors);
        foreach (['trend', 'setup', 'entry', 'confirmation'] as $role) {
            self::assertTrue($input->{$role}->warmupReady);
        }
        $worker = $this->worker('inspect');
        self::assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
        self::assertSame('VALID', json_decode($worker->getOutput(), true)['status']);
    }

    #[DataProvider('malformedSymbols')]
    public function test_malformed_evidence_blocks_input_health_readiness_and_recovery_without_modification(mixed $symbol, bool $missing = false): void
    {
        $entry = CandleConflictEvidenceFixture::make();
        $entry['symbol'] = $symbol;
        if ($missing) {
            unset($entry['symbol']);
        }
        $dir = config('market_data.conflict_journal_path');
        mkdir($dir, 0770, true);
        $path = $dir.'/'.str_repeat('c', 64).'.json';
        $bytes = json_encode($entry, JSON_THROW_ON_ERROR);
        file_put_contents($path, $bytes);
        $this->assertBlocked('CANDLE_JOURNAL_RECORD_INVALID');
        try {
            app(CandleConflictRecovery::class)->recover($path);
            self::fail('Malformed recovery was accepted.');
        } catch (CandleIntegrityUnavailable $e) {
            self::assertSame('CANDLE_JOURNAL_RECORD_INVALID', $e->reasonCode);
        }
        self::assertSame($bytes, file_get_contents($path));
        self::assertSame(0, DB::table('market_candle_conflict_journal')->count());
        self::assertSame('VALID', app(MarketCandleConflictService::class)->integrityStatus('bybit', 'BTCUSDT', '30'));
        $this->getJson('/api/v1/research/health/data-freshness?exchange=binance')->assertOk()
            ->assertJsonPath('data.status', 'CRITICAL')->assertJsonPath('data.integrity_status', 'UNAVAILABLE')
            ->assertJsonPath('data.candle_freshness.0.integrity_reason_code', 'CANDLE_JOURNAL_RECORD_INVALID');
        $ready = app(BinancePaperReadiness::class)->inspect();
        self::assertSame('BLOCKED', $ready['overall_readiness']);
        self::assertSame('CANDLE_HISTORY_INTEGRITY_UNAVAILABLE', $ready['candle_history']['symbols']['BTCUSDT']['30']['status']);
        self::assertSame('CANDLE_JOURNAL_RECORD_INVALID', $ready['candle_history']['symbols']['BTCUSDT']['30']['integrity_reason_code']);
    }

    public static function malformedSymbols(): array
    {
        return [[['BTCUSDT']], [null], [null, true], ['btcusdt'], ['BTC/USDT'], ['UNKNOWNUSDT']];
    }

    #[DataProvider('concurrentChanges')]
    public function test_final_boundary_recheck_observes_a_concurrent_process_change(string $mode, string $reason): void
    {
        $this->mock(MarketContextRepository::class)->shouldReceive('latestAt')->once()->andReturnUsing(function () use ($mode): null {
            $worker = $this->worker($mode);
            self::assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());

            return null;
        });
        $this->assertBlocked($reason);
    }

    public static function concurrentChanges(): array
    {
        return [['publish', 'CANDLE_JOURNAL_PENDING'], ['publish-conflict', 'CANDLE_HISTORY_INTEGRITY_CONFLICT']];
    }

    public function test_peer_symbol_integrity_is_checked_using_its_actual_input_window(): void
    {
        app(CandleConflictJournal::class)->begin(CandleConflictEvidenceFixture::make(['symbol' => 'ETHUSDT', 'interval' => '15', 'open_time' => '2026-01-01T00:45:00Z']));
        app(StrategyInputFactory::class)->make('binance', 'linear', 'BTCUSDT', $this->at);
        $this->expectException(StrategyInputIntegrityException::class);
        app(StrategyInputFactory::class)->make('binance', 'linear', 'BTCUSDT', $this->at, 'ETHUSDT');
    }

    public function test_unrelated_symbol_interval_and_outside_warmup_scope_do_not_block_input(): void
    {
        foreach ([['symbol' => 'ETHUSDT'], ['interval' => '240', 'open_time' => '2026-01-01T00:00:00Z'], ['open_time' => '2025-01-01T00:30:00Z']] as $scope) {
            app(CandleConflictJournal::class)->begin(CandleConflictEvidenceFixture::make($scope));
        }
        self::assertSame([], app(StrategyInputFactory::class)->make('binance', 'linear', 'BTCUSDT', $this->at)->validationErrors);
    }

    public function test_integrity_query_failure_blocks_input_and_returns_a_safe_machine_readable_api_error(): void
    {
        DB::statement('ALTER TABLE market_candle_conflict_journal RENAME COLUMN state TO hidden_state');
        try {
            $this->assertBlocked('CANDLE_INTEGRITY_UNAVAILABLE');
            Route::get('/api/testing/strategy-integrity', fn () => app(StrategyInputFactory::class)->make('binance', 'linear', 'BTCUSDT', $this->at));
            $response = $this->getJson('/api/testing/strategy-integrity')->assertStatus(503)
                ->assertJsonPath('error.code', 'CANDLE_INTEGRITY_UNAVAILABLE')->assertJsonPath('error.integrity_status', 'UNAVAILABLE');
            self::assertStringNotContainsString(config('market_data.conflict_journal_path'), $response->getContent());
            self::assertStringNotContainsString('SQLSTATE', $response->getContent());
        } finally {
            DB::statement('ALTER TABLE market_candle_conflict_journal RENAME COLUMN hidden_state TO state');
        }
    }

    public function test_unavailable_shared_filesystem_blocks_input(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'v4-unavailable-');
        $original = config('market_data.conflict_journal_path');
        config(['market_data.conflict_journal_path' => $file]);
        try {
            $this->assertBlocked('CANDLE_INTEGRITY_UNAVAILABLE');
        } finally {
            config(['market_data.conflict_journal_path' => $original]);
            unlink($file);
        }
    }

    public function test_unavailable_postgres_connection_blocks_input_with_a_stable_reason(): void
    {
        $port = config('database.connections.pgsql.port');
        config(['database.connections.pgsql.port' => 1]);
        DB::purge('pgsql');
        try {
            $this->assertBlocked('CANDLE_STORAGE_UNAVAILABLE');
            $health = app(SystemFreshnessService::class)->check('binance');
            self::assertSame('CRITICAL', $health['status']);
            self::assertSame('UNAVAILABLE', $health['integrity_status']);
            self::assertNull($health['unresolved_conflicts_count']);
            self::assertSame('UNAVAILABLE', $health['candle_freshness'][0]['integrity_status']);
        } finally {
            config(['database.connections.pgsql.port' => $port]);
            DB::purge('pgsql');
        }
    }

    public function test_failed_durable_receipt_blocks_even_when_pending_file_visibility_is_lost(): void
    {
        $path = app(CandleConflictJournal::class)->begin(CandleConflictEvidenceFixture::make());
        DB::statement("ALTER TABLE market_candle_conflicts ADD CONSTRAINT v4_input_reject CHECK (symbol <> 'BTCUSDT')");
        try {
            try {
                app(CandleConflictRecovery::class)->recover($path);
                self::fail('Write rejection was ignored.');
            } catch (CandleIntegrityUnavailable) {
                self::assertSame('FAILED', DB::table('market_candle_conflict_journal')->value('state'));
            }
        } finally {
            DB::statement('ALTER TABLE market_candle_conflicts DROP CONSTRAINT v4_input_reject');
        }
        // Simulated filesystem visibility loss; retain and restore the exact forensic bytes.
        rename($path, $path.'.retained');
        try {
            $this->assertBlocked('CANDLE_JOURNAL_RECOVERY_PENDING');
        } finally {
            rename($path.'.retained', $path);
        }
    }

    public function test_a_caller_repeatable_read_snapshot_cannot_hide_new_committed_conflicts(): void
    {
        DB::beginTransaction();
        try {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            self::assertSame(0, DB::table('market_candle_conflicts')->count());
            $worker = $this->worker('publish-conflict');
            self::assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
            self::assertSame(0, DB::table('market_candle_conflicts')->count(), 'Caller snapshot is intentionally older.');
            $this->assertBlocked('CANDLE_HISTORY_INTEGRITY_CONFLICT');
        } finally {
            DB::rollBack();
        }
    }

    public function test_blocked_input_and_evidence_recovery_leave_paper_account_and_history_bytes_unchanged(): void
    {
        $existingAccounts = DB::table('paper_accounts')->pluck('id')->all();
        $account = app(PaperAccountService::class)->default();
        $tables = ['paper_accounts', 'equity_snapshots', 'virtual_positions', 'virtual_executions', 'trading_journal_events', 'position_funding_events'];
        $snapshot = static function () use ($tables): array {
            $data = [];
            foreach ($tables as $table) {
                $data[$table] = DB::table($table)->get()->map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR))->sort()->values()->all();
            }

            return $data;
        };
        $before = $snapshot();
        self::assertNotEmpty($before['paper_accounts']);
        self::assertNotEmpty($before['equity_snapshots']);
        try {
            $path = app(CandleConflictJournal::class)->begin(CandleConflictEvidenceFixture::make());
            $this->assertBlocked('CANDLE_JOURNAL_PENDING');
            app(CandleConflictRecovery::class)->recover($path);
            $this->assertBlocked('CANDLE_HISTORY_INTEGRITY_CONFLICT');
            self::assertSame($before, $snapshot());
        } finally {
            // Only the account fixture created by this test is removed after the preservation assertion.
            if (! in_array($account->id, $existingAccounts, true)) {
                DB::table('equity_snapshots')->where('paper_account_id', $account->id)->delete();
                DB::table('paper_accounts')->where('id', $account->id)->delete();
            }
        }
    }

    public function test_research_and_signal_paths_do_not_write_observations_or_signals_while_blocked(): void
    {
        app(CandleConflictJournal::class)->begin(CandleConflictEvidenceFixture::make());
        foreach ([fn () => app(BinanceResearchCycle::class)->run('BTCUSDT', false, $this->at), fn () => app(SignalGenerator::class)->generate('BTCUSDT', $this->at, 'binance')] as $run) {
            try {
                $run();
                self::fail('Blocked input reached strategy generation.');
            } catch (StrategyInputIntegrityException $e) {
                self::assertSame('CANDLE_JOURNAL_PENDING', $e->reasonCode);
            }
        }
        self::assertSame(0, DB::table('signals')->count());
        self::assertSame(0, DB::table('strategy_observations')->count());
    }

    private function assertBlocked(string $reason): void
    {
        try {
            app(StrategyInputFactory::class)->make('binance', 'linear', 'BTCUSDT', $this->at);
            self::fail('Unverified strategy input was returned.');
        } catch (StrategyInputIntegrityException $e) {
            self::assertSame($reason, $e->reasonCode);
            self::assertContains($e->integrityStatus, ['UNAVAILABLE', 'CONFLICTED']);
        }
    }

    private function worker(string $mode): Process
    {
        $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/strategy_input_integrity_worker.php'), $mode, config('market_data.conflict_journal_path')], base_path());
        $worker->setTimeout(20);
        $worker->run();

        return $worker;
    }

    private function seedHistory(): void
    {
        foreach (['BTCUSDT', 'ETHUSDT'] as $symbol) {
            foreach ([5, 15, 30, 60] as $minutes) {
                $rows = [];
                for ($i = 0; $i < 220; $i++) {
                    $price = (string) (1000 + $i);
                    $rows[] = ['exchange' => 'binance', 'category' => 'linear', 'symbol' => $symbol, 'interval' => (string) $minutes,
                        'open_time' => gmdate('Y-m-d H:i:s', $this->at->getTimestamp() - (220 - $i) * $minutes * 60),
                        'open' => $price, 'high' => (string) (1010 + $i), 'low' => (string) (990 + $i), 'close' => $price,
                        'volume' => '100', 'turnover' => '100000', 'is_closed' => true];
                }
                DB::table('market_candles')->insert($rows);
            }
        }
    }
}
