<?php

declare(strict_types=1);

namespace App\Audit\Application;

use App\Audit\Infrastructure\Persistence\AuditExportRecord;
use App\Audit\Jobs\GenerateAuditExportJob;
use App\PaperTrading\Application\DailyPerformanceService;
use App\PaperTrading\Infrastructure\Persistence\EquitySnapshotRecord;
use App\Strategy\Application\StrategyRegistry;
use App\StrategyBuilder\PrimaryStrategies;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Private, read-only, bounded, best-effort as-of audit snapshot.
 * Never performs strategy evaluation or places paper/live orders.
 */
final class AuditExportService
{
    /** Selected, non-secret columns only. Backtest data is deliberately separate. */
    private const DATASETS = [
        'paper_accounts' => ['id', 'exchange_id', 'is_primary', 'primary_strategy_id', 'research_experiment_id', 'name', 'mode', 'status', 'strategy_id', 'strategy_version', 'configuration_hash', 'starting_balance', 'current_equity', 'peak_equity', 'realized_pnl', 'unrealized_pnl', 'reserved_risk', 'current_drawdown_percent', 'maximum_drawdown_percent', 'created_at', 'updated_at'],
        'strategy_observations' => ['id', 'exchange', 'category', 'symbol', 'strategy_id', 'strategy_version', 'configuration_hash', 'side', 'score', 'quality_class', 'decision_time', 'reasons', 'analysis', 'created_at'],
        'signals' => ['id', 'strategy_id', 'strategy_version', 'exchange', 'category', 'symbol', 'timeframe', 'side', 'quality_class', 'score', 'decision_time', 'candle_close_time', 'expires_at', 'entry_low', 'entry_high', 'stop_loss', 'take_profit_1', 'take_profit_2', 'risk_reward_1', 'risk_reward_2', 'model_risk_percent', 'maximum_holding_minutes', 'reasons', 'risks', 'indicator_snapshot', 'contract', 'created_at'],
        'risk_decisions' => ['id', 'exchange_id', 'paper_account_id', 'signal_id', 'allowed', 'reasons', 'snapshot', 'risk_version', 'decided_at', 'created_at'],
        'paper_signal_states' => ['id', 'exchange_id', 'paper_account_id', 'signal_id', 'status', 'reason', 'processed_at', 'created_at'],
        'virtual_positions' => ['id', 'exchange_id', 'paper_account_id', 'signal_id', 'symbol', 'side', 'status', 'entry_price', 'stop_loss', 'take_profit_1', 'take_profit_2', 'initial_quantity', 'remaining_quantity', 'risk_capital', 'gross_pnl', 'net_pnl', 'unrealized_pnl', 'fees', 'funding', 'r_multiple', 'opened_at', 'closed_at', 'maximum_holding_until', 'funding_complete', 'ambiguous_execution', 'awaiting_market_data', 'strategy_version'],
        'virtual_executions' => ['id', 'exchange_id', 'position_id', 'type', 'side', 'quantity', 'price', 'gross_pnl', 'commission', 'slippage', 'market_event_at', 'registered_at', 'price_source', 'ambiguous_execution', 'idempotency_key'],
        'trading_journal_events' => ['sequence', 'id', 'exchange_id', 'paper_account_id', 'signal_id', 'position_id', 'event_type', 'occurred_at', 'idempotency_key'],
        'equity_snapshots' => ['id', 'exchange_id', 'paper_account_id', 'recorded_at', 'equity', 'realized_pnl', 'unrealized_pnl', 'drawdown_percent', 'source_event_key'],
        'position_funding_events' => ['id', 'exchange_id', 'position_id', 'market_funding_event_id', 'quantity', 'mark_price', 'rate', 'amount', 'funding_at'],
        'level_interaction_forecasts' => ['id', 'exchange', 'category', 'symbol', 'timeframe', 'event', 'side', 'triggered_at', 'horizon_ends_at', 'model_version', 'configuration_hash', 'sample_size', 'success_probability', 'failure_probability', 'unresolved_probability', 'confidence_low', 'confidence_high', 'validation_status', 'outcome', 'outcome_at', 'brier_score', 'features'],
        'candlestick_pattern_occurrences' => ['id', 'exchange', 'category', 'symbol', 'timeframe', 'pattern_name', 'direction', 'pattern_type', 'version', 'formation_time', 'detected_at', 'bar_count', 'evidence'],
        'market_context_snapshots' => ['id', 'exchange', 'category', 'symbol', 'observed_at', 'bid', 'ask', 'turnover_24h', 'tick_size', 'funding_rate', 'open_interest', 'open_interest_change'],
        'market_candles' => ['id', 'exchange', 'category', 'symbol', 'interval', 'open_time', 'open', 'high', 'low', 'close', 'volume', 'turnover', 'is_closed'],
        'market_funding_events' => ['id', 'exchange', 'category', 'symbol', 'funding_at', 'rate', 'mark_price'],
        'market_open_interest_events' => ['id', 'exchange', 'category', 'symbol', 'interval', 'observed_at', 'open_interest'],
        'research_patterns' => ['id', 'pattern_run_id', 'direction', 'status', 'terms', 'metrics', 'rule_hash', 'version', 'created_at'],
        'research_pattern_matches' => ['id', 'pattern_id', 'symbol', 'direction', 'matched_at', 'feature_snapshot', 'status', 'outcome', 'net_r', 'outcome_at'],
        'strategy_health_snapshots' => ['id', 'strategy_id', 'strategy_version', 'symbol', 'period', 'status', 'funnel_metrics', 'diagnostics', 'snapshot_at', 'created_at'],
        'reconciliation_runs' => ['id', 'paper_account_id', 'status', 'starting_balance', 'recalculated_balance', 'stored_balance', 'recalculated_equity', 'stored_equity', 'discrepancy_count', 'findings', 'methodology_version', 'completed_at', 'created_at'],
        'counterfactual_evaluations' => ['id', 'signal_id', 'strategy_id', 'strategy_version', 'symbol', 'timeframe', 'decision_time', 'skip_reason', 'filter_family', 'candidate_direction', 'hypothetical_entry', 'status', 'mfe', 'mae', 'r_multiple', 'net_return_percent', 'resolved_at', 'created_at'],
        'strategy_degradation_snapshots' => ['id', 'strategy_id', 'strategy_version', 'symbol', 'horizon', 'sample_size', 'expectancy_r', 'profit_factor', 'win_rate', 'status', 'degradation_type', 'as_of_time', 'created_at'],
        'portfolio_correlation_snapshots' => ['id', 'horizon', 'concentration_score', 'alerts', 'evaluated_at', 'created_at'],
        'research_recommendations' => ['id', 'category', 'strategy_id', 'title', 'description', 'status', 'created_at'],
        'learning_candidate_strategies' => ['id', 'name', 'parent_strategy_id', 'candidate_version', 'configuration_hash', 'status', 'promoted_at', 'created_at'],
        'learning_model_artifacts' => ['id', 'name', 'model_type', 'target_event', 'version', 'artifact_hash', 'status', 'created_at'],
        'autonomy_approval_events' => ['id', 'actor', 'action', 'target_type', 'target_id', 'previous_state', 'new_state', 'reason', 'created_at'],
        'learning_feature_snapshots' => ['id', 'symbol', 'timeframe', 'decision_time', 'available_at', 'regime', 'label_status', 'label_outcome', 'label_r_multiple', 'created_at'],
    ];

