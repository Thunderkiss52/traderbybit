<?php

use App\MarketData\Jobs\CheckMarketDataFreshnessJob;
use App\MarketData\Jobs\EnsureStrategyHistoryJob;
use App\MarketData\Jobs\RecoverCandleGapsJob;
use App\PaperTrading\Jobs\ProcessPaperTradingJob;
use App\Strategy\Jobs\CaptureMarketContextJob;
use App\Strategy\Jobs\EnsureResearchCoverageJob;
use App\Strategy\Jobs\GenerateSignalJob;
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
Schedule::job(new EnsureStrategyHistoryJob)->hourly()->name('market:ensure-strategy-history')->withoutOverlapping();
Schedule::job(new EnsureResearchCoverageJob)->hourlyAt(20)->name('research:ensure-coverage')->withoutOverlapping();

foreach ((array) config('market_data.symbols', ['BTCUSDT', 'ETHUSDT']) as $symbol) {
    // Capture before the 15m close: a quote taken after it cannot be used at that decision time.
    Schedule::job(new CaptureMarketContextJob((string) $symbol))
        ->cron('13,28,43,58 * * * *')
        ->name("market-context:capture:{$symbol}")
        ->withoutOverlapping();
    Schedule::job(new GenerateSignalJob((string) $symbol))
        ->cron('2,17,32,47 * * * *')
        ->name("signals:generate:{$symbol}")
        ->withoutOverlapping();
}

Schedule::job(new ProcessPaperTradingJob)
    ->cron('4,9,14,19,24,29,34,39,44,49,54,59 * * * *')
    ->name('paper:process:default')
    ->withoutOverlapping();
Schedule::job(new ProcessResearchPaperJob)
    ->cron('3,18,33,48 * * * *')
    ->name('research:forward-paper')
    ->withoutOverlapping();
