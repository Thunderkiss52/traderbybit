<?php

use App\Audit\Application\AuditExportService;
use App\Exchanges\Binance\BinanceCandleBackfillService;
use App\Exchanges\Binance\BinanceCandleIngestion;
use App\Exchanges\Binance\BinanceContextCapture;
use App\Exchanges\Binance\BinanceDeploymentInitializer;
use App\Exchanges\Binance\BinanceFundingIngestion;
use App\Exchanges\Binance\BinancePaperActivation;
use App\Exchanges\Binance\BinancePaperCapital;
use App\Exchanges\Binance\BinancePaperProvisioner;
use App\Exchanges\Binance\BinancePaperReadiness;
use App\Exchanges\Binance\BinancePortabilityMatrix;
use App\Exchanges\Binance\BinanceResearchCycle;
use App\Exchanges\Binance\ExchangePaperReconciliation;
use App\MarketData\Domain\CandleIntegrityUnavailable;
use App\MarketData\Jobs\CheckMarketDataFreshnessJob;
use App\MarketData\Jobs\EnsureStrategyHistoryJob;
use App\MarketData\Jobs\RecoverCandleGapsJob;
use App\MarketData\Jobs\SyncFundingHistoryJob;
use App\MarketData\Jobs\SyncOpenInterestHistoryJob;
use App\PaperTrading\Application\EquitySnapshotRepairService;
use App\PaperTrading\Application\PaperProcessingService;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Jobs\CapturePaperEquityJob;
use App\PaperTrading\Jobs\ProcessPaperTradingJob;
use App\Research\Application\ResearchJobService;
use App\Research\Jobs\ProcessAutonomousLearningJob;
use App\Research\Jobs\ReconcileLedgerJob;
use App\Strategy\Jobs\CaptureMarketContextJob;
use App\Strategy\Jobs\EnsureResearchCoverageJob;
use App\Strategy\Jobs\GenerateSignalJob;
use App\Strategy\Jobs\ObserveStrategiesJob;
use App\Strategy\Jobs\ProcessResearchPaperJob;
use App\Trading\Application\TradingPipelineRecoveryService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Separate owner-approved data migration and irreversible account provisioning.
Artisan::command('binance:provision-paper-accounts {--force : Approve provisioning explicitly without requiring env variable}', function (BinancePaperProvisioner $provisioner): void {
    if ($this->option('force')) {
        config(['exchanges.binance_paper_provisioning_approved' => true]);
    }
    $this->line(json_encode($provisioner->provision(), JSON_THROW_ON_ERROR));
})->purpose('Explicit, gated provisioning of 13 BLOCKED zero-balance Binance paper accounts');

Artisan::command('binance:initialize-paper {--capital=10000} {--reference=OWNER-VECTOR-20261023} {--force}', function (BinanceDeploymentInitializer $initializer): int {
    try {
        $amount = (string) $this->option('capital');
        $reference = (string) $this->option('reference');
        $result = $initializer->initialize($amount, $reference);
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return 0;
    } catch (Throwable $e) {
        $this->error('Binance initialization failed: '.$e->getMessage());

        return 1;
    }
})->purpose('Idempotently provision, configure and fund 13 canonical Binance paper accounts (status remains BLOCKED)');

// Opt-in only: no automatic Binance trading or production schedule is added.
Artisan::command('binance:ingest-candles {symbol=BTCUSDT} {interval=5m} {--limit=100} {--quarantine}', function (BinanceCandleIngestion $ingestion): void {
    $report = $ingestion->ingest(
        strtoupper((string) $this->argument('symbol')),
        (string) $this->argument('interval'),
        (int) $this->option('limit'),
        (bool) $this->option('quarantine'),
    );
    $this->line(json_encode($report, JSON_THROW_ON_ERROR));
})->purpose('Ingest public, closed Binance candles for research only; no paper execution');

