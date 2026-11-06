<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Audit\Application\AuditExportService;
use App\Audit\Infrastructure\Persistence\AuditExportRecord;
use App\PaperTrading\Application\PaperAccountService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;
use ZipArchive;

final class Rem08AuditExportConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_rem08_audit_export_manifest_and_metadata_contain_snapshot_watermark_and_checksums(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('ZIP extension required for audit archive verification.');
        }

        $this->app->make(PaperAccountService::class)->default();
        Queue::fake();

        $service = $this->app->make(AuditExportService::class);
        $job = $service->request(1);

        try {
            $service->generate($job->id);

            $record = AuditExportRecord::query()->findOrFail($job->id);
            self::assertSame('COMPLETED', $record->status);

            $archivePath = $service->archivePath($job->id);
            self::assertFileExists($archivePath);

            $zip = new ZipArchive;
            self::assertTrue($zip->open($archivePath) === true);

            // 1. Verify manifest.csv headers and snapshot watermark per file
            self::assertNotFalse($zip->locateName('manifest.csv'));
            $manifestContent = $zip->getFromName('manifest.csv');
            self::assertIsString($manifestContent);

            $manifestLines = array_values(array_filter(explode("\n", trim($manifestContent)), 'strlen'));
            self::assertNotEmpty($manifestLines);

            $manifestHeader = str_getcsv($manifestLines[0]);
            self::assertContains('snapshot_watermark', $manifestHeader);
            self::assertContains('sha256', $manifestHeader);
            self::assertContains('row_count', $manifestHeader);
            self::assertContains('source_scope', $manifestHeader);

            $watermarkIndex = array_search('snapshot_watermark', $manifestHeader, true);
            $sha256Index = array_search('sha256', $manifestHeader, true);
            $rowCountIndex = array_search('row_count', $manifestHeader, true);
            $sourceScopeIndex = array_search('source_scope', $manifestHeader, true);
            $fileNameIndex = array_search('file_name', $manifestHeader, true);

            self::assertNotFalse($watermarkIndex);
            self::assertNotFalse($sha256Index);
            self::assertNotFalse($rowCountIndex);
            self::assertNotFalse($sourceScopeIndex);
            self::assertNotFalse($fileNameIndex);

            $extractedWatermarks = [];
            for ($i = 1; $i < count($manifestLines); $i++) {
                $cells = str_getcsv($manifestLines[$i]);
                $fileName = $cells[$fileNameIndex] ?? '';
                $watermark = $cells[$watermarkIndex] ?? '';
                $sourceScope = $cells[$sourceScopeIndex] ?? '';
                $sha = $cells[$sha256Index] ?? '';
                $rows = (int) ($cells[$rowCountIndex] ?? -1);

                self::assertSame('REPEATABLE_READ_SNAPSHOT', $sourceScope, "source_scope for {$fileName} must be REPEATABLE_READ_SNAPSHOT");
                self::assertMatchesRegularExpression('/^\d+:\d+:/', $watermark, "snapshot_watermark for {$fileName} must be a PostgreSQL snapshot");
                self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $sha, "sha256 for {$fileName} must be 64-char hex");
                self::assertGreaterThanOrEqual(0, $rows, "row_count for {$fileName} must be >= 0");

                $extractedWatermarks[] = $watermark;
            }

            // All datasets in the snapshot share the exact same snapshot watermark
            $uniqueWatermarks = array_unique($extractedWatermarks);
            self::assertCount(1, $uniqueWatermarks, 'All exported tables must share the identical transaction snapshot watermark.');
            $canonicalWatermark = $uniqueWatermarks[0];

            // 2. Verify export_metadata.csv
            self::assertNotFalse($zip->locateName('export_metadata.csv'));
            $metadataContent = $zip->getFromName('export_metadata.csv');
            self::assertIsString($metadataContent);

            $metadataMap = [];
            foreach (array_filter(explode("\n", trim($metadataContent)), 'strlen') as $line) {
                $parts = str_getcsv($line);
                if (count($parts) >= 2) {
                    $metadataMap[$parts[0]] = $parts[1];
                }
            }

            self::assertSame('REPEATABLE_READ_SNAPSHOT', $metadataMap['source_consistency'] ?? null);
            self::assertSame('REPEATABLE READ', $metadataMap['isolation_level'] ?? null);
            self::assertSame($canonicalWatermark, $metadataMap['snapshot_watermark'] ?? null);
            self::assertArrayHasKey('snapshot_txid', $metadataMap);
            self::assertMatchesRegularExpression('/^\d+$/', $metadataMap['snapshot_txid']);
            self::assertSame('FILE_BYTES_AND_TRANSACTIONAL_SNAPSHOT_WATERMARK', $metadataMap['checksum_scope'] ?? null);

            // 3. Verify data_quality.csv
            self::assertNotFalse($zip->locateName('data_quality.csv'));
            $qualityContent = $zip->getFromName('data_quality.csv');
            self::assertIsString($qualityContent);
            self::assertStringContainsString('REPEATABLE_READ_SNAPSHOT', $qualityContent);
            self::assertStringContainsString($canonicalWatermark, $qualityContent);

            // 4. Verify checksums.csv
            self::assertNotFalse($zip->locateName('checksums.csv'));
            $checksumContent = $zip->getFromName('checksums.csv');
            self::assertIsString($checksumContent);
            self::assertStringContainsString('manifest.csv', $checksumContent);
            self::assertStringNotContainsString('checksums.csv,', $checksumContent);

            $zip->close();
        } finally {
            @unlink($service->archivePath($job->id));
        }
    }

    public function test_rem08_repeatable_read_snapshot_isolation_shields_multi_table_export_from_concurrent_modifications(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('ZIP extension required for audit archive verification.');
        }

        $account = $this->app->make(PaperAccountService::class)->default();
        Queue::fake();

        $service = $this->app->make(AuditExportService::class);
        $now = CarbonImmutable::now('UTC');
        $this->travelTo($now);

        // Ensure we are at transaction level 0 so generate() starts its own REPEATABLE READ snapshot transaction
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        $job = $service->request(1);

        $injectedSignalId = null;
        $injectedAfterTableRead = false;

        // Simulate an independent concurrent writer:
        // As soon as the export transaction reads paper_accounts, an external writer inserts
        // a new signal into the signals table and immediately commits it via separate PDO connection.
        DB::listen(function ($query) use (&$injectedSignalId, &$injectedAfterTableRead, $now): void {
            if (! $injectedAfterTableRead && str_contains($query->sql, 'paper_accounts')) {
                $injectedAfterTableRead = true;

                $testHost = env('TEST_DB_HOST') ?: config('database.connections.pgsql.host') ?: '127.0.0.1';
                $testPort = env('TEST_DB_PORT') ?: config('database.connections.pgsql.port') ?: 26432;
                $testDb = env('TEST_DB_DATABASE') ?: config('database.connections.pgsql.database') ?: 'bybit_test_f';
                $testUser = env('TEST_DB_USERNAME') ?: config('database.connections.pgsql.username') ?: 'postgres';
                $testPass = env('TEST_DB_PASSWORD') ?: config('database.connections.pgsql.password') ?: 'testpass';

                $pdo = new PDO("pgsql:host={$testHost};port={$testPort};dbname={$testDb}", (string) $testUser, (string) $testPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);

                $id = (string) Str::uuid();
                $injectedSignalId = $id;

                $stmt = $pdo->prepare('INSERT INTO signals (id, strategy_id, strategy_version, schema_version, exchange, category, symbol, timeframe, side, score, decision_time, candle_close_time, expires_at, maximum_holding_minutes, reasons, risks, indicator_snapshot, contract, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?::jsonb, ?::jsonb, ?, ?)');
                $stmt->execute([
                    $id, 'baseline', '1.0.0', '1.0.0', 'bybit', 'linear', 'BTCUSDT', '15', 'BUY', 95,
                    $now->subMinutes(5)->toIso8601String(),
                    $now->subMinutes(5)->toIso8601String(),
                    $now->addHours(2)->toIso8601String(),
                    120, '[]', '[]', '[]', '[]',
                    $now->subMinutes(5)->toIso8601String(),
                    $now->subMinutes(5)->toIso8601String(),
                ]);
            }
        });

        try {
            $service->generate($job->id);

            self::assertTrue($injectedAfterTableRead, 'Concurrent writer must have triggered during the export loop.');
            self::assertNotNull($injectedSignalId, 'Concurrent signal ID must be recorded.');

            // Open the generated zip archive
            $zip = new ZipArchive;
            self::assertTrue($zip->open($service->archivePath($job->id)) === true);

            $signalsCsv = $zip->getFromName('signals.csv');
            self::assertIsString($signalsCsv);

            // In REPEATABLE READ snapshot isolation: the concurrent insert is completely SHIELDED from the export
            self::assertStringNotContainsString(
                $injectedSignalId,
                $signalsCsv,
                'REPEATABLE READ snapshot isolation invariant violated: concurrently committed signal leaked into the export!'
            );

            $zip->close();

            // Verify that outside the export transaction, the signal exists in the database
            $existsInDb = DB::table('signals')->where('id', $injectedSignalId)->exists();
            self::assertTrue($existsInDb, 'Concurrently injected signal must exist in the database after commit.');
        } finally {
            if ($injectedSignalId !== null) {
                DB::table('signals')->where('id', $injectedSignalId)->delete();
            }
            if (isset($job)) {
                DB::table('audit_export_jobs')->where('id', $job->id)->delete();
                @unlink($service->archivePath($job->id));
            }
            if (DB::transactionLevel() === 0) {
                DB::beginTransaction();
            }
        }
    }
}
