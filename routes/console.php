<?php

use App\Audit\Application\AuditExportService;
use App\Exchanges\Binance\BinanceCandleIngestion;
use App\Exchanges\Binance\BinanceContextCapture;
use App\Exchanges\Binance\BinanceFundingIngestion;
use App\Exchanges\Binance\BinancePaperActivation;
use App\Exchanges\Binance\BinancePaperCapital;
use App\Exchanges\Binance\BinancePaperProvisioner;
use App\Exchanges\Binance\BinancePortabilityMatrix;
use App\Exchanges\Binance\BinanceResearchCycle;
use App\Exchanges\Binance\ExchangePaperReconciliation;
use App\MarketData\Jobs\CheckMarketDataFreshnessJob;
use App\MarketData\Jobs\EnsureStrategyHistoryJob;
use App\MarketData\Jobs\RecoverCandleGapsJob;
use App\MarketData\Jobs\SyncFundingHistoryJob;
use App\MarketData\Jobs\SyncOpenInterestHistoryJob;
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
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Separate owner-approved data migration and irreversible account provisioning.
Artisan::command('binance:provision-paper-accounts', function (BinancePaperProvisioner $provisioner): void {
    $this->line(json_encode($provisioner->provision(), JSON_THROW_ON_ERROR));
})->purpose('Explicit, gated provisioning of 13 BLOCKED zero-balance Binance paper accounts');

// Opt-in only: no automatic Binance trading or production schedule is added.
Artisan::command('binance:ingest-candles {symbol=BTCUSDT} {interval=5m} {--limit=100}', function (BinanceCandleIngestion $ingestion): void {
    $report = $ingestion->ingest(
        strtoupper((string) $this->argument('symbol')),
        (string) $this->argument('interval'),
        (int) $this->option('limit'),
    );
    $this->line(json_encode($report, JSON_THROW_ON_ERROR));
})->purpose('Ingest public, closed Binance candles for research only; no paper execution');

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

Artisan::command('binance:ingest-funding {symbol=BTCUSDT}', function (BinanceFundingIngestion $ingestion): void {
    $this->line(json_encode($ingestion->ingest(strtoupper((string) $this->argument('symbol'))), JSON_THROW_ON_ERROR));
})->purpose('Ingest verified public Binance funding settlements with source mark price');

Artisan::command('binance:research-cycle {symbol=BTCUSDT} {--paper : Process funded approved PAPER accounts only}', function (BinanceResearchCycle $cycle): void {
    $this->line(json_encode($cycle->run(strtoupper((string) $this->argument('symbol')), (bool) $this->option('paper')), JSON_THROW_ON_ERROR));
})->purpose('Evaluate all 13 Binance strategy families; PAPER is separately gated');

Artisan::command('binance:paper-reconcile', function (ExchangePaperReconciliation $check): int {
    $report = $check->inspect();
    $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    return $report['status'] === 'LEDGER_PASS_ONLY' ? 0 : 1;
})->purpose('Read-only M9 26-account and exact-decimal ledger reconciliation');

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

Artisan::command('binance:activate-paper {--approve}', function (BinancePaperActivation $activation): int {
    if (! (bool) $this->option('approve')) {
        $this->error('Explicit owner --approve is required.');

        return 1;
    }
    $this->line(json_encode($activation->activate(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    return 0;
})->purpose('Explicitly activate eligible funded Binance PAPER accounts after readiness gates');

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
        Schedule::call(function () use ($symbol): void {
            foreach (['15m', '30m', '1h', '4h'] as $interval) {
                app(BinanceCandleIngestion::class)->ingest($symbol, $interval, 250);
            }
        })->cron('1,16,31,46 * * * *')->name("binance:htf:{$symbol}")->withoutOverlapping();
        Schedule::call(fn () => app(BinanceFundingIngestion::class)->ingest($symbol))
            ->cron('10 * * * *')->name("binance:funding:{$symbol}")->withoutOverlapping();
        Schedule::call(fn () => app(BinanceResearchCycle::class)->run($symbol, false))
            ->cron('2,17,32,47 * * * *')->name("binance:signals:{$symbol}")->withoutOverlapping();
    }
    // PAPER execution remains separately disabled unless the owner passes the cost,
    // capital, provenance and database safety gates. No real orders are possible.
    if ((bool) config('exchanges.binance.paper_execution_approved', false)) {
        Schedule::call(function (): void {
            foreach (PaperAccountRecord::query()
                ->where('exchange_id', 'binance')->where('is_primary', true)
                ->whereIn('status', ['ACTIVE', 'BLOCKED', 'HALTED'])->get() as $account) {
                if (in_array($account->strategy_id, (array) config('research.forward_paper.signal_only_strategies', []), true)
                    || $account->strategy_id === 'funding_open_interest_squeeze') {
                    continue;
                }
                app(PaperProcessingService::class)->process($account->id);
            }
        })->cron('3,8,13,18,23,28,33,38,43,48,53,58 * * * *')
            ->name('binance:paper-scheduled')->withoutOverlapping();
    }
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

// Execute continuous quantitative learning, model retraining, and challenger evaluation.
Schedule::job(new ProcessAutonomousLearningJob)
    ->cron('9,24,39,54 * * * *')->name('research:autonomous-learning')->withoutOverlapping();