Artisan::command('binance:ingest-htf {symbol=BTCUSDT} {interval=30m} {--limit=10} {--quarantine}', function (BinanceCandleIngestion $ingestion): int {
    try {
        $report = $ingestion->ingest(
            strtoupper((string) $this->argument('symbol')),
            (string) $this->argument('interval'),
            (int) $this->option('limit'),
            (bool) $this->option('quarantine')
        );
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return isset($report['status']) && $report['status'] === 'PARTIAL_INGESTION_WITH_CONFLICTS' ? 2 : 0;
    } catch (Throwable $e) {
        if ($e instanceof CandleIntegrityUnavailable && $e->ingestionReport !== null) {
            $this->line(json_encode($e->ingestionReport, JSON_THROW_ON_ERROR));
        }
        $this->error('Binance HTF ingestion failed: '.$e->getMessage());

        return $e instanceof CandleIntegrityUnavailable ? 3 : 1;
    }
})->purpose('Explicitly ingest Binance higher-timeframe candles with conflict quarantine options');

// Explicit paper research commands. No scheduler is enabled, no real orders.
Artisan::command('binance:capture-context {symbol=BTCUSDT}', function (BinanceContextCapture $capture): void {
    $this->line(json_encode($capture->capture(strtoupper((string) $this->argument('symbol'))), JSON_THROW_ON_ERROR));
})->purpose('Capture live public Binance context before strategy decision; no orders');

Artisan::command('binance:ingest-research {symbol=BTCUSDT}', function (BinanceCandleIngestion $ingestion): void {
    $symbol = strtoupper((string) $this->argument('symbol'));
    $reports = [];
    foreach (['5m', '15m', '30m', '1h', '4h'] as $interval) {
        $reports[] = $ingestion->ingest($symbol, $interval, 500);
    }
    $this->line(json_encode($reports, JSON_THROW_ON_ERROR));
})->purpose('Ingest closed multi-timeframe Binance research candles; no execution');

Artisan::command('binance:backfill-candles {symbol=BTCUSDT} {interval=30m} {--days=14} {--from=} {--to=} {--apply : Apply mutations; default is dry-run}', function (BinanceCandleBackfillService $backfill): int {
    if (! (bool) config('exchanges.binance.research_cycle_enabled', true)) {
        $this->error('Binance research cycle is disabled by configuration.');

        return 1;
    }
    $symbol = (string) $this->argument('symbol');
    $interval = (string) $this->argument('interval');
    $days = (int) $this->option('days');
    $fromArg = $this->option('from');
    $toArg = $this->option('to');
    $apply = (bool) $this->option('apply');

    $nowMs = (int) floor(microtime(true) * 1000);
    $fromMs = $fromArg !== null ? (int) $fromArg : max(0, $nowMs - $days * 86_400_000);
    $toMs = $toArg !== null ? (int) $toArg : $nowMs;
    if ($fromMs >= $toMs) {
        $this->error('Candle range start must precede end.');

        return 1;
    }
    if (! $apply) {
        $this->comment("DRY-RUN: would backfill {$symbol} {$interval} from {$fromMs} to {$toMs}. Pass --apply to write.");

        return 0;
    }

    $report = $backfill->backfill($symbol, $interval, $fromMs, $toMs);
    $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    return $report['status'] === 'COMPLETED' ? 0 : 1;
})->purpose('Backfill historical Binance candles for research (data-only, no execution).');
Artisan::command('binance:ingest-funding {symbol=BTCUSDT}', function (BinanceFundingIngestion $ingestion): void {
    $this->line(json_encode($ingestion->ingest(strtoupper((string) $this->argument('symbol'))), JSON_THROW_ON_ERROR));
})->purpose('Ingest verified public Binance funding settlements with source mark price');

