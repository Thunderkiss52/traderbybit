<?php

declare(strict_types=1);

namespace App\Audit\Application;

use App\Audit\Infrastructure\Persistence\AuditExportRecord;
use App\Audit\Jobs\GenerateAuditExportJob;
use App\Strategy\Application\StrategyRegistry;
use Brick\Math\BigDecimal;
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
        'paper_accounts' => ['id', 'name', 'mode', 'status', 'strategy_id', 'strategy_version', 'configuration_hash', 'starting_balance', 'current_equity', 'peak_equity', 'realized_pnl', 'unrealized_pnl', 'reserved_risk', 'current_drawdown_percent', 'maximum_drawdown_percent', 'created_at', 'updated_at'],
        'strategy_observations' => ['id', 'exchange', 'category', 'symbol', 'strategy_id', 'strategy_version', 'configuration_hash', 'side', 'score', 'quality_class', 'decision_time', 'reasons', 'analysis', 'created_at'],
        'signals' => ['id', 'strategy_id', 'strategy_version', 'exchange', 'category', 'symbol', 'timeframe', 'side', 'quality_class', 'score', 'decision_time', 'candle_close_time', 'expires_at', 'entry_low', 'entry_high', 'stop_loss', 'take_profit_1', 'take_profit_2', 'risk_reward_1', 'risk_reward_2', 'model_risk_percent', 'maximum_holding_minutes', 'reasons', 'risks', 'indicator_snapshot', 'contract', 'created_at'],
        'risk_decisions' => ['id', 'paper_account_id', 'signal_id', 'allowed', 'reasons', 'snapshot', 'risk_version', 'decided_at', 'created_at'],
        'paper_signal_states' => ['id', 'paper_account_id', 'signal_id', 'status', 'reason', 'processed_at', 'created_at'],
        'virtual_positions' => ['id', 'paper_account_id', 'signal_id', 'symbol', 'side', 'status', 'entry_price', 'stop_loss', 'take_profit_1', 'take_profit_2', 'initial_quantity', 'remaining_quantity', 'risk_capital', 'gross_pnl', 'net_pnl', 'unrealized_pnl', 'fees', 'funding', 'r_multiple', 'opened_at', 'closed_at', 'maximum_holding_until', 'funding_complete', 'ambiguous_execution', 'awaiting_market_data', 'strategy_version'],
        'virtual_executions' => ['id', 'position_id', 'type', 'side', 'quantity', 'price', 'gross_pnl', 'commission', 'slippage', 'market_event_at', 'registered_at', 'price_source', 'ambiguous_execution', 'idempotency_key'],
        'trading_journal_events' => ['sequence', 'id', 'paper_account_id', 'signal_id', 'position_id', 'event_type', 'occurred_at', 'idempotency_key'],
        'equity_snapshots' => ['id', 'paper_account_id', 'recorded_at', 'equity', 'realized_pnl', 'unrealized_pnl', 'drawdown_percent', 'source_event_key'],
        'position_funding_events' => ['id', 'position_id', 'market_funding_event_id', 'quantity', 'mark_price', 'rate', 'amount', 'funding_at'],
        'level_interaction_forecasts' => ['id', 'exchange', 'category', 'symbol', 'timeframe', 'event', 'side', 'triggered_at', 'horizon_ends_at', 'model_version', 'configuration_hash', 'sample_size', 'success_probability', 'failure_probability', 'unresolved_probability', 'confidence_low', 'confidence_high', 'validation_status', 'outcome', 'outcome_at', 'brier_score', 'features'],
        'candlestick_pattern_occurrences' => ['id', 'exchange', 'category', 'symbol', 'timeframe', 'pattern_name', 'direction', 'pattern_type', 'version', 'formation_time', 'detected_at', 'bar_count', 'evidence'],
        'market_context_snapshots' => ['id', 'exchange', 'category', 'symbol', 'observed_at', 'bid', 'ask', 'turnover_24h', 'tick_size', 'funding_rate', 'open_interest', 'open_interest_change'],
        'market_candles' => ['id', 'exchange', 'category', 'symbol', 'interval', 'open_time', 'open', 'high', 'low', 'close', 'volume', 'turnover', 'is_closed'],
        'market_funding_events' => ['id', 'exchange', 'category', 'symbol', 'funding_at', 'rate', 'mark_price'],
        'market_open_interest_events' => ['id', 'exchange', 'category', 'symbol', 'interval', 'observed_at', 'open_interest'],
        'research_pattern_runs' => ['id', 'status', 'from_at', 'to_at', 'symbol', 'configuration_hash', 'parameters', 'data_quality', 'examined_decisions', 'labeled_observations', 'completed_at', 'created_at'],
        'research_patterns' => ['id', 'pattern_run_id', 'direction', 'status', 'terms', 'metrics', 'rule_hash', 'version', 'created_at'],
        'research_pattern_matches' => ['id', 'pattern_id', 'symbol', 'direction', 'matched_at', 'feature_snapshot', 'status', 'outcome', 'net_r', 'outcome_at'],
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
    ];

    public function request(int $days): AuditExportRecord
    {
        if (! in_array($days, [1, 7, 14, 30], true)) {
            throw new \InvalidArgumentException('Unsupported audit export period.');
        }
        $now = now('UTC');
        // Reusable short-lived fixed cutoff: no moving window on every click.
        $end = $now->copy()->startOfHour()->addMinutes(intdiv($now->minute, 15) * 15);
        $start = $end->copy()->subDays($days);
        $key = hash('sha256', implode('|', [config('audit.schema_version'), $days, $end->format('c'), 'research-paper-all']));
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
        try {
            $export = AuditExportRecord::query()->findOrFail($id);
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
                DB::statement("SET statement_timeout TO '3000ms'");
                DB::statement("SET lock_timeout TO '1000ms'");
            }
            $counts = [];
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
            $this->writeDailySnapshots($export, $workingDir.'/daily_performance.csv', $counts, $entries);
            $this->writeQuality($workingDir.'/data_quality.csv', $counts, $entries);
            $manifest = [
                'schema_version' => config('audit.schema_version'), 'type' => 'FORWARD_PAPER_RESEARCH_AUDIT',
                'period_days' => $export->period_days,
                'window' => ['from_inclusive_utc' => $export->from_at->toIso8601String(), 'to_exclusive_utc' => $export->to_at->toIso8601String()],
                'requested_at_utc' => $export->as_of->toIso8601String(),
                'generated_at_utc' => now('UTC')->toIso8601String(),
                'source_consistency' => 'BOUNDED_BEST_EFFORT_NOT_REPEATABLE_READ',
                'mutable_position_snapshots_may_change_after_cutoff' => true,
                'research_mode_only' => true,
                'market_candle_warmup_days_before_window' => 10,
                'files' => $entries,
                'excluded' => ['credentials', 'personal_data', 'raw_journal_payload', 'real_exchange_orders', 'historical_backtests'],
            ];
            file_put_contents($workingDir.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            file_put_contents($workingDir.'/summary.json', json_encode(['row_counts' => $counts, 'note' => 'Decision counts are not fills; data covers distinct isolated portfolios.'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            file_put_contents($workingDir.'/README.txt', "VECTOR Quant Research audit\nUTC boundaries: [from, to). No order execution occurs.\nMonetary amounts are decimal strings; drawdown values are percentage points.\nStrategy decisions are not executed fills. Closed-trade PnL differs from equity when positions are open.\nCSV values beginning with spreadsheet formula markers are escaped.\nThis package is a bounded best-effort extract, not a transactionally frozen snapshot.\nNo backtest data is mixed with forward-paper data.\n");
            $part = $this->archivePath($id).'.part';
            $zip = new ZipArchive;
            if ($zip->open($part, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('audit_zip_open_failed');
            }
            foreach (scandir($workingDir) ?: [] as $filename) {
                if ($filename === '.' || $filename === '..') {
                    continue;
                }
                $zip->addFile($workingDir.'/'.$filename, $filename);
                $zip->setCompressionName($filename, ZipArchive::CM_DEFLATE, 1);
            }
            if (! $zip->close()) {
                throw new RuntimeException('audit_zip_close_failed');
            }
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
            // Retrying from the beginning is safe: all outputs are temporary until final rename.
            $this->failed($id, 'audit_generation_failed');
            throw $error;
        } finally {
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
                    $q->whereBetween('processed_at', [$export->from_at, $export->to_at])
                        ->orWhereBetween('created_at', [$export->from_at, $export->to_at]);
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
        fputcsv($handle, ['strategy_id', 'strategy_version', 'configuration_hash', 'enabled', 'description'], ',', '"', '');
        $count = 0;
        $enabled = (array) config('research.forward_paper.enabled_strategies', []);
        foreach (app(StrategyRegistry::class)->metadata() as $item) {
            fputcsv($handle, [
                $item['id'], $item['version'], $item['configuration_hash'],
                $item['id'] === 'baseline' || in_array($item['id'], $enabled, true) ? 'true' : 'false',
                $this->cell($item['description']),
            ], ',', '"', '');
            $count++;
        }
        fclose($handle);
        $this->track($file, $count, $counts, $entries);
    }

    private function writeFunnel(AuditExportRecord $export, string $file, array &$counts, array &$entries): void
    {
        $handle = fopen($file, 'wb');
        fputcsv($handle, ['paper_account_id', 'strategy_id', 'strategy_version', 'decision_signals', 'risk_approved', 'risk_rejected', 'opened_positions', 'closed_positions'], ',', '"', '');
        $count = 0;
        foreach (DB::table('paper_accounts')->select('id', 'strategy_id', 'strategy_version')->cursor() as $account) {
            $window = fn ($q, string $column) => $q->where($column, '>=', $export->from_at)->where($column, '<', $export->to_at);
            $signals = DB::table('paper_signal_states')->join('signals', 'signals.id', '=', 'paper_signal_states.signal_id')
                ->where('paper_signal_states.paper_account_id', $account->id);
            $risks = DB::table('risk_decisions')->where('paper_account_id', $account->id);
            $positions = DB::table('virtual_positions')->where('paper_account_id', $account->id);
            fputcsv($handle, [
                $account->id, $account->strategy_id, $account->strategy_version,
                $window(clone $signals, 'signals.decision_time')->count(),
                $window(clone $risks, 'decided_at')->where('allowed', true)->count(),
                $window(clone $risks, 'decided_at')->where('allowed', false)->count(),
                $window(clone $positions, 'opened_at')->count(),
                $window(clone $positions, 'closed_at')->count(),
            ], ',', '"', '');
            $count++;
        }
        fclose($handle);
        $this->track($file, $count, $counts, $entries);
    }

    private function writeDailySnapshots(AuditExportRecord $export, string $file, array &$counts, array &$entries): void
    {
        $handle = fopen($file, 'wb');
        fputcsv($handle, ['paper_account_id', 'date_utc', 'first_snapshot_at', 'first_equity', 'last_snapshot_at', 'last_equity', 'snapshot_count'], ',', '"', '');
        $count = 0;
        $current = null;
        $first = $last = null;
        $snapshots = 0;
        $emit = static function () use (&$current, &$first, &$last, &$snapshots, &$count, $handle): void {
            if ($current === null) {
                return;
            }
            fputcsv($handle, [$current[0], $current[1], $first->recorded_at, $first->equity, $last->recorded_at, $last->equity, $snapshots], ',', '"', '');
            $count++;
        };
        foreach (DB::table('equity_snapshots')->where('recorded_at', '>=', $export->from_at)->where('recorded_at', '<', $export->to_at)
            ->orderBy('paper_account_id')->orderBy('recorded_at')->orderBy('id')->cursor() as $snapshot) {
            $date = substr((string) $snapshot->recorded_at, 0, 10);
            $key = [$snapshot->paper_account_id, $date];
            if ($current !== $key) {
                $emit();
                $current = $key;
                $first = $snapshot;
                $snapshots = 0;
            }
            $last = $snapshot;
            $snapshots++;
        }
        $emit();
        fclose($handle);
        $this->track($file, $count, $counts, $entries);
    }

    private function writeQuality(string $file, array &$counts, array &$entries): void
    {
        $handle = fopen($file, 'wb');
        fputcsv($handle, ['paper_account_id', 'check', 'value', 'meaning'], ',', '"', '');
        $count = 0;
        $record = static function ($handle, array $fields) use (&$count): void {
            fputcsv($handle, $fields, ',', '"', '');
            $count++;
        };
        $record($handle, ['', 'source_snapshot', 'BOUNDED_BEST_EFFORT', 'Not a repeatable-read transaction']);
        foreach (DB::table('paper_accounts')->select(['id', 'starting_balance', 'current_equity', 'realized_pnl', 'unrealized_pnl'])->cursor() as $account) {
            $snapshots = DB::table('equity_snapshots')->where('paper_account_id', $account->id)->where('source_event_key', 'account:initial')->exists();
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
