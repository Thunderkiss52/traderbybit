<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\MarketData\Application\CandleConflictRecovery;
use App\MarketData\Application\MarketCandleConflictService;
use App\MarketData\Domain\CandleIntegrityUnavailable;
use App\MarketData\Infrastructure\Persistence\CandleConflictJournal;
use App\MarketData\Infrastructure\Persistence\CandleIntegrityDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Concerns\CleansCandleConflictEvidence;
use Tests\Fixtures\CandleConflictEvidenceFixture;
use Tests\TestCase;

final class CandleConflictRecoveryTest extends TestCase
{
    use CleansCandleConflictEvidence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePostgresTestDatabaseReady('market_candle_conflict_journal');
        $this->registerConflictCleanup();
        Http::preventStrayRequests();
    }

    public function test_replay_and_duplicate_delivery_have_one_durable_effect_and_archive_the_original(): void
    {
        $path = $this->entry();
        $bytes = file_get_contents($path);
        $duplicate = dirname($path).'/'.str_repeat('b', 64).'.json';
        copy($path, $duplicate);
        $observer = app(CandleIntegrityDatabase::class)->connect();
        try {
            $pid = DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
            $otherPid = $observer->selectOne('SELECT pg_backend_pid() AS pid')->pid;
            self::assertNotSame($pid, $otherPid);
            DB::beginTransaction();
            try {
                $first = app(CandleConflictRecovery::class)->recover($path);
                self::assertSame('RECOVERED', $observer->table('market_candle_conflict_journal')->value('state'));
                self::assertSame(1, $observer->table('market_candle_conflicts')->value('occurrence_count'));
            } finally {
                DB::rollBack();
            }
            self::assertSame($first, app(CandleConflictRecovery::class)->recover($path));
            self::assertSame($first, app(CandleConflictRecovery::class)->recover($duplicate));
            self::assertSame(1, $observer->table('market_candle_conflicts')->value('occurrence_count'));
            self::assertSame(1, $observer->table('market_candle_conflict_journal')->value('attempts'));
            fwrite(STDOUT, json_encode(['recovery_durability' => 'VERIFIED', 'main_pid' => $pid, 'observer_pid' => $otherPid, 'after_outer_rollback' => true, 'count' => 1], JSON_THROW_ON_ERROR)."\n");
        } finally {
            $observer->disconnect();
        }
        self::assertFileDoesNotExist($path);
        self::assertFileDoesNotExist($duplicate);
        self::assertSame([], app(CandleConflictJournal::class)->pending());
        self::assertSame($bytes, file_get_contents(dirname($path).'/recovered/'.basename($path)));
        self::assertSame('CONFLICTED', app(MarketCandleConflictService::class)->integrityStatus('binance', 'BTCUSDT', '30'));
    }

    public function test_four_independent_processes_cannot_apply_one_event_twice(): void
    {
        $path = $this->entry();
        $marker = dirname($path).'/barrier';
        $workers = [];
        for ($i = 0; $i < 4; $i++) {
            $workers[] = $this->worker($path, 'concurrent', $marker);
        }
        $deadline = microtime(true) + 10;
        while (count(glob($marker.'.*')) < 4 && microtime(true) < $deadline) {
            usleep(1000);
        }
        $pids = array_map('file_get_contents', glob($marker.'.*'));
        self::assertCount(4, array_unique($pids));
        file_put_contents($marker, 'go');
        foreach ($workers as $worker) {
            $worker->wait();
            self::assertContains($worker->getExitCode(), [0, 75], $worker->getErrorOutput().$worker->getOutput());
        }
        app(CandleConflictRecovery::class)->recover($path);
        self::assertSame(1, DB::table('market_candle_conflicts')->value('occurrence_count'));
        self::assertSame(1, DB::table('market_candle_conflict_journal')->count());
        self::assertSame(1, DB::table('market_candle_conflict_journal')->value('attempts'));
        self::assertSame([], app(CandleConflictJournal::class)->pending());
        fwrite(STDOUT, json_encode(['recovery_concurrency' => 'VERIFIED', 'independent_pids' => $pids, 'count' => 1], JSON_THROW_ON_ERROR)."\n");
        foreach ([...glob($marker.'.*'), $marker] as $file) {
            unlink($file);
        }
    }

    #[DataProvider('crashBoundaries')]
    public function test_real_process_termination_is_recoverable_at_both_committed_boundaries(string $mode, string $state, int $attempts): void
    {
        $path = $this->entry();
        $marker = dirname($path).'/crash-marker';
        $worker = $this->worker($path, $mode, $marker);
        $deadline = microtime(true) + 10;
        while (! is_file($marker) && $worker->isRunning() && microtime(true) < $deadline) {
            usleep(1000);
        }
        self::assertFileExists($marker, $worker->getErrorOutput());
        $proof = json_decode(file_get_contents($marker), true, flags: JSON_THROW_ON_ERROR);
        $worker->signal(SIGKILL);
        $worker->wait();
        self::assertNotSame(0, $worker->getExitCode());
        self::assertSame($state, DB::table('market_candle_conflict_journal')->value('state'));
        self::assertSame($state === 'RECOVERED' ? 1 : 0, DB::table('market_candle_conflicts')->count(), 'Interrupted transactions must never leave an unreceipted effect.');
        self::assertFileExists($path);
        self::assertSame('UNAVAILABLE', app(MarketCandleConflictService::class)->integrityStatus('binance', 'BTCUSDT', '30'));
        if ($state === 'IN_PROGRESS') {
            try {
                app(CandleConflictRecovery::class)->recover($path);
                self::fail('Active lease was bypassed.');
            } catch (CandleIntegrityUnavailable $e) {
                self::assertSame('CANDLE_RECOVERY_IN_PROGRESS', $e->reasonCode);
            }
            DB::table('market_candle_conflict_journal')->update(['claim_until' => DB::raw("CURRENT_TIMESTAMP - INTERVAL '1 second'")]);
        }
        app(CandleConflictRecovery::class)->recover($path);
        self::assertSame('RECOVERED', DB::table('market_candle_conflict_journal')->value('state'));
        self::assertSame($attempts, DB::table('market_candle_conflict_journal')->value('attempts'));
        self::assertSame(1, DB::table('market_candle_conflicts')->value('occurrence_count'));
        self::assertFileDoesNotExist($path);
        fwrite(STDOUT, json_encode(['recovery_crash' => $mode, 'committed_before_kill' => $proof, 'exit' => $worker->getExitCode(), 'final_count' => 1, 'final_attempts' => $attempts], JSON_THROW_ON_ERROR)."\n");
        unlink($marker);
    }

    public static function crashBoundaries(): array
    {
        return [['crash-after-claim', 'IN_PROGRESS', 2], ['crash-during-apply', 'IN_PROGRESS', 2], ['crash-after-commit', 'RECOVERED', 1]];
    }

    public function test_permanently_failed_receipt_is_not_retried_or_acknowledged(): void
    {
        $path = $this->entry();
        $entry = app(CandleConflictJournal::class)->read($path);
        DB::table('market_candle_conflict_journal')->insert([
            'event_id' => $entry['event_id'], 'evidence_hash' => $entry['hash'], 'evidence' => json_encode($entry['evidence'], JSON_THROW_ON_ERROR),
            'source_entry' => basename($path), 'exchange' => 'binance', 'category' => 'linear', 'symbol' => 'BTCUSDT',
            'interval' => '30', 'open_time' => '2026-01-01T00:30:00Z', 'state' => 'FAILED', 'retryable' => false,
            'last_error_code' => 'REQUIRES_OPERATOR_REVIEW', 'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            app(CandleConflictRecovery::class)->recover($path);
            self::fail('Permanent failure was retried.');
        } catch (CandleIntegrityUnavailable $e) {
            self::assertSame('CANDLE_RECOVERY_PERMANENT_FAILURE', $e->reasonCode);
        }
        self::assertSame(0, DB::table('market_candle_conflict_journal')->value('attempts'));
        self::assertSame(0, DB::table('market_candle_conflicts')->count());
        self::assertFileExists($path);
        self::assertSame('UNAVAILABLE', app(MarketCandleConflictService::class)->integrityStatus('binance', 'BTCUSDT', '30'));
    }

    public function test_failed_failure_state_write_retains_a_fenced_claim_and_pending_source(): void
    {
        $path = $this->entry();
        DB::statement("ALTER TABLE market_candle_conflicts ADD CONSTRAINT v4_apply_reject CHECK (symbol <> 'BTCUSDT')");
        DB::statement("ALTER TABLE market_candle_conflict_journal ADD CONSTRAINT v4_state_reject CHECK (state <> 'FAILED')");
        try {
            try {
                app(CandleConflictRecovery::class)->recover($path);
                self::fail('Write failures were acknowledged.');
            } catch (CandleIntegrityUnavailable) {
                self::assertSame('IN_PROGRESS', DB::table('market_candle_conflict_journal')->value('state'));
                self::assertNotNull(DB::table('market_candle_conflict_journal')->value('claim_token'));
                self::assertSame(0, DB::table('market_candle_conflicts')->count());
                self::assertFileExists($path);
            }
        } finally {
            DB::statement('ALTER TABLE market_candle_conflicts DROP CONSTRAINT v4_apply_reject');
            DB::statement('ALTER TABLE market_candle_conflict_journal DROP CONSTRAINT v4_state_reject');
        }
        DB::table('market_candle_conflict_journal')->update(['claim_until' => DB::raw("CURRENT_TIMESTAMP - INTERVAL '1 second'")]);
        app(CandleConflictRecovery::class)->recover($path);
        self::assertSame('RECOVERED', DB::table('market_candle_conflict_journal')->value('state'));
        self::assertSame(1, DB::table('market_candle_conflicts')->value('occurrence_count'));
    }

    public function test_failed_recovery_remains_observable_and_retry_commits_once(): void
    {
        $path = $this->entry();
        DB::statement("ALTER TABLE market_candle_conflicts ADD CONSTRAINT v4_reject CHECK (symbol <> 'BTCUSDT')");
        try {
            try {
                app(CandleConflictRecovery::class)->recover($path);
                self::fail('Failed write was acknowledged.');
            } catch (CandleIntegrityUnavailable) {
                self::assertSame('FAILED', DB::table('market_candle_conflict_journal')->value('state'));
                self::assertTrue(DB::table('market_candle_conflict_journal')->value('retryable'));
                self::assertSame('CANDLE_RECOVERY_WRITE_FAILED', DB::table('market_candle_conflict_journal')->value('last_error_code'));
                self::assertFileExists($path);
                self::assertSame('UNAVAILABLE', app(MarketCandleConflictService::class)->integrityStatus('binance', 'BTCUSDT', '30'));
            }
        } finally {
            DB::statement('ALTER TABLE market_candle_conflicts DROP CONSTRAINT v4_reject');
        }
        app(CandleConflictRecovery::class)->recover($path);
        self::assertSame(2, DB::table('market_candle_conflict_journal')->value('attempts'));
        self::assertSame(1, DB::table('market_candle_conflicts')->value('occurrence_count'));
    }

    public function test_archive_failure_after_commit_retries_acknowledgement_without_reapplying(): void
    {
        $path = $this->entry();
        $archive = dirname($path).'/recovered';
        file_put_contents($archive, 'isolated archive failure');
        try {
            app(CandleConflictRecovery::class)->recover($path);
            self::fail('Failed acknowledgement was accepted.');
        } catch (CandleIntegrityUnavailable) {
            self::assertSame('RECOVERED', DB::table('market_candle_conflict_journal')->value('state'));
            self::assertFileExists($path);
        } finally {
            unlink($archive);
        }
        app(CandleConflictRecovery::class)->recover($path);
        self::assertSame(1, DB::table('market_candle_conflicts')->value('occurrence_count'));
        self::assertFileDoesNotExist($path);
    }

    public function test_identity_collision_cannot_change_receipt_or_discard_conflicting_bytes(): void
    {
        $path = $this->entry();
        $entry = json_decode(file_get_contents($path), true);
        app(CandleConflictRecovery::class)->recover($path);
        $changed = CandleConflictEvidenceFixture::make(['event_id' => $entry['event_id'], 'incoming_values' => ['close' => '104']]);
        $path = app(CandleConflictJournal::class)->begin($changed);
        $bytes = file_get_contents($path);
        try {
            app(CandleConflictRecovery::class)->recover($path);
            self::fail('Reused identity with different content was accepted.');
        } catch (CandleIntegrityUnavailable $e) {
            self::assertSame('CANDLE_JOURNAL_IDENTITY_MISMATCH', $e->reasonCode);
        }
        self::assertSame($bytes, file_get_contents($path));
        self::assertSame(1, DB::table('market_candle_conflicts')->value('occurrence_count'));
        self::assertSame('RECOVERED', DB::table('market_candle_conflict_journal')->value('state'));
    }

    public function test_legacy_complete_v2_file_recovers_without_rewriting_its_bytes(): void
    {
        $entry = CandleConflictEvidenceFixture::make();
        unset($entry['schema_version'], $entry['event_id']);
        $dir = config('market_data.conflict_journal_path');
        mkdir($dir, 0770, true);
        $path = $dir.'/'.str_repeat('a', 32).'.json';
        file_put_contents($path, json_encode($entry, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $bytes = file_get_contents($path);
        app(CandleConflictRecovery::class)->recover($path);
        app(CandleConflictRecovery::class)->recover($path);
        self::assertSame($bytes, file_get_contents($dir.'/recovered/'.basename($path)));
        self::assertSame(1, DB::table('market_candle_conflicts')->value('occurrence_count'));
    }

    public function test_pending_source_cannot_be_acknowledged_without_an_independently_committed_receipt(): void
    {
        $path = $this->entry();
        try {
            app(CandleConflictJournal::class)->acknowledge($path, hash_file('sha256', $path));
            self::fail('Evidence without a committed receipt was archived.');
        } catch (CandleIntegrityUnavailable $e) {
            self::assertSame('CANDLE_RECOVERY_RECEIPT_INVALID', $e->reasonCode);
        }
        self::assertFileExists($path);
        self::assertSame(0, DB::table('market_candle_conflicts')->count());
    }

    public function test_out_of_order_recovery_preserves_latest_observation_and_forensic_events(): void
    {
        $newer = app(CandleConflictJournal::class)->begin(CandleConflictEvidenceFixture::make(['incoming_values' => ['close' => '104'], 'last_seen_at' => '2026-01-02T00:00:00Z']));
        app(CandleConflictRecovery::class)->recover($newer);
        app(CandleConflictRecovery::class)->recover($this->entry());
        self::assertSame(2, DB::table('market_candle_conflicts')->value('occurrence_count'));
        self::assertSame(['close' => '104'], json_decode(DB::table('market_candle_conflicts')->value('incoming_values'), true));
        self::assertSame(2, DB::table('market_candle_conflict_journal')->where('state', 'RECOVERED')->count());
    }

    public function test_cli_reports_completed_evidence_without_resolving_the_conflict(): void
    {
        $path = $this->entry();
        $this->artisan('market-data:recover-candle-conflicts', ['--entry' => basename($path)])->expectsOutputToContain('"recovery_status":"COMPLETE"')->assertSuccessful();
        $this->artisan('market-data:recover-candle-conflicts', ['--entry' => basename($path)])->assertSuccessful();
        self::assertSame('UNRESOLVED', DB::table('market_candle_conflicts')->value('status'));
        self::assertSame(1, DB::table('market_candle_conflicts')->value('occurrence_count'));
        $this->artisan('market-data:recover-candle-conflicts', ['--entry' => '../unsafe.json'])->assertExitCode(2);
    }

    public function test_receipt_insert_failure_preserves_source_for_a_later_retry(): void
    {
        $path = $this->entry();
        DB::statement("ALTER TABLE market_candle_conflict_journal ADD CONSTRAINT v4_reject_receipt CHECK (symbol <> 'BTCUSDT')");
        try {
            try {
                app(CandleConflictRecovery::class)->recover($path);
                self::fail('Failed receipt insert was acknowledged.');
            } catch (CandleIntegrityUnavailable) {
                self::assertFileExists($path);
                self::assertSame(0, DB::table('market_candle_conflict_journal')->count());
                self::assertSame('UNAVAILABLE', app(MarketCandleConflictService::class)->integrityStatus('binance', 'BTCUSDT', '30'));
            }
        } finally {
            DB::statement('ALTER TABLE market_candle_conflict_journal DROP CONSTRAINT v4_reject_receipt');
        }
        app(CandleConflictRecovery::class)->recover($path);
        self::assertSame(1, DB::table('market_candle_conflicts')->value('occurrence_count'));
    }

    public function test_invalid_observation_from_a_producer_cannot_lose_the_only_conflict_evidence(): void
    {
        try {
            app(MarketCandleConflictService::class)->recordConflict('binance', 'linear', 'BTCUSDT', '30', '2026-01-01T00:30:00Z', ['close' => 'NaN'], ['close' => '103'], 'close', 'Invalid stored immutable value.');
            self::fail('Invalid evidence was accepted.');
        } catch (CandleIntegrityUnavailable $e) {
            self::assertSame('CANDLE_JOURNAL_RECORD_INVALID', $e->reasonCode);
        }
        self::assertCount(1, app(CandleConflictJournal::class)->pending());
        self::assertSame('UNAVAILABLE', app(MarketCandleConflictService::class)->integrityStatus('binance', 'BTCUSDT', '30'));
        self::assertSame(0, DB::table('market_candle_conflicts')->count());
    }

    private function entry(): string
    {
        return app(CandleConflictJournal::class)->begin(CandleConflictEvidenceFixture::make());
    }

    private function worker(string $path, string $mode, string $marker): Process
    {
        $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/candle_recovery_worker.php'), $path, $mode, $marker], base_path());
        $worker->setTimeout(20);
        $worker->start();

        return $worker;
    }
}