Artisan::command('binance:research-cycle {symbol=BTCUSDT} {--paper : Process funded approved PAPER accounts only} {--at= : ISO-8601 decision timestamp}', function (BinanceResearchCycle $cycle): void {
    $at = $this->option('at') ? new DateTimeImmutable((string) $this->option('at')) : null;
    $this->line(json_encode($cycle->run(strtoupper((string) $this->argument('symbol')), (bool) $this->option('paper'), $at), JSON_THROW_ON_ERROR));
})->purpose('Evaluate all 13 Binance strategy families; PAPER is separately gated');

Artisan::command('binance:paper-reconcile', function (ExchangePaperReconciliation $check): int {
    $report = $check->inspect();
    $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    return $report['status'] === 'LEDGER_PASS_ONLY' ? 0 : 1;
})->purpose('Read-only M9 26-account and exact-decimal ledger reconciliation');

Artisan::command('paper:repair-equity-snapshots {--apply : Apply database mutations; default is dry-run} {--dry-run : Simulate repair without writing}', function (EquitySnapshotRepairService $repair): int {
    $apply = (bool) $this->option('apply') && ! (bool) $this->option('dry-run');
    try {
        $result = $repair->repair($apply);
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return 0;
    } catch (Throwable $e) {
        $this->error('Equity snapshot repair failed: '.$e->getMessage());

        return 1;
    }
})->purpose('Safely and idempotently repair cross-exchange equity snapshots for canonical Binance primary accounts');

Artisan::command('binance:repair-equity-snapshots {--apply : Apply database mutations; default is dry-run} {--dry-run : Simulate repair without writing}', function (EquitySnapshotRepairService $repair): int {
    $apply = (bool) $this->option('apply') && ! (bool) $this->option('dry-run');
    try {
        $result = $repair->repair($apply);
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return 0;
    } catch (Throwable $e) {
        $this->error('Binance equity snapshot repair failed: '.$e->getMessage());

        return 1;
    }
})->purpose('Alias for paper:repair-equity-snapshots; repairs cross-exchange equity snapshots');

