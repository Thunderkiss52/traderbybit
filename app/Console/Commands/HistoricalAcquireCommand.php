<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\HistoricalData\AcquisitionDataset;
use App\HistoricalData\AcquisitionWorker;
use App\HistoricalData\BatchControl;
use App\HistoricalData\BatchRepository;
use App\HistoricalData\CoverageValidator;
use App\HistoricalData\DownloadPlanner;
use App\HistoricalData\ReadModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class HistoricalAcquireCommand extends Command
{
    protected $signature = 'historical:acquire
        {--preset=binance-2024-2025 : The preset dataset to acquire}
        {--dry-run : Only plan and display estimates without downloading}
        {--limit= : Optional maximum entries to download}
        {--reference=OPERATOR-ACQUIRE-BINANCE-2024-2025 : Operator reference}';

    protected $description = 'Operator-triggered acquisition, SHA-256 verification, and pinning of historical datasets';

    public function handle(
        DownloadPlanner $planner,
        BatchRepository $batches,
        BatchControl $control,
        AcquisitionWorker $worker,
        CoverageValidator $coverage,
        AcquisitionDataset $pins,
        ReadModel $view
    ): int {
        $presetName = (string) $this->option('preset');
        $presets = [
            'binance-2024-2025' => [
                'provider' => 'BINANCE_VISION',
                'market' => 'futures/um',
                'symbols' => ['BTCUSDT', 'ETHUSDT'],
                'intervals' => ['1m', '5m', '15m', '30m', '1h'],
                'from' => '2024-01-01T00:00:00Z',
                'to' => '2025-12-31T23:59:59.999Z',
                'funding' => true,
                'intended_use' => 'NONCOMMERCIAL_RESEARCH',
            ],
        ];

        if (! isset($presets[$presetName])) {
            $this->error("Unknown preset '{$presetName}'. Available presets: ".implode(', ', array_keys($presets)));

            return self::FAILURE;
        }

        $preset = $presets[$presetName];
        $this->info("Planning historical acquisition for preset '{$presetName}'...");

        $plan = $planner->plan($preset);
        $estimate = $plan['estimate'];

        $this->table(
            ['Metric', 'Value'],
            [
                ['Provider', $plan['provider']],
                ['Market', $plan['market']],
                ['Symbols', implode(', ', $preset['symbols'])],
                ['Timeframes', implode(', ', $preset['intervals'])],
                ['Date Range', "{$preset['from']} to {$preset['to']}"],
                ['UTC Days', (string) $estimate['utc_days']],
                ['Candle Archives (ZIP)', (string) $estimate['candle_archives']],
                ['Total Archives/Windows', (string) count($plan['entries'])],
                ['Expected Candles', number_format((float) $estimate['expected_candles'])],
                ['License', $plan['license']['license'].' ('.$plan['license']['attribution'].')'],
                ['Intended Use', $preset['intended_use']],
            ]
        );

        if ((bool) $this->option('dry-run')) {
            $this->info('Dry-run complete. No downloads or database records created.');

            return self::SUCCESS;
        }

        $key = 'preset:'.$presetName.':v1';
        $batch = $batches->create($preset, $key);
        $this->info("Batch initialized: {$batch->id} (status: {$batch->status})");

        $reference = (string) $this->option('reference');
        $termsVersion = (string) config('historical_data.license.version', 'VISION-1.0-2026-08-26');

        if ($batch->status === 'PLANNED') {
            $batch = $control->change($batch->id, 'start', $reference, $termsVersion);
            $this->info("Batch activated: {$batch->id} (status: {$batch->status})");
        }

        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : PHP_INT_MAX;
        $processed = 0;

        $this->info('Beginning acquisition worker loop (limit: '.($limit === PHP_INT_MAX ? 'ALL' : (string) $limit).')...');

        $bar = $this->output->createProgressBar(min($limit, count($plan['entries'])));
        $bar->start();

        while ($processed < $limit && $worker->once($batch->id)) {
            $processed++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Processed {$processed} acquisition entries.");

        // Check if batch is completed
        $currentBatch = DB::table('historical_download_batches')->find($batch->id);
        $entriesCount = DB::table('historical_download_entries')->where('batch_id', $batch->id)->count();
        $importedCount = DB::table('historical_download_entries')->where('batch_id', $batch->id)->where('status', 'IMPORTED')->count();
        $quarantinedCount = DB::table('historical_download_entries')->where('batch_id', $batch->id)->where('status', 'QUARANTINED')->count();
        $failedCount = DB::table('historical_download_entries')->where('batch_id', $batch->id)->where('status', 'FAILED')->count();

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Entries', (string) $entriesCount],
                ['Imported', (string) $importedCount],
                ['Quarantined', (string) $quarantinedCount],
                ['Failed', (string) $failedCount],
                ['Batch Status', (string) $currentBatch->status],
            ]
        );

        if ($currentBatch->status === 'ACTIVE' && $limit !== PHP_INT_MAX && $processed > 0) {
            $currentBatch = $control->change($batch->id, 'pause', $reference, $termsVersion);
            $this->info("Batch paused after limit: {$batch->id} (status: {$currentBatch->status})");
        }

        if (in_array($currentBatch->status, ['PAUSED', 'IMPORTED', 'VALIDATED', 'ACCEPTED_WITH_LIMITATIONS'], true)) {
            $this->info('Validating coverage...');
            $validation = $coverage->validate($batch->id);
            $this->info('Coverage validation: '.($validation['status'] ?? 'COMPLETED'));

            if (($validation['quality']['research_ready'] ?? false) || in_array($currentBatch->status, ['VALIDATED', 'ACCEPTED_WITH_LIMITATIONS'], true)) {
                $this->info('Generating immutable dataset pins in database...');
                foreach ($preset['symbols'] as $symbol) {
                    try {
                        $scope = [
                            'exchange' => 'binance',
                            'symbols' => [$symbol],
                            'from' => $preset['from'],
                            'to' => '2024-01-31T23:59:59.999Z', // Monthly window
                            'intended_use' => 'NONCOMMERCIAL_RESEARCH',
                        ];
                        $pin = $pins->pin($batch->id, $scope, 200);
                        $this->info("Dataset pinned for {$symbol}: {$pin->id} (canonical: {$pin->canonical_pin_id})");
                    } catch (\Throwable $e) {
                        $this->warn("Dataset pin notice for {$symbol}: ".$e->getMessage());
                    }
                }
            }
        }

        return self::SUCCESS;
    }
}