    private const TIMES = [
        'paper_accounts' => 'created_at', 'strategy_observations' => 'decision_time',
        'signals' => 'decision_time', 'risk_decisions' => 'decided_at',
        'paper_signal_states' => 'processed_at', 'virtual_positions' => 'opened_at',
        'virtual_executions' => 'market_event_at', 'trading_journal_events' => 'occurred_at',
        'equity_snapshots' => 'recorded_at', 'position_funding_events' => 'funding_at',
        'level_interaction_forecasts' => 'triggered_at', 'candlestick_pattern_occurrences' => 'detected_at',
        'market_context_snapshots' => 'observed_at',
        'market_candles' => 'open_time', 'market_funding_events' => 'funding_at',
        'market_open_interest_events' => 'observed_at', 'research_pattern_runs' => 'created_at',
        'research_patterns' => 'created_at', 'research_pattern_matches' => 'matched_at',
        'strategy_health_snapshots' => 'created_at', 'reconciliation_runs' => 'created_at',
        'counterfactual_evaluations' => 'decision_time', 'strategy_degradation_snapshots' => 'as_of_time',
        'portfolio_correlation_snapshots' => 'evaluated_at', 'research_recommendations' => 'created_at',
        'learning_candidate_strategies' => 'created_at', 'learning_model_artifacts' => 'created_at',
        'autonomy_approval_events' => 'created_at', 'learning_feature_snapshots' => 'decision_time',
    ];