Artisan::command('binance:portability', function (BinancePortabilityMatrix $matrix): void {
    $this->line(json_encode($matrix->report(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
})->purpose('Read-only M6 13x2 market-data readiness and strategy limitations');

Artisan::command('binance:allocate-paper-capital {amount} {reference} {--approve}', function (BinancePaperCapital $capital): int {
    if (! (bool) $this->option('approve')) {
        $this->error('Owner must explicitly provide --approve; no paper balances changed.');

        return 1;
    }
    $result = $capital->allocate((string) $this->argument('amount'), (string) $this->argument('reference'));
    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    return 0;
})->purpose('Explicit idempotent owner paper-capital allocation to 13 Binance accounts; stays BLOCKED');

Artisan::command('binance:paper-readiness {--require-execution : Require VECTOR_BINANCE_PAPER_EXECUTION_APPROVED=true}', function (BinancePaperReadiness $readiness): int {
    $requireExecution = (bool) $this->option('require-execution');
    $report = $readiness->inspect($requireExecution);
    $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    return $report['overall_readiness'] !== 'BLOCKED' ? 0 : 1;
})->purpose('Read-only comprehensive check of Binance cost evidence, candles, funding, census, and execution eligibility');

Artisan::command('binance:activate-paper {--approve}', function (BinancePaperActivation $activation): int {
    if (! (bool) $this->option('approve')) {
        $this->error('Explicit owner --approve is required.');

        return 1;
    }
    $result = $activation->activate();
    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    if (! ($result['paper_execution_approved'] ?? false)) {
        $this->warn('NOTE: Account status updated to ACTIVE for eligible strategies, but simulated executions remain BLOCKED because VECTOR_BINANCE_PAPER_EXECUTION_APPROVED is false.');
    }

    return 0;
})->purpose('Explicitly activate eligible funded Binance PAPER accounts after readiness gates');

Artisan::command('trading:pipeline-recovery', function (TradingPipelineRecoveryService $recovery): void {
    $this->line(json_encode($recovery->recover(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
})->purpose('Scan and recover any pending or uncompleted trading pipeline events across isolated queues');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new RecoverCandleGapsJob)->everyFiveMinutes()->withoutOverlapping();

Schedule::job(new CheckMarketDataFreshnessJob)->everyMinute()->withoutOverlapping();
// Bootstrap and maintain 220 contiguous closed candles on each strategy timeframe.
Schedule::job(new EnsureStrategyHistoryJob)->everyFifteenMinutes()->name('market:ensure-strategy-history')->withoutOverlapping();
Schedule::job(new EnsureResearchCoverageJob)->cron('7,22,37,52 * * * *')->name('research:ensure-coverage')->withoutOverlapping();

foreach ((array) config('market_data.symbols', ['BTCUSDT', 'ETHUSDT']) as $symbol) {
    // Capture before the 15m close: a quote taken after it cannot be used at that decision time.
    Schedule::job(new CaptureMarketContextJob((string) $symbol))
        ->cron('13,28,43,58 * * * *')
        ->name("market-context:capture:{$symbol}")
        ->withoutOverlapping();
    Schedule::job(new SyncFundingHistoryJob((string) $symbol))
        ->cron('11 * * * *')
        ->name("market-funding:sync:{$symbol}")
        ->withoutOverlapping();
    Schedule::job(new SyncOpenInterestHistoryJob((string) $symbol))
        ->cron('12 * * * *')
        ->name("market-open-interest:sync:{$symbol}")
        ->withoutOverlapping();
    Schedule::job(new GenerateSignalJob((string) $symbol))
        ->cron('2,17,32,47 * * * *')
        ->name("signals:generate:{$symbol}")
        ->withoutOverlapping();
    // GenerateSignalJob already generates signals and observes all registered strategies at :02, :17, :32, :47.
    // ObserveStrategiesJob runs hourly at :03 for recent multi-candle catchup without redundant 15m duplicate runs.
    Schedule::job(new ObserveStrategiesJob((string) $symbol))
        ->cron('3 * * * *')
        ->name("strategies:observe:{$symbol}")
        ->withoutOverlapping();
}

Schedule::job(new ProcessPaperTradingJob)
    ->cron('4,9,14,19,24,29,34,39,44,49,54,59 * * * *')
    ->name('paper:process:default')
    ->withoutOverlapping();
Schedule::job(new ProcessResearchPaperJob)
    ->cron('4,19,34,49 * * * *')
    ->name('research:forward-paper')
    ->withoutOverlapping();

// Remove only expired private audit archives, never trading history or Docker volumes.
Schedule::call(fn () => app(AuditExportService::class)->purgeExpired())->hourly()->name('audit:purge-expired')->withoutOverlapping();

// Record regular portfolio marks after scheduled paper position processing.
Schedule::job(new CapturePaperEquityJob)
    ->cron('5,20,35,50 * * * *')->name('paper:equity-checkpoint')->withoutOverlapping();

// OFF by default. Enable only after isolated migration/E2E checks and explicit owner approval.
// Entire sequence uses public Binance data and simulated accounting only.
if ((bool) config('exchanges.binance.research_cycle_enabled', false)) {
    foreach ((array) config('market_data.symbols', ['BTCUSDT', 'ETHUSDT']) as $symbol) {
        $symbol = (string) $symbol;
        Schedule::call(fn () => app(BinanceContextCapture::class)->capture($symbol))
            ->cron('13,28,43,58 * * * *')->name("binance:context:{$symbol}")->withoutOverlapping();
        Schedule::call(fn () => app(BinanceCandleIngestion::class)->ingest($symbol, '5m', 30))
            ->cron('1,6,11,16,21,26,31,36,41,46,51,56 * * * *')
            ->name("binance:5m:{$symbol}")->withoutOverlapping();
        foreach (['15m', '30m', '1h', '4h'] as $interval) {
            Schedule::command('binance:ingest-htf', [$symbol, $interval, '--limit' => 10, '--quarantine'])
                ->appendOutputTo(storage_path('logs/binance-htf-ingestion.log'))
                ->cron('1,16,31,46 * * * *')->name("binance:htf:{$symbol}:{$interval}")->withoutOverlapping();
        }
        Schedule::call(fn () => app(BinanceFundingIngestion::class)->ingest($symbol))
            ->cron('10 * * * *')->name("binance:funding:{$symbol}")->withoutOverlapping();
        Schedule::call(function () use ($symbol): void {
            if (config('trading.pipeline_mode') === 'EVENT_DRIVEN') {
                $recovery = app(TradingPipelineRecoveryService::class);
                if ($recovery->isDecisionAlreadyServiced('binance', $symbol, null)) {
                    return;
                }
            }
            app(BinanceResearchCycle::class)->run($symbol, false);
        })->cron('2,17,32,47 * * * *')->name("binance:signals:{$symbol}")->withoutOverlapping();
    }
    // Maintain and service Binance paper accounts on every 5m cycle.
    // When owner execution is approved, new entries are permitted. When unapproved,
    // existing positions and funding settlement continue to be serviced while new entries are rejected.
    Schedule::call(function (): void {
        if (config('trading.pipeline_mode') === 'EVENT_DRIVEN') {
            $recovery = app(TradingPipelineRecoveryService::class);
            if ($recovery->isPaperAlreadyServiced('binance', null, null)) {
                return;
            }
        }
        $allowNewEntries = (bool) config('exchanges.binance.paper_execution_approved', false);
        $failures = [];
        foreach (PaperAccountRecord::query()
            ->where('exchange_id', 'binance')->where('is_primary', true)
            ->whereIn('status', ['ACTIVE', 'BLOCKED', 'HALTED'])->get() as $account) {
            if (in_array($account->strategy_id, (array) config('research.forward_paper.signal_only_strategies', []), true)) {
                continue;
            }
            try {
                app(PaperProcessingService::class)->process($account->id, allowNewEntries: $allowNewEntries);
            } catch (Throwable $e) {
                Log::channel('testing')->warning("Binance paper account {$account->id} ({$account->strategy_id}) processing error: ".$e->getMessage(), [
                    'account_id' => $account->id,
                    'strategy_id' => $account->strategy_id,
                    'exception' => $e::class,
                ]);
                $failures[] = [
                    'account_id' => $account->id,
                    'strategy_id' => $account->strategy_id,
                    'error' => $e->getMessage(),
                ];
            }
        }
        if (! empty($failures)) {
            Log::warning('Binance paper scheduled cycle completed with account failures.', [
                'failed_accounts_count' => count($failures),
                'failures' => $failures,
            ]);
        }
    })->cron('3,8,13,18,23,28,33,38,43,48,53,58 * * * *')
        ->name('binance:paper-scheduled')->withoutOverlapping();
}

// Legacy SKIP counterfactual analysis is quarantined (P-3 replay prohibited)
// and is intentionally not scheduled.

// Periodically reconcile all paper trading accounts, cash flow, and invariants on the audit worker.
Schedule::job(new ReconcileLedgerJob)
    ->cron('8,23,38,53 * * * *')->name('audit:reconcile-ledger')->withoutOverlapping();

// Recover or terminate research jobs whose worker lease expired unexpectedly.
Schedule::call(fn () => app(ResearchJobService::class)->reconcileStaleLeases())
    ->everyMinute()
    ->name('research:reconcile-stale-leases')
    ->withoutOverlapping();

// Recover uncompleted pipeline events and expire stale ones across isolated queues.
Schedule::call(fn () => app(TradingPipelineRecoveryService::class)->recover())
    ->everyMinute()
    ->name('trading:pipeline-recovery')
    ->withoutOverlapping();

// Execute continuous quantitative learning, model retraining, and challenger evaluation.
Schedule::job(new ProcessAutonomousLearningJob)
    ->cron('9,24,39,54 * * * *')->name('research:autonomous-learning')->withoutOverlapping();
