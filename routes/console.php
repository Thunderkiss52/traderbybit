<?php

use App\Audit\Application\AuditExportService;
use App\MarketData\Jobs\CheckMarketDataFreshnessJob;
use App\MarketData\Jobs\EnsureStrategyHistoryJob;
use App\MarketData\Jobs\RecoverCandleGapsJob;
use App\MarketData\Jobs\SyncFundingHistoryJob;
use App\MarketData\Jobs\SyncOpenInterestHistoryJob;
use App\PaperTrading\Jobs\CapturePaperEquityJob;
use App\PaperTrading\Jobs\ProcessPaperTradingJob;
use App\Research\Jobs\ProcessCounterfactualAnalysisJob;
use App\Research\Jobs\ReconcileLedgerJob;
use App\Strategy\Jobs\CaptureMarketContextJob;
use App\Strategy\Jobs\EnsureResearchCoverageJob;
use App\Strategy\Jobs\GenerateSignalJob;
use App\Strategy\Jobs\ObserveStrategiesJob;
use App\Strategy\Jobs\ProcessResearchPaperJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

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

// Evaluate and resolve matured SKIP counterfactuals on the research worker.
Schedule::job(new ProcessCounterfactualAnalysisJob)
    ->cron('6,21,36,51 * * * *')->name('research:counterfactual-analysis')->withoutOverlapping();

// Periodically reconcile all paper trading accounts, cash flow, and invariants on the audit worker.
Schedule::job(new ReconcileLedgerJob)
    ->cron('8,23,38,53 * * * *')->name('audit:reconcile-ledger')->withoutOverlapping();
