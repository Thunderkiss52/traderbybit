<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Research\Application\ResearchJobService;
use App\Research\Infrastructure\Persistence\ResearchJobRecord;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ResearchJobSubmissionPostgresIntegrationTest extends TestCase
{
    public function test_migration_refuses_existing_active_duplicates_without_changing_them(): void
    {
        $this->ensurePostgresTestDatabaseReady('research_jobs');
        $db = DB::connection('pgsql');
        $db->statement('CREATE TEMP TABLE research_jobs (kind text NOT NULL, idempotency_key text NOT NULL, status text NOT NULL)');
        try {
            $db->table('research_jobs')->insert([
                ['kind' => 'BACKTEST', 'idempotency_key' => 'duplicate', 'status' => 'QUEUED'],
                ['kind' => 'BACKTEST', 'idempotency_key' => 'duplicate', 'status' => 'RUNNING'],
            ]);
            $migration = require database_path('migrations/2026_11_04_000000_add_active_research_job_idempotency_index.php');
            try {
                $migration->up();
                self::fail('The migration accepted conflicting active jobs.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('duplicates require investigation', $error->getMessage());
            }
            self::assertSame(2, $db->table('research_jobs')->count());
        } finally {
            $db->statement('DROP TABLE IF EXISTS pg_temp.research_jobs');
        }
    }

    public function test_active_uniqueness_and_terminal_key_reuse(): void
    {
        $this->ensurePostgresTestDatabaseReady('research_jobs');
        $key = 'r3-'.Uuid::uuid4()->toString();
        try {
            $first = app(ResearchJobService::class)->submitJob(['kind' => 'BACKTEST', 'idempotency_key' => $key, 'payload' => ['exchange' => 'bybit']]);
            try {
                ResearchJobRecord::query()->create([
                    'job_id' => Uuid::uuid4()->toString(), 'kind' => 'BACKTEST', 'idempotency_key' => $key,
                    'status' => 'QUEUED', 'requested_at_utc' => now('UTC'),
                ]);
                self::fail('A duplicate active research job bypassed the database index.');
            } catch (QueryException $error) {
                self::assertSame('23505', $error->errorInfo[0]);
            }

            $first->update(['status' => 'CANCELLED']);
            $replacement = app(ResearchJobService::class)->submitJob(['kind' => 'BACKTEST', 'idempotency_key' => $key, 'payload' => ['exchange' => 'bybit']]);
            self::assertNotSame($first->job_id, $replacement->job_id);
            self::assertSame(2, ResearchJobRecord::query()->where('kind', 'BACKTEST')->where('idempotency_key', $key)->count());
        } finally {
            ResearchJobRecord::query()->where('idempotency_key', $key)->delete();
        }
    }

    public function test_independent_submitters_serialize_on_the_same_key(): void
    {
        $this->ensurePostgresTestDatabaseReady('research_jobs');
        $key = 'r3-'.Uuid::uuid4()->toString();
        $label = 'vector-r3-'.substr(hash('sha256', $key), 0, 12);
        $db = DB::connection('pgsql');
        $workers = [];
        try {
            $db->beginTransaction();
            $db->select('SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))', ['BACKTEST|'.$key]);
            for ($i = 0; $i < 2; $i++) {
                $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/submit_research_job.php'), $key, $label.'-'.$i], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => (string) $db->getConfig('host'),
                    'DB_PORT' => (string) $db->getConfig('port'), 'DB_DATABASE' => 'bybit_test',
                    'DB_USERNAME' => (string) $db->getConfig('username'), 'DB_PASSWORD' => (string) $db->getConfig('password'),
                ]);
                $worker->setTimeout(20);
                $worker->start();
                self::assertTrue($worker->waitUntil(fn ($type, $output) => str_contains($output, 'READY')), $worker->getErrorOutput());
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 10;
            do {
                $blocked = (int) $db->selectOne("SELECT COUNT(*) AS n FROM pg_stat_activity WHERE application_name LIKE ? AND wait_event_type = 'Lock'", [$label.'%'])->n;
                if ($blocked === 2) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            self::assertSame(2, $blocked, 'Both independent connections must wait for the same submission lock.');
            $db->commit();

            $ids = [];
            foreach ($workers as $worker) {
                $worker->wait();
                self::assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $ids[] = trim(substr($worker->getOutput(), strlen("READY\n")));
            }
            self::assertSame($ids[0], $ids[1]);
            self::assertSame(1, ResearchJobRecord::query()->where('kind', 'BACKTEST')->where('idempotency_key', $key)->count());
        } finally {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            foreach ($workers as $worker) {
                $worker->stop();
            }
            ResearchJobRecord::query()->where('idempotency_key', $key)->delete();
        }
    }
}
