<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exchanges\Binance\BinanceCandleIngestion;
use App\Exchanges\Binance\BinanceCandleWindow;
use App\Exchanges\Binance\BinancePaperReadiness;
use App\MarketData\Application\MarketCandleConflictService;
use App\MarketData\Domain\CandleIntegrityUnavailable;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Concerns\CleansCandleConflictEvidence;
use Tests\TestCase;

final class BinanceCandleConflictDurabilityTest extends TestCase
{
    use CleansCandleConflictEvidence;

    private array $rows = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePostgresTestDatabaseReady('market_candle_conflicts');
        $this->registerConflictCleanup();
        Http::preventStrayRequests();
        Http::fake(['https://fapi.binance.com/fapi/v1/klines*' => fn () => Http::response($this->rows)]);
        config(['market_data.symbols' => ['BTCUSDT', 'ETHUSDT']]);
        $this->beforeApplicationDestroyed(fn () => DB::table('market_candles')->where('exchange', 'binance')->where('symbol', 'BTCUSDT')->delete());
    }

    #[DataProvider('boundaries')]
    public function test_conflict_survives_ingestion_and_enclosing_transaction_rollback(bool $outer, bool $quarantine): void
    {
        $this->rows = [$this->row(1)];
        app(BinanceCandleIngestion::class)->ingest('BTCUSDT', '5m', 1);
        $original = DB::table('market_candles')->where('exchange', 'binance')->where('symbol', 'BTCUSDT')->first();
        $config = DB::connection()->getConfig();
        $observer = app(ConnectionFactory::class)->make($config, 'observer');
        $mainPid = DB::selectOne('select pg_backend_pid() as pid')->pid;
        $observerPid = $observer->selectOne('select pg_backend_pid() as pid')->pid;
        self::assertNotSame($mainPid, $observerPid);
        if ($outer) {
            DB::beginTransaction();
        }
        try {
            $this->rows = [$this->row(0), $this->row(1, '103'), $this->row(2)];
            try {
                $report = app(BinanceCandleIngestion::class)->ingest('BTCUSDT', '5m', 3, $quarantine);
                self::assertTrue($quarantine);
                self::assertSame(2, $report['new_closed_candles']);
                self::assertSame('PARTIAL_INGESTION_WITH_CONFLICTS', $report['status']);
            } catch (DomainException $e) {
                self::assertFalse($quarantine);
                self::assertSame('Conflicting immutable Binance close observation.', $e->getMessage());
            }
            self::assertSame($outer ? 1 : 0, DB::transactionLevel());
            self::assertSame(1, $observer->table('market_candle_conflicts')->where('symbol', 'BTCUSDT')->count());
        } finally {
            if ($outer) {
                DB::rollBack();
            }
        }
        self::assertSame(1, $observer->table('market_candle_conflicts')->where('symbol', 'BTCUSDT')->count());
        self::assertSame($quarantine && ! $outer ? 3 : 1, DB::table('market_candles')->where('exchange', 'binance')->where('symbol', 'BTCUSDT')->count());
        self::assertEquals($original, DB::table('market_candles')->where('id', $original->id)->first());
        fwrite(STDOUT, json_encode(['durability' => 'VERIFIED', 'main_pid' => $mainPid, 'observer_pid' => $observerPid, 'outer_rollback' => $outer, 'quarantine' => $quarantine, 'committed_conflicts' => $observer->table('market_candle_conflicts')->where('symbol', 'BTCUSDT')->count()], JSON_THROW_ON_ERROR)."\n");
        $observer->disconnect();
    }

    public static function boundaries(): array
    {
        return [[false, false], [false, true], [true, false], [true, true]];
    }

    #[DataProvider('quarantineModes')]
    public function test_failed_persistence_leaves_durable_scoped_unavailable_evidence_and_preserves_quarantined_valid_candles(bool $quarantine): void
    {
        $this->rows = [$this->row(1)];
        app(BinanceCandleIngestion::class)->ingest('BTCUSDT', '5m', 1);
        DB::statement("ALTER TABLE market_candle_conflicts ADD CONSTRAINT test_reject_conflict CHECK (symbol <> 'BTCUSDT')");
        try {
            $this->rows = [$this->row(0), $this->row(1, '103'), $this->row(2)];
            try {
                app(BinanceCandleIngestion::class)->ingest('BTCUSDT', '5m', 3, $quarantine);
                self::fail('Failed persistence was accepted.');
            } catch (CandleIntegrityUnavailable $e) {
                self::assertStringContainsString('CANDLE_INTEGRITY_UNAVAILABLE', $e->getMessage());
                if ($quarantine) {
                    self::assertSame('INTEGRITY_UNAVAILABLE', $e->ingestionReport['status']);
                    self::assertSame(2, $e->ingestionReport['new_closed_candles']);
                } else {
                    self::assertNull($e->ingestionReport);
                }
            }
        } finally {
            DB::statement('ALTER TABLE market_candle_conflicts DROP CONSTRAINT test_reject_conflict');
        }
        self::assertSame($quarantine ? 3 : 1, DB::table('market_candles')->where('exchange', 'binance')->where('symbol', 'BTCUSDT')->count());
        self::assertSame(0, DB::table('market_candle_conflicts')->where('symbol', 'BTCUSDT')->count());
        $files = glob(config('market_data.conflict_journal_path').'/*.json');
        self::assertCount(1, $files);
        $evidence = json_decode(file_get_contents($files[0]), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('103', $evidence['incoming_values']['close']);
        self::assertSame('102.000000000000', $evidence['stored_values']['close']);
        self::assertSame('CANDLE_HISTORY_INTEGRITY_UNAVAILABLE', $this->window());
        self::assertSame('READY', $this->window('ETHUSDT'));
        // A new service instance still sees the durable failure marker after DB recovery.
        self::assertSame('UNAVAILABLE', (new MarketCandleConflictService)->integrityStatus('binance', 'BTCUSDT', '5'));
    }

    public static function quarantineModes(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('readFailures')]
    public function test_all_integrity_reads_and_consumers_fail_closed(string $failure): void
    {
        $sql = $failure === 'table'
            ? ['ALTER TABLE market_candle_conflicts RENAME TO test_hidden_conflicts', 'ALTER TABLE test_hidden_conflicts RENAME TO market_candle_conflicts']
            : ['ALTER TABLE market_candle_conflicts RENAME COLUMN status TO test_hidden_status', 'ALTER TABLE market_candle_conflicts RENAME COLUMN test_hidden_status TO status'];
        DB::statement($sql[0]);
        try {
            $service = app(MarketCandleConflictService::class);
            foreach ([fn () => $service->hasActiveConflict('binance', 'BTCUSDT', '5'), fn () => $service->countUnresolved(), fn () => $service->unresolvedConflicts()] as $read) {
                try {
                    $read();
                    self::fail('Integrity read hid a SQL failure.');
                } catch (CandleIntegrityUnavailable) {
                    self::assertTrue(true);
                }
            }
            self::assertSame('CANDLE_HISTORY_INTEGRITY_UNAVAILABLE', $this->window());
            $response = $this->getJson('/api/v1/research/health/data-freshness?exchange=binance');
            $response->assertOk()->assertJsonPath('data.status', 'CRITICAL')->assertJsonPath('data.integrity_status', 'UNAVAILABLE')
                ->assertJsonPath('data.unresolved_conflicts_count', null)->assertJsonPath('data.conflicts', null);
            self::assertSame('UNAVAILABLE', $response->json('data.candle_freshness.0.integrity_status'));
            $this->seedWindow();
            $report = app(BinancePaperReadiness::class)->inspect();
            self::assertSame('BLOCKED', $report['overall_readiness']);
            self::assertSame('CANDLE_HISTORY_INTEGRITY_UNAVAILABLE', $report['candle_history']['symbols']['BTCUSDT']['5']['status']);
        } finally {
            DB::statement($sql[1]);
        }
    }

    public static function readFailures(): array
    {
        return [['table'], ['column']];
    }

    #[DataProvider('concurrentSeeds')]
    public function test_resolved_conflict_reopens_and_atomic_concurrent_upserts_preserve_counts_and_provenance(bool $resolved): void
    {
        $service = app(MarketCandleConflictService::class);
        $first = null;
        if ($resolved) {
            $this->record($service);
            $first = DB::table('market_candle_conflicts')->where('symbol', 'BTCUSDT')->first();
            DB::table('market_candle_conflicts')->where('id', $first->id)->update(['status' => 'RESOLVED', 'resolved_at' => now(), 'resolution_notes' => 'Prior operator decision']);
        } else {
            mkdir(config('market_data.conflict_journal_path'), 0770, true);
        }
        $processes = [];
        $barrier = config('market_data.conflict_journal_path').'/start';
        for ($i = 0; $i < 4; $i++) {
            $process = new Process([PHP_BINARY, base_path('tests/Fixtures/candle_conflict_writer.php'), (string) $i, config('market_data.conflict_journal_path'), $barrier], base_path());
            $process->setTimeout(15);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 5;
        while (count(glob($barrier.'.*')) < 4 && microtime(true) < $deadline) {
            usleep(1000);
        }
        self::assertCount(4, glob($barrier.'.*'), implode(' ', array_map(fn ($p) => $p->getErrorOutput(), $processes)));
        foreach (glob($barrier.'.*') as $ready) {
            unlink($ready);
        }
        file_put_contents($barrier, 'go');
        foreach ($processes as $process) {
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        }
        unlink($barrier);
        self::assertSame(1, DB::table('market_candle_conflicts')->where('symbol', 'BTCUSDT')->count());
        $after = DB::table('market_candle_conflicts')->where('symbol', 'BTCUSDT')->first();
        self::assertSame($resolved ? 5 : 4, $after->occurrence_count);
        self::assertSame('UNRESOLVED', $after->status);
        self::assertNull($after->resolved_at);
        self::assertSame(['close' => '102'], json_decode($after->stored_values, true));
        if ($resolved) {
            self::assertSame($first->first_seen_at, $after->first_seen_at);
            self::assertSame('Prior operator decision', $after->resolution_notes);
        }
        self::assertContains(json_decode($after->incoming_values, true)['close'], ['103', '104', '105', '106']);
        self::assertSame('CANDLE_HISTORY_INTEGRITY_CONFLICT', $this->window());
    }

    public static function concurrentSeeds(): array
    {
        return [['resolved' => false], ['resolved' => true]];
    }

    public function test_unavailable_journal_storage_prevents_ready_and_successful_persistence_claims(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'integrity-unavailable-');
        config(['market_data.conflict_journal_path' => $path]);
        try {
            self::assertSame('CANDLE_HISTORY_INTEGRITY_UNAVAILABLE', $this->window());
            $this->expectException(CandleIntegrityUnavailable::class);
            $this->record(app(MarketCandleConflictService::class));
        } finally {
            unlink($path);
        }
    }

    public function test_database_connection_failure_is_unavailable_and_leaves_evidence_after_recovery(): void
    {
        $config = config('database.connections.pgsql');
        config(['database.connections.pgsql.port' => 1]);
        DB::purge('pgsql');
        try {
            self::assertSame('CANDLE_HISTORY_INTEGRITY_UNAVAILABLE', $this->window());
            $service = app(MarketCandleConflictService::class);
            foreach ([fn () => $service->countUnresolved(), fn () => $service->unresolvedConflicts(), fn () => $this->record($service)] as $operation) {
                try {
                    $operation();
                    self::fail('Unavailable PostgreSQL was accepted.');
                } catch (CandleIntegrityUnavailable) {
                    self::assertTrue(true);
                }
            }
            $this->getJson('/api/v1/research/health/data-freshness')->assertOk()->assertJsonPath('data.status', 'CRITICAL')->assertJsonPath('data.integrity_status', 'UNAVAILABLE');
        } finally {
            config(['database.connections.pgsql' => $config]);
            DB::purge('pgsql');
        }
        self::assertSame('CANDLE_HISTORY_INTEGRITY_UNAVAILABLE', $this->window());
        self::assertSame(0, DB::table('market_candle_conflicts')->count());
    }

    public function test_contended_conflict_row_has_a_bounded_timeout_and_preserves_pending_evidence(): void
    {
        $this->record(app(MarketCandleConflictService::class));
        DB::beginTransaction();
        DB::table('market_candle_conflicts')->where('symbol', 'BTCUSDT')->lockForUpdate()->first();
        $start = microtime(true);
        try {
            try {
                $this->record(app(MarketCandleConflictService::class));
                self::fail('A locked conflict was accepted.');
            } catch (CandleIntegrityUnavailable) {
                self::assertLessThan(5, microtime(true) - $start);
            }
        } finally {
            DB::rollBack();
        }
        self::assertSame(1, DB::table('market_candle_conflicts')->where('symbol', 'BTCUSDT')->value('occurrence_count'));
        self::assertSame('CANDLE_HISTORY_INTEGRITY_UNAVAILABLE', $this->window());
    }

    private function record(MarketCandleConflictService $service): void
    {
        $service->recordConflict('binance', 'linear', 'BTCUSDT', '5', '2026-01-01T00:05:00Z', ['close' => '102'], ['close' => '103'], 'close', 'Conflicting immutable Binance close observation.');
    }

    private function window(string $symbol = 'BTCUSDT'): string
    {
        $now = 1767226500;

        return BinanceCandleWindow::status(array_map(fn ($i) => $now - 300 - $i * 300, range(0, 219)), 300, $now, 220, app(MarketCandleConflictService::class), 'binance', $symbol, '5');
    }

    private function seedWindow(): void
    {
        $now = CarbonImmutable::now('UTC')->startOfMinute()->timestamp;
        $now -= $now % 300;
        $rows = [];
        for ($i = 1; $i <= 220; $i++) {
            $rows[] = ['exchange' => 'binance', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'interval' => '5', 'open_time' => gmdate('Y-m-d H:i:s', $now - $i * 300), 'open' => '100', 'high' => '105', 'low' => '99', 'close' => '102', 'volume' => '10', 'turnover' => '1010', 'is_closed' => true];
        }
        DB::table('market_candles')->insert($rows);
    }

    private function row(int $i, string $close = '102'): array
    {
        return [1767225600000 + $i * 300000, '100', '105', '99', $close, '10', 1767225600000 + ($i + 1) * 300000 - 1, '1010'];
    }
}