    public function request(int $days): AuditExportRecord
    {
        if (! in_array($days, [1, 7, 14, 30], true)) {
            throw new \InvalidArgumentException('Unsupported audit export period.');
        }
        $now = now('UTC');
        // The first accepted request captures an exact rolling UTC window.
        // Quarter-hour bucketing is ONLY a retry/deduplication key: subsequent
        // clicks reuse that original cutoff rather than moving it or rewriting
        // an in-progress/private export.
        $end = $now->copy();
        $start = $end->copy()->subDays($days);
        $deduplicationBucket = $now->copy()->startOfHour()->addMinutes(intdiv($now->minute, 15) * 15);
        $key = hash('sha256', implode('|', [config('audit.schema_version'), $days, $deduplicationBucket->format('c'), 'research-paper-all']));
        $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'traderbybit:audit:'.$key)->toString();
        $inserted = DB::table('audit_export_jobs')->insertOrIgnore([
            'id' => $id, 'cache_key' => $key, 'period_days' => $days,
            'from_at' => $start, 'to_at' => $end, 'as_of' => $now,
            'status' => 'QUEUED', 'created_at' => $now, 'updated_at' => $now,
        ]);
        if ($inserted === 1 || DB::table('audit_export_jobs')->where('id', $id)
            ->where(function (Builder $q) use ($id): void {
                $q->where('status', 'FAILED');
                if (! is_file($this->archivePath($id))) {
                    $q->orWhere('status', 'COMPLETED');
                }
                // Recover tasks abandoned after a container restart/OOM.
                $q->orWhere(fn (Builder $stale) => $stale->where('status', 'RUNNING')
                    ->where('updated_at', '<', now('UTC')->subMinutes(45)));
                $q->orWhere(fn (Builder $stale) => $stale->where('status', 'QUEUED')
                    ->where('updated_at', '<', now('UTC')->subMinutes(15)));
            })->update(['status' => 'QUEUED', 'progress_percent' => 0, 'error_code' => null, 'updated_at' => now()]) === 1) {
            GenerateAuditExportJob::dispatch($id);
        }

