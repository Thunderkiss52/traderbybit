<?php

declare(strict_types=1);

namespace App\Audit\Backtest;

use App\Backtest\Application\DatasetInspector;
use App\Backtest\Infrastructure\Persistence\BacktestRunRecord;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Historical SIMULATION audit. Exports the stored backtest and current matching
 * market data. It is not a transactionally repeatable snapshot of the input data.
 * Never reads/changes virtual PAPER accounts or places real/demo orders.
 */
final class BacktestAuditArchiveBuilder
{
    private const MAX_ROWS_PER_CSV = 2_000_000;

    /** @return array{sha256: string, bytes: int, row_counts: array<string, int>} */
    public function build(BacktestRunRecord $run, string $target): array
    {
        if ($run->status !== 'COMPLETED' || ! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Backtest incomplete or ZIP support unavailable.');
        }
        $exchange = $run->strategy_config['_exchange'] ?? 'bybit';
        $symbols = $run->symbols;
        if (! in_array($exchange, ['bybit', 'binance'], true)
            || ! is_array($symbols) || $symbols === [] || count($symbols) > 32) {
            throw new RuntimeException('Invalid saved backtest exchange or symbol scope.');
        }
        foreach ($symbols as $symbol) {
            if (! is_string($symbol) || ! preg_match('/^[A-Z0-9_]{1,32}$/D', $symbol)) {
                throw new RuntimeException('Invalid saved symbol scope.');
            }
        }
        $from = $run->from_at->toDateTimeImmutable();
        $to = $run->to_at->toDateTimeImmutable();
        if ($from >= $to) {
            throw new RuntimeException('Invalid saved backtest time window.');
        }
        $directory = dirname($target);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create private audit folder.');
        }
        $staging = $directory.'/stage-'.$run->id.'-'.bin2hex(random_bytes(8));
        if (! mkdir($staging, 0700, true)) {
            throw new RuntimeException('Unable to create private audit staging.');
        }
        $zipTmp = $staging.'/archive.zip';
        $zip = new ZipArchive;
        $files = [];
        $totalBytes = 0;
        $limit = (int) config('audit.max_staging_bytes', 500 * 1024 * 1024);
        $zipLimit = (int) config('audit.max_archive_bytes', 250 * 1024 * 1024);

