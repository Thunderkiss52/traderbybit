<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

trait CleansCandleConflictEvidence
{
    private function registerConflictCleanup(): void
    {
        $path = storage_path('framework/testing/integrity-'.bin2hex(random_bytes(8)));
        config(['market_data.conflict_journal_path' => $path]);
        // Registered after RefreshDatabase's rollback callback; never delete on a live fixture transaction.
        $this->beforeApplicationDestroyed(function () use ($path): void {
            $db = DB::connection();
            $expectedDb = (string) (env('TEST_DB_DATABASE') ?: env('DB_DATABASE', 'bybit_test'));
            if ($db->getDatabaseName() !== $expectedDb || $db->transactionLevel() !== 0) {
                throw new \RuntimeException('Conflict fixture cleanup requires isolated, rolled-back PostgreSQL.');
            }
            $db->table('market_candle_conflict_journal')->delete();
            $db->table('market_candle_conflicts')->delete();
            foreach (glob($path.'/recovered/*.json') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($path.'/recovered')) {
                rmdir($path.'/recovered');
            }
            foreach (glob($path.'/*.json') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($path)) {
                rmdir($path);
            }
        });
    }
}