        return AuditExportRecord::query()->findOrFail($id);
    }

    public function publicStatus(AuditExportRecord $export): array
    {
        $ready = $export->status === 'COMPLETED' && $export->expires_at?->isFuture()
            && is_file($this->archivePath($export->id));

        return [
            'id' => $export->id, 'period_days' => $export->period_days,
            'from_at' => $export->from_at, 'to_at' => $export->to_at,
            'status' => $ready ? 'READY' : ($export->status === 'COMPLETED' ? 'EXPIRED' : $export->status),
            'progress_percent' => $export->progress_percent, 'row_counts' => $export->row_counts,
            'archive_bytes' => $export->archive_bytes, 'archive_sha256' => $export->archive_sha256,
            'error_code' => $export->error_code, 'created_at' => $export->created_at,
            'download_url' => $ready ? URL::temporarySignedRoute('audit.download', now()->addMinutes(3), ['id' => $export->id], absolute: false) : null,
        ];
    }

    public function archivePath(string $id): string
    {
        // ID is verified UUID; never accept user-supplied filesystem paths.
        if (! Uuid::isValid($id)) {
            throw new RuntimeException('Invalid audit ID.');
        }

        return storage_path('app/private/audit_exports/'.$id.'.zip');
    }

    public function generate(string $id): void
    {
        $lock = Cache::lock('audit-export:'.$id, 1900);
        if (! $lock->get()) {
            return;
        }
        $workingDir = storage_path('app/private/audit_exports/.tmp-'.$id);
        $ownsTransaction = false;
        try {
            $export = AuditExportRecord::query()->findOrFail($id);
            if (Schema::hasTable('historical_download_entries') && DB::table('historical_download_entries')->where('family', 'klines')->where('checkpoint', '>', 0)->where('requested_from', '<', $export->to_at)->where('requested_to', '>', $export->from_at)->exists()) {
                $this->failed($id, 'RESTRICTED_SOURCE_EXPORT_BLOCKED');

                return;
            }
            if ($export->status === 'COMPLETED') {
                return;
            }
            if (! is_dir(dirname($workingDir)) && ! mkdir(dirname($workingDir), 0700, true) && ! is_dir(dirname($workingDir))) {
                throw new RuntimeException('audit_storage_unavailable');
            }
            if (disk_free_space(dirname($workingDir)) < (int) config('audit.min_free_disk_bytes')) {
                throw new RuntimeException('audit_disk_guard');
            }
            if (! is_dir($workingDir) && ! mkdir($workingDir, 0700, true) && ! is_dir($workingDir)) {
                throw new RuntimeException('audit_staging_unavailable');
            }
            $export->update(['status' => 'RUNNING', 'progress_percent' => 1, 'error_code' => null]);
            if (DB::getDriverName() === 'pgsql') {
                $statementTimeout = (int) config('audit.statement_timeout_ms', 60000);
                $lockTimeout = (int) config('audit.lock_timeout_ms', 5000);
                DB::statement("SET statement_timeout TO '{$statementTimeout}ms'");
                DB::statement("SET lock_timeout TO '{$lockTimeout}ms'");
            }

            $ownsTransaction = false;
            $snapshotWatermark = null;
            $snapshotTxId = null;

            if (DB::transactionLevel() === 0) {
                DB::beginTransaction();
                $ownsTransaction = true;
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                }
            }

            if (DB::getDriverName() === 'pgsql') {
                $snapshotRow = DB::selectOne('SELECT txid_current_snapshot()::text as snap, txid_current()::text as txid');
                if ($snapshotRow !== null) {
                    $snapshotWatermark = (string) $snapshotRow->snap;
                    $snapshotTxId = (string) $snapshotRow->txid;
                }
            }

            $counts = [];
            $captureStarted = now('UTC')->toIso8601String();
            $entries = [];
            $datasets = array_keys(self::DATASETS);
            foreach ($datasets as $index => $table) {
                $file = $workingDir.'/'.$table.'.csv';
                $count = $this->writeDataset($export, $table, $file);
                $counts[$table] = $count;
                $entries[$table.'.csv'] = ['rows' => $count, 'sha256' => hash_file('sha256', $file), 'bytes' => filesize($file)];
                if (array_sum(array_column($entries, 'bytes')) > (int) config('audit.max_staging_bytes')) {
                    throw new RuntimeException('audit_total_staging_size_limit');
                }
                $export->update(['progress_percent' => min(85, 5 + (int) floor((1 + $index) * 80 / count($datasets)))]);
            }
            $this->writeStrategies($workingDir.'/strategies.csv', $counts, $entries);
            $this->writeFunnel($export, $workingDir.'/strategy_funnel.csv', $counts, $entries);
            // The per-account funnel alone silently omits registered strategies
            // when their paper account has not yet been bootstrapped.
            $this->writePrimaryCoverage($workingDir.'/primary_strategy_coverage.csv', $counts, $entries);
            $this->writeDailySnapshots($export, $workingDir.'/daily_performance.csv', $counts, $entries);
            $this->writeQuality($workingDir.'/data_quality.csv', $counts, $entries, $snapshotWatermark);

            if ($ownsTransaction && DB::transactionLevel() > 0) {
                DB::commit();
                $ownsTransaction = false;
            }

            // V2.4 private Audit ZIP contains CSV ONLY. Keep existing payload CSV
            // names, with explicit CSV metadata, manifest and SHA-256 checksums.
            // The checksum list includes the finalized manifest but never itself.
            $metadata = [
                'export_id' => $export->id,
                'schema_version' => (string) config('audit.schema_version'),
                'period_hours' => (string) ($export->period_days * 24),
                'window_start_utc' => $export->from_at->toIso8601String(),
                'window_end_utc' => $export->to_at->toIso8601String(),
                'requested_at_utc' => $export->as_of->toIso8601String(),
                'generated_at_utc' => now('UTC')->toIso8601String(),
                'source_consistency' => $snapshotWatermark !== null ? 'REPEATABLE_READ_SNAPSHOT' : 'BOUNDED_BEST_EFFORT',
                'isolation_level' => 'REPEATABLE READ',
                'snapshot_watermark' => $snapshotWatermark ?? 'N/A',
                'snapshot_txid' => $snapshotTxId ?? 'N/A',
                'capture_started_at_utc' => $captureStarted,
                'capture_finished_at_utc' => now('UTC')->toIso8601String(),
                'record_population' => 'FORWARD_PAPER_AND_RESEARCH_NO_HISTORICAL_BACKTEST',
                'aggregation_scope' => 'PER_EXCHANGE_PER_STRATEGY',
                'expected_primary_account_pairs' => '26',
                'monetary_representation' => 'EXACT_DECIMAL_STRINGS',
                'checksum_scope' => 'FILE_BYTES_AND_TRANSACTIONAL_SNAPSHOT_WATERMARK',
            ];
            $this->writeExportMetadata($workingDir.'/export_metadata.csv', $metadata, $counts, $entries);
            $this->writeExportManifest($workingDir.'/manifest.csv', $entries, $export->id, $snapshotWatermark);
            $this->writeExportChecksums($workingDir.'/checksums.csv', $workingDir, array_keys($entries));
            $expectedArchiveHashes = array_map(static fn (array $entry): string => $entry['sha256'], $entries);
            $expectedArchiveHashes['manifest.csv'] = hash_file('sha256', $workingDir.'/manifest.csv');
            $expectedArchiveHashes['checksums.csv'] = hash_file('sha256', $workingDir.'/checksums.csv');
            $part = $this->archivePath($id).'.part';
            $zip = new ZipArchive;
            if ($zip->open($part, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('audit_zip_open_failed');
            }
            // Explicit allowlist: never include leftovers from an interrupted
            // run, arbitrary files, or old non-CSV manifests in a private ZIP.
            $archiveFiles = array_merge(array_keys($entries), ['manifest.csv', 'checksums.csv']);
            sort($archiveFiles, SORT_STRING);
            foreach ($archiveFiles as $filename) {
                if (! str_ends_with($filename, '.csv') || basename($filename) !== $filename
                    || ! is_file($workingDir.'/'.$filename)
                    || ! $zip->addFile($workingDir.'/'.$filename, $filename)) {
                    throw new RuntimeException('audit_archive_payload_missing_or_invalid');
                }
                $zip->setCompressionName($filename, ZipArchive::CM_DEFLATE, 1);
            }
            if (! $zip->close()) {
                throw new RuntimeException('audit_zip_close_failed');
            }
            $this->verifyArchivePayloads($part, $expectedArchiveHashes);
            if (filesize($part) > (int) config('audit.max_archive_bytes')) {
                throw new RuntimeException('audit_archive_size_limit');
            }
            if (! rename($part, $this->archivePath($id))) {
                throw new RuntimeException('audit_archive_publish_failed');
            }
            $export->update([
                'status' => 'COMPLETED', 'progress_percent' => 100, 'row_counts' => $counts,
                'archive_bytes' => filesize($this->archivePath($id)),
                'archive_sha256' => hash_file('sha256', $this->archivePath($id)),
                'finished_at' => now('UTC'), 'expires_at' => now('UTC')->addHours((int) config('audit.retention_hours')),
            ]);
        } catch (Throwable $error) {
            if ($ownsTransaction && DB::transactionLevel() > 0) {
                try {
                    DB::rollBack();
                } catch (Throwable) {
                }
                $ownsTransaction = false;
            }
            // Retrying from the beginning is safe: all outputs are temporary until final rename.
            $this->failed($id, 'audit_generation_failed');
            throw $error;
        } finally {
            if ($ownsTransaction && DB::transactionLevel() > 0) {
                try {
                    DB::rollBack();
                } catch (Throwable) {
                }
            }
            try {
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement('RESET statement_timeout');
                    DB::statement('RESET lock_timeout');
                }
            } finally {
                $this->removeTemporary($workingDir);
                @unlink($this->archivePath($id).'.part');
                $lock->release();
            }
        }
    }

    /** @param array<string,string> $metadata */
    private function writeExportMetadata(string $file, array $metadata, array &$counts, array &$entries): void
    {
        $handle = fopen($file, 'wb');
        if ($handle === false) {
            throw new RuntimeException('audit_metadata_csv_unwritable');
        }
        try {
            fputcsv($handle, ['field', 'value'], ',', '"', '');
            foreach ($metadata as $field => $value) {
                fputcsv($handle, [$field, $this->cell($value)], ',', '"', '');
            }
        } finally {
            fclose($handle);
        }
        $this->track($file, count($metadata), $counts, $entries);
    }

    /** @param array<string,array{rows:int,sha256:string,bytes:int}> $entries */
    private function writeExportManifest(string $file, array $entries, string $exportId, ?string $snapshotWatermark = null): void
    {
        ksort($entries);
        $handle = fopen($file, 'wb');
        if ($handle === false) {
            throw new RuntimeException('audit_manifest_csv_unwritable');
        }
        $sourceScope = $snapshotWatermark !== null ? 'REPEATABLE_READ_SNAPSHOT' : 'BOUNDED_BEST_EFFORT';
        try {
            fputcsv($handle, ['file_name', 'schema_version', 'row_count', 'sha256', 'bytes', 'export_id', 'source_scope', 'snapshot_watermark'], ',', '"', '');
            foreach ($entries as $name => $item) {
                fputcsv($handle, [
                    $name,
                    (string) config('audit.schema_version'),
                    $item['rows'],
                    $item['sha256'],
                    $item['bytes'],
                    $exportId,
                    $sourceScope,
                    $snapshotWatermark ?? '',
                ], ',', '"', '');
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param list<string> $payloadNames */
    private function writeExportChecksums(string $file, string $directory, array $payloadNames): void
    {
        $names = array_merge($payloadNames, ['manifest.csv']);
        sort($names, SORT_STRING);
        $handle = fopen($file, 'wb');
        if ($handle === false) {
            throw new RuntimeException('audit_checksum_csv_unwritable');
        }
        try {
            fputcsv($handle, ['file_name', 'sha256'], ',', '"', '');
            foreach ($names as $name) {
                if (! str_ends_with($name, '.csv') || basename($name) !== $name || $name === 'checksums.csv'
                    || ! is_file($directory.'/'.$name)) {
                    throw new RuntimeException('audit_checksum_payload_invalid');
                }
                fputcsv($handle, [$name, hash_file('sha256', $directory.'/'.$name)], ',', '"', '');
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string,string> $expectedHashes */
    private function verifyArchivePayloads(string $path, array $expectedHashes): void
    {
        $archive = new ZipArchive;
        if ($archive->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('audit_archive_verification_open_failed');
        }
        try {
            if ($archive->numFiles !== count($expectedHashes)) {
                throw new RuntimeException('audit_archive_payload_count_mismatch');
            }
            foreach ($expectedHashes as $name => $expected) {
                $stream = $archive->getStream($name);
                if ($stream === false) {
                    throw new RuntimeException('audit_archive_payload_missing');
                }
                $hash = hash_init('sha256');
                try {
                    while (! feof($stream)) {
                        $bytes = fread($stream, 65536);
                        if ($bytes === false) {
                            throw new RuntimeException('audit_archive_payload_read_failed');
                        }
                        hash_update($hash, $bytes);
                    }
                } finally {
                    fclose($stream);
                }
                if (! hash_equals($expected, hash_final($hash))) {
                    throw new RuntimeException('audit_archive_checksum_mismatch');
                }
            }
        } finally {
            $archive->close();
        }
    }

    private function writeDataset(AuditExportRecord $export, string $table, string $filename): int
    {
        $columns = array_values(array_intersect(self::DATASETS[$table], Schema::getColumnListing($table)));
        if ($columns === []) {
            throw new RuntimeException('audit_schema_missing_'.$table);
        }
        $handle = fopen($filename, 'wb');
        if ($handle === false) {
            throw new RuntimeException('audit_csv_unwritable');
        }
        $count = 0;
        try {
            fputcsv($handle, $columns, ',', '"', '');
            $query = DB::table($table);
            $time = self::TIMES[$table];
            if ($table === 'paper_accounts' || $table === 'research_patterns' || $table === 'research_pattern_runs') {
                // Include definitions and portfolio references originating before the
                // requested window; otherwise exported occurrences have orphaned IDs.
            } elseif ($table === 'market_candles') {
                // Provide enough preceding price history to independently recompute
                // 1h indicators for a one-day audit without future data.
                $query->where('is_closed', true)
                    ->where($time, '>=', $export->from_at->copy()->subDays(10))
                    ->where($time, '<', $export->to_at);
            } elseif ($table === 'paper_signal_states') {
                $query->where(function (Builder $q) use ($export): void {
                    $q->where('processed_at', '>=', $export->from_at)->where('processed_at', '<', $export->to_at)
                        ->orWhere(fn (Builder $created) => $created->where('created_at', '>=', $export->from_at)->where('created_at', '<', $export->to_at));
                });
            } elseif ($table === 'virtual_positions') {
                $query->where($time, '<', $export->to_at)
                    ->where(fn (Builder $q) => $q->whereNull('closed_at')->orWhere('closed_at', '>=', $export->from_at));
            } else {
                $query->where($time, '>=', $export->from_at)->where($time, '<', $export->to_at);
            }
            $key = $table === 'trading_journal_events' ? 'sequence' : 'id';
            $query->select($columns)->chunkById((int) config('audit.row_batch'), function ($rows) use ($handle, $columns, &$count): void {
                foreach ($rows as $row) {
                    fputcsv($handle, array_map(fn (string $column): string => $this->cell($row->{$column} ?? null), $columns), ',', '"', '');
                    $count++;
                }
                if (ftell($handle) > (int) config('audit.max_staging_bytes')) {
                    throw new RuntimeException('audit_staging_size_limit');
                }
            }, $key);
        } finally {
            fclose($handle);
        }

        return $count;
    }

    private function writeStrategies(string $file, array &$counts, array &$entries): void
    {
        $handle = fopen($file, 'wb');
        fputcsv($handle, ['strategy_id', 'strategy_version', 'configuration_hash', 'enabled', 'description', 'execution_mode'], ',', '"', '');
        $count = 0;
        $enabled = (array) config('research.forward_paper.enabled_strategies', []);
        foreach (app(StrategyRegistry::class)->metadata() as $item) {
            fputcsv($handle, [
                $item['id'], $item['version'], $item['configuration_hash'],
                $item['id'] === 'baseline' || in_array($item['id'], $enabled, true) ? 'true' : 'false',
                $this->cell($item['description']),
                in_array($item['id'], (array) config('research.forward_paper.signal_only_strategies', []), true) ? 'SIGNAL_ONLY' : 'PAPER_EXECUTION',
            ], ',', '"', '');
            $count++;
        }
        fclose($handle);
        $this->track($file, $count, $counts, $entries);
    }

    private function writeFunnel(AuditExportRecord $export, string $file, array &$counts, array &$entries): void
    {
        $handle = fopen($file, 'wb');
        fputcsv($handle, ['paper_account_id', 'exchange_id', 'strategy_id', 'strategy_version', 'decision_signals', 'buy_decisions', 'sell_decisions', 'skip_decisions', 'eligible_paper_signals', 'execution_mode', 'risk_approved', 'risk_rejected', 'opened_positions', 'closed_positions'], ',', '"', '');
        $count = 0;
        foreach (DB::table('paper_accounts')->where('is_primary', true)->select('id', 'exchange_id', 'strategy_id', 'strategy_version', 'configuration_hash')->cursor() as $account) {
            $window = fn ($q, string $column) => $q->where($column, '>=', $export->from_at)->where($column, '<', $export->to_at);
            $signals = DB::table('paper_signal_states')->join('signals', 'signals.id', '=', 'paper_signal_states.signal_id')
                ->where('paper_signal_states.paper_account_id', $account->id);
            $decisions = DB::table('strategy_observations')->where('exchange', $account->exchange_id)->where('strategy_id', $account->strategy_id)
                ->where('strategy_version', $account->strategy_version);
            $hash = $account->configuration_hash;
            if ($hash === null) {
                $meta = collect(app(StrategyRegistry::class)->metadata())->firstWhere('id', $account->strategy_id);
                $hash = ($meta['version'] ?? null) === $account->strategy_version ? ($meta['configuration_hash'] ?? null) : null;
            }
            $decisions->where('configuration_hash', $hash);
            $decisions = $window($decisions, 'decision_time');
            $decisionCounts = (clone $decisions)->selectRaw('side, count(*) as n')->groupBy('side')->pluck('n', 'side');
            $buy = (int) ($decisionCounts['BUY'] ?? 0);
            $sell = (int) ($decisionCounts['SELL'] ?? 0);
            $skip = (int) ($decisionCounts['SKIP'] ?? 0);
            $totalDecisions = $buy + $sell + $skip;

            $signalOnly = in_array($account->strategy_id, (array) config('research.forward_paper.signal_only_strategies', []), true);
            $risks = DB::table('risk_decisions')->where('paper_account_id', $account->id);
            $riskCounts = $window(clone $risks, 'decided_at')
                ->selectRaw('count(*) filter (where allowed = true) as approved, count(*) filter (where allowed = false) as rejected')
                ->first();

            $posCounts = DB::table('virtual_positions')->where('paper_account_id', $account->id)
                ->selectRaw('count(*) filter (where opened_at >= ? and opened_at < ?) as opened, count(*) filter (where closed_at >= ? and closed_at < ?) as closed', [$export->from_at, $export->to_at, $export->from_at, $export->to_at])
                ->first();

            fputcsv($handle, [
                $account->id, $account->exchange_id, $account->strategy_id, $account->strategy_version,
                $totalDecisions,
                $buy,
                $sell,
                $skip,
                $window(clone $signals, 'signals.decision_time')->count(),
                $signalOnly ? 'SIGNAL_ONLY' : 'PAPER_EXECUTION',
                (int) ($riskCounts->approved ?? 0),
                (int) ($riskCounts->rejected ?? 0),
                (int) ($posCounts->opened ?? 0),
                (int) ($posCounts->closed ?? 0),
            ], ',', '"', '');
            $count++;
        }
        fclose($handle);
        $this->track($file, $count, $counts, $entries);
    }

    /**
     * Export one explicit row for each registered primary strategy, even if no
     * primary paper account currently exists. This is an account-coverage
     * snapshot, NOT trading performance or an assertion of 13 live accounts.
     */
    private function writePrimaryCoverage(string $file, array &$counts, array &$entries): void
    {
        $handle = fopen($file, 'wb');
        fputcsv($handle, [
            'exchange_id', 'strategy_id', 'strategy_version', 'configuration_hash', 'execution_mode',
            'primary_account_count', 'matching_version_count', 'coverage_status',
        ], ',', '"', '');
        $count = 0;
        $primary = PrimaryStrategies::ids();
        $metadata = collect(app(StrategyRegistry::class)->metadata())->keyBy('id');
        foreach ((array) config('exchanges.enabled', ['bybit', 'binance']) as $exchangeId) {
            foreach ($primary as $strategyId) {
                $strategy = $metadata->get($strategyId);
                $accounts = DB::table('paper_accounts')
                    ->where('is_primary', true)
                    ->where('primary_strategy_id', $strategyId)
                    ->where('exchange_id', $exchangeId)
                    ->get(['strategy_version', 'configuration_hash']);
                $matches = $strategy === null ? collect() : $accounts->filter(fn ($account): bool => $account->strategy_version === $strategy['version']
                    && $account->configuration_hash !== null
                    && $account->configuration_hash === $strategy['configuration_hash']);
                $status = match (true) {
                    $strategy === null => 'STRATEGY_NOT_REGISTERED',
                    $accounts->isEmpty() => 'MISSING_PRIMARY_ACCOUNT',
                    $accounts->count() !== 1 => 'DUPLICATE_PRIMARY_ACCOUNT',
                    $matches->count() !== 1 => 'VERSION_OR_CONFIG_MISMATCH',
                    default => 'MATCHED',
                };
                fputcsv($handle, [
                    $exchangeId, $strategyId, $strategy['version'] ?? '', $strategy['configuration_hash'] ?? '',
                    $strategy === null ? 'UNKNOWN'
                        : ($strategy['execution_eligible']
                            ? 'PAPER_EXECUTION' : 'SIGNAL_ONLY'),
                    $accounts->count(), $matches->count(), $status,
                ], ',', '"', '');
                $count++;
            }
        }
        fclose($handle);
        $this->track($file, $count, $counts, $entries);
    }

    private function writeDailySnapshots(AuditExportRecord $export, string $file, array &$counts, array &$entries): void
    {
        $handle = fopen($file, 'wb');
        $columns = ['paper_account_id', 'date', 'timezone', 'net_pnl', 'realized_pnl', 'unrealized_pnl_change', 'commissions', 'funding', 'closing_equity', 'opening_boundary', 'opening_snapshot_at', 'closing_snapshot_at', 'complete', 'incomplete_reasons'];
        fputcsv($handle, $columns, ',', '"', '');
        $count = 0;
        foreach (DB::table('paper_accounts')->orderBy('id')->cursor(['id']) as $account) {
            $days = app(DailyPerformanceService::class)->calendarDays(
                $account->id, 'UTC', CarbonImmutable::parse($export->from_at), CarbonImmutable::parse($export->to_at)
            );
            foreach ($days as $day) {
                $day['paper_account_id'] = $account->id;
                $values = array_map(static fn (string $column): mixed => is_array($day[$column] ?? null) ? json_encode($day[$column], JSON_THROW_ON_ERROR) : ($day[$column] ?? null), $columns);
                fputcsv($handle, $values, ',', '"', '');
                $count++;
            }
        }
        fclose($handle);
        $this->track($file, $count, $counts, $entries);
    }

    private function writeQuality(string $file, array &$counts, array &$entries, ?string $snapshotWatermark = null): void
    {
        $handle = fopen($file, 'wb');
        fputcsv($handle, ['paper_account_id', 'check', 'value', 'meaning'], ',', '"', '');
        $count = 0;
        $record = static function ($handle, array $fields) use (&$count): void {
            fputcsv($handle, $fields, ',', '"', '');
            $count++;
        };
        $snapshotValue = $snapshotWatermark !== null ? 'REPEATABLE_READ_SNAPSHOT' : 'BOUNDED_BEST_EFFORT';
        $snapshotMeaning = $snapshotWatermark !== null
            ? 'Point-in-time repeatable-read transaction snapshot watermark: '.$snapshotWatermark
            : 'Not a repeatable-read transaction';
        $record($handle, ['', 'source_snapshot', $snapshotValue, $snapshotMeaning]);
        foreach (DB::table('paper_accounts')->select(['id', 'starting_balance', 'current_equity', 'realized_pnl', 'unrealized_pnl'])->cursor() as $account) {
            $snapshots = EquitySnapshotRecord::query()->where('paper_account_id', $account->id)
                ->openingCapital()->exists();
            if (! $snapshots) {
                $record($handle, [$account->id, 'initial_equity_snapshot', 'MISSING', 'Drawdown baseline may be incomplete']);
            }
            $expected = BigDecimal::of($account->starting_balance)->plus($account->realized_pnl)->plus($account->unrealized_pnl);
            if (! $expected->isEqualTo(BigDecimal::of($account->current_equity))) {
                $record($handle, [$account->id, 'equity_reconciliation', 'MISMATCH', 'Starting balance + realized + unrealized differs from current equity']);
            }
        }
        fclose($handle);
        $this->track($file, $count, $counts, $entries);
    }

    private function track(string $file, int $count, array &$counts, array &$entries): void
    {
        $name = basename($file);
        $counts[substr($name, 0, -4)] = $count;
        $entries[$name] = ['rows' => $count, 'sha256' => hash_file('sha256', $file), 'bytes' => filesize($file)];
    }

    private function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }
        $text = is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR);

        // Prevent formulas when the report is opened in spreadsheet software.
        return ! is_numeric($text) && preg_match('/^[=+@\-\t\r]/u', $text) ? "'".$text : $text;
    }

    public function failed(string $id, string $code): void
    {
        AuditExportRecord::query()->whereKey($id)->where('status', '!=', 'COMPLETED')
            ->update(['status' => 'FAILED', 'error_code' => substr($code, 0, 64), 'finished_at' => now('UTC')]);
    }

    public function purgeExpired(): void
    {
        foreach (AuditExportRecord::query()->where('expires_at', '<', now('UTC'))->limit(100)->get() as $export) {
            @unlink($this->archivePath($export->id));
            $export->delete();
        }
        $total = (int) AuditExportRecord::query()->where('status', 'COMPLETED')->sum('archive_bytes');
        $quota = (int) config('audit.quota_bytes');
        if ($total > $quota) {
            foreach (AuditExportRecord::query()->where('status', 'COMPLETED')->oldest('finished_at')->limit(100)->get() as $old) {
                @unlink($this->archivePath($old->id));
                $old->delete();
                $total -= (int) $old->archive_bytes;
                if ($total <= $quota) {
                    break;
                }
            }
        }
    }

    private function removeTemporary(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob($dir.'/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }
}