        try {
            if ($zip->open($zipTmp, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new RuntimeException('Unable to open audit archive.');
            }
            $tables = [
                'segments.csv' => ['backtest_segments', ['id', 'window_index', 'kind', 'from_at', 'to_at', 'parameters', 'metrics', 'warnings', 'decisions', 'status']],
                'trades.csv' => ['backtest_trades', ['id', 'backtest_segment_id', 'identity_key', 'symbol', 'side', 'quality_class', 'strategy_version', 'signal_at', 'opened_at', 'closed_at', 'entry_price', 'exit_price', 'quantity', 'gross_pnl', 'fees', 'funding', 'net_pnl', 'r_multiple', 'holding_seconds', 'close_reason', 'ambiguous_execution', 'funding_complete', 'executions']],
                'equity.csv' => ['backtest_equity_snapshots', ['id', 'backtest_segment_id', 'recorded_at', 'equity', 'realized_pnl', 'unrealized_pnl', 'drawdown_percent', 'exposed']],
                'optimizations.csv' => ['backtest_optimizations', ['id', 'window_index', 'combination_index', 'parameters', 'train_metrics', 'validation_metrics', 'objective_score', 'selected']],
                'reports.csv' => ['backtest_reports', ['id', 'kind', 'payload']],
            ];
            foreach ($tables as $name => [$table, $columns]) {
                $query = DB::table($table)->where('backtest_run_id', $run->id)->orderBy('id');
                $this->appendCsv($zip, $staging, $name, $columns, $query, $files, $totalBytes, $limit);
            }
            $warmup = (int) config('backtest.warmup_bars', 220);
            if ($warmup < 0 || $warmup > 10000) {
                throw new RuntimeException('Invalid audit warmup configuration.');
            }
            $candles = ['exchange', 'category', 'symbol', 'interval', 'open_time', 'open', 'high', 'low', 'close', 'volume', 'turnover', 'is_closed'];
            $candleSeriesCounts = [];
            $candleRows = function () use ($symbols, $exchange, $from, $to, $warmup, &$candleSeriesCounts): \Generator {
                foreach ($symbols as $symbol) {
                    // Match StrategyInputFactory and DatasetInspector hierarchy (5m, 15m, 30m, 60m)
                    foreach (['5' => 300, '15' => 900, '30' => 1800, '60' => 3600] as $interval => $seconds) {
                        $firstExpectedOpen = (new DateTimeImmutable('@'.(intdiv($from->getTimestamp(), $seconds) * $seconds)))->setTimezone(new DateTimeZone('UTC'));
                        $lastExpectedOpen = (new DateTimeImmutable('@'.(intdiv($to->getTimestamp() - $seconds, $seconds) * $seconds)))->setTimezone(new DateTimeZone('UTC'));
                        $start = $firstExpectedOpen->modify('-'.(max($warmup, 1) * $seconds).' seconds');
                        $seriesKey = $symbol.'|'.$interval;
                        $candleSeriesCounts[$seriesKey] = 0;
                        foreach (DB::table('market_candles')->select(['exchange', 'category', 'symbol', 'interval', 'open_time', 'open', 'high', 'low', 'close', 'volume', 'turnover', 'is_closed'])
                            ->where(['exchange' => $exchange, 'category' => 'linear', 'symbol' => $symbol, 'interval' => $interval, 'is_closed' => true])
                            ->where('open_time', '>=', $start->format('Y-m-d H:i:s.uP'))
                            ->where('open_time', '<=', $lastExpectedOpen->format('Y-m-d H:i:s.uP'))
                            ->orderBy('open_time')->cursor() as $candle) {
                            $candleSeriesCounts[$seriesKey]++;
                            yield $candle;
                        }
                    }
                }
            };
            $this->appendCsv($zip, $staging, 'candles.csv', $candles, $candleRows(), $files, $totalBytes, $limit);

            $contextScope = ['exchange' => $exchange, 'category' => 'linear'];
            $contextRows = function () use ($symbols, $contextScope, $from, $to): \Generator {
                foreach ($symbols as $symbol) {
                    $scope = array_merge($contextScope, ['symbol' => $symbol]);
                    $prior = DB::table('market_context_snapshots')->where($scope)
                        ->where('observed_at', '<', $from->format('Y-m-d H:i:s.uP'))
                        ->orderByDesc('observed_at')->orderByDesc('id')->first();
                    if ($prior !== null) {
                        yield $prior;
                    }
                    foreach (DB::table('market_context_snapshots')->where($scope)
                        ->where('observed_at', '>=', $from->format('Y-m-d H:i:s.uP'))
                        ->where('observed_at', '<=', $to->format('Y-m-d H:i:s.uP'))
                        ->orderBy('observed_at')->orderBy('id')->cursor() as $row) {
                        yield $row;
                    }
                }
            };
            $this->appendCsv($zip, $staging, 'market_context.csv',
                ['exchange', 'category', 'symbol', 'observed_at', 'bid', 'ask', 'turnover_24h', 'tick_size', 'funding_rate', 'open_interest', 'open_interest_change', 'qty_step', 'min_order_qty', 'max_order_qty', 'min_notional_value'],
                $contextRows(),
                $files, $totalBytes, $limit);

            $this->appendCsv($zip, $staging, 'funding.csv',
                ['exchange', 'category', 'symbol', 'funding_at', 'rate', 'mark_price'],
                DB::table('market_funding_events')->where(['exchange' => $exchange, 'category' => 'linear'])
                    ->whereIn('symbol', $symbols)
                    ->where('funding_at', '>=', $from->format('Y-m-d H:i:s.uP'))
                    ->where('funding_at', '<=', $to->format('Y-m-d H:i:s.uP'))
                    ->orderBy('funding_at')->orderBy('id'),
                $files, $totalBytes, $limit);

            $currentReverified = false;
            if ($run->dataset_checksum !== null && $from->getTimestamp() % 300 === 0 && $to->getTimestamp() % 300 === 0) {
                try {
                    $inspector = app(DatasetInspector::class);
                    $inspected = $inspector->inspect($symbols, $from, $to, $warmup, $exchange);
                    $currentReverified = ($inspected['checksum'] === $run->dataset_checksum);
                } catch (Throwable) {
                    $currentReverified = false;
                }
            }

            $manifest = [
                'schema_version' => 'VECTOR_BACKTEST_AUDIT_R8_1',
                'source' => 'HISTORICAL_SIMULATION_NOT_EXCHANGE_EXECUTION',
                'consistency' => 'CURRENT_DB_BOUNDED_EXPORT_NOT_REPEATABLE_READ',
                'dataset_checksum_at_run' => $run->dataset_checksum,
                'current_candle_series_rows' => $candleSeriesCounts,
                'current_dataset_reverified' => $currentReverified,
                'run' => [
                    'id' => $run->id, 'fingerprint' => $run->fingerprint,
                    'exchange' => $exchange, 'symbols' => $symbols,
                    'from_at' => $from->format(DATE_ATOM), 'to_at_exclusive' => $to->format(DATE_ATOM),
                    'strategy_version' => $run->strategy_version, 'risk_version' => $run->risk_version,
                    'execution_version' => $run->execution_version, 'backtest_version' => $run->backtest_version,
                    'seed' => $run->seed, 'type' => $run->type, 'status' => $run->status,
                    'strategy_config' => BacktestAuditRedactor::sanitize($run->strategy_config),
                    'risk_config' => BacktestAuditRedactor::sanitize($run->risk_config),
                    'execution_config' => BacktestAuditRedactor::sanitize($run->execution_config), 'metrics' => $run->metrics,
                    'warnings' => $run->warnings, 'errors' => $run->errors,
                ],
                'csv' => $files,
            ];
            if (! $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
                throw new RuntimeException('Cannot add audit manifest.');
            }
            if (! $zip->close()) {
                throw new RuntimeException('Cannot finalize audit ZIP.');
            }
            if (filesize($zipTmp) > $zipLimit) {
                throw new RuntimeException('Audit ZIP exceeds bounded size limit.');
            }
            if (! rename($zipTmp, $target)) {
                throw new RuntimeException('Atomic audit publishing failed.');
            }

            return [
                'sha256' => hash_file('sha256', $target),
                'bytes' => filesize($target),
                'row_counts' => array_map(static fn (array $f): int => $f['rows'], $files),
            ];
        } finally {
            // Staging contains only archive-build artifacts for this run.
            foreach (glob($staging.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($staging);
        }
    }

    /** @param Builder|iterable<object> $source */
    private function appendCsv(ZipArchive $zip, string $staging, string $name, array $columns, Builder|iterable $source, array &$files, int &$totalBytes, int $limit): void
    {
        $path = $staging.'/'.$name;
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Cannot open audit CSV.');
        }
        $rows = 0;
        try {
            fputcsv($handle, $columns);
            $generator = $source instanceof Builder ? $source->cursor($columns) : $source;
            foreach ($generator as $row) {
                $values = [];
                foreach ($columns as $column) {
                    $values[] = BacktestAuditCsv::cell($row->{$column} ?? null);
                }
                fputcsv($handle, $values);
                if (++$rows > self::MAX_ROWS_PER_CSV || ftell($handle) + $totalBytes > $limit) {
                    throw new RuntimeException('Backtest audit exceeds bounded staging limit.');
                }
            }
        } finally {
            fclose($handle);
        }
        $bytes = filesize($path);
        $totalBytes += $bytes;
        if ($totalBytes > $limit) {
            throw new RuntimeException('Backtest audit exceeds bounded staging limit.');
        }
        $files[$name] = ['rows' => $rows, 'bytes' => $bytes, 'sha256' => hash_file('sha256', $path)];
        if (! $zip->addFile($path, $name)) {
            throw new RuntimeException('Cannot add audit CSV to ZIP.');
        }
    }
}
