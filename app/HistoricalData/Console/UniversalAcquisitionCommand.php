<?php

declare(strict_types=1);

namespace App\HistoricalData\Console;

use App\HistoricalData\UniversalAcquisitionService;
use Illuminate\Console\Command;

final class UniversalAcquisitionCommand extends Command
{
    protected $signature = 'historical:universal-acquire
        {--exchange=binance : Target exchange (binance, bybit, coinbase, okx, etc.)}
        {--market-type=linear : Market type (spot, linear, perpetual, futures)}
        {--symbol=BTCUSDT : Trading pair symbol}
        {--interval=5m : Candle timeframe (1m, 5m, 15m, 1h, etc.)}
        {--from= : Start date/time UTC (ISO 8601 or YYYY-MM-DD)}
        {--to= : End date/time UTC (ISO 8601 or YYYY-MM-DD)}
        {--categories=candles : Comma-separated categories (candles,funding,open_interest)}
        {--dry-run : Only plan and verify without downloading or writing to database}';

    protected $description = 'Universal historical market data acquisition across any registered exchange, market type, and categories';

    public function handle(UniversalAcquisitionService $service): int
    {
        $exchange = (string) ($this->option('exchange') ?? 'binance');
        $marketType = (string) ($this->option('market-type') ?? 'linear');
        $symbol = strtoupper((string) ($this->option('symbol') ?? 'BTCUSDT'));
        $interval = (string) ($this->option('interval') ?? '5m');
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');
        $dryRun = (bool) $this->option('dry-run');
        $categoriesStr = (string) ($this->option('categories') ?? 'candles');
        $categories = array_values(array_filter(array_map('trim', explode(',', $categoriesStr))));

        if ($from === '' || $to === '') {
            $this->error('Both --from and --to parameters are required.');

            return self::FAILURE;
        }

        try {
            $plan = $service->plan($exchange, $marketType, $symbol, $interval, $from, $to, $categories);
        } catch (\Throwable $e) {
            $this->error('Planning failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Universal Acquisition Plan: {$plan['exchange']} {$plan['symbol']} ({$plan['display_interval']})");
        $this->table(
            ['Parameter', 'Value'],
            [
                ['Exchange', $plan['exchange']],
                ['Market Type', $plan['market_type']],
                ['Symbol', $plan['symbol']],
                ['Interval', $plan['display_interval'].' ('.$plan['interval'].' min)'],
                ['From', $plan['start_date']],
                ['To', $plan['end_date']],
                ['Categories', implode(', ', $plan['categories'])],
                ['Planned Chunks', (string) count($plan['chunks'])],
                ['Expected Candles', number_format($plan['expected_candle_count'])],
                ['License', $plan['provenance']['license'] ?? 'NONCOMMERCIAL_RESEARCH'],
            ]
        );

        if ($dryRun) {
            $dryResult = $service->acquire($plan, dryRun: true);
            $this->info('Dry-run complete. Planned '.count($plan['chunks']).' chunks, expected '.number_format($plan['expected_candle_count']).' candles. Zero database records created.');

            return self::SUCCESS;
        }

        $this->info('Starting historical data acquisition...');
        $chunksCount = count($plan['chunks']);
        $bar = $this->output->createProgressBar(max(1, $chunksCount));
        $bar->start();

        try {
            $result = $service->acquire(
                $plan,
                dryRun: false,
                progressCallback: function () use ($bar): void {
                    $bar->advance();
                }
            );
        } catch (\Throwable $e) {
            $bar->finish();
            $this->newLine();
            $this->error('Acquisition failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Acquisition completed with status: '.$result['status']);
        $this->table(
            ['Metric', 'Value'],
            [
                ['Status', $result['status']],
                ['Chunks Total', (string) $result['chunks_total']],
                ['Chunks Acquired', (string) $result['chunks_acquired']],
                ['Chunks Skipped (Resumed)', (string) $result['chunks_skipped']],
                ['Candles Acquired', (string) $result['acquired_candles']],
                ['Candles Duplicate (Idempotent)', (string) $result['duplicate_candles']],
                ['Candles Conflicts', (string) $result['conflicts_count']],
                ['Coverage Ratio', number_format(($result['coverage']['coverage_ratio'] ?? 0) * 100, 2).'%'],
                ['Dataset Pin ID', $result['dataset_pin']['id'] ?? 'N/A'],
                ['SHA-256', $result['dataset_pin']['sha256'] ?? 'N/A'],
            ]
        );

        return $result['status'] === 'FAILED' ? self::FAILURE : self::SUCCESS;
    }
}
