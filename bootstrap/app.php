<?php

use App\Backtest\Console\BacktestReportCommand;
use App\Backtest\Console\CancelBacktestCommand;
use App\Backtest\Console\CompareBacktestsCommand;
use App\Backtest\Console\RunBacktestCommand;
use App\Backtest\Console\WalkForwardCommand;
use App\MarketData\Console\StreamCandlesCommand;
use App\MarketData\Console\SyncCandlesCommand;
use App\PaperTrading\Console\PaperAccountCommand;
use App\PaperTrading\Console\PaperPositionsCommand;
use App\PaperTrading\Console\PaperStatsCommand;
use App\PaperTrading\Console\ProcessPaperTradingCommand;
use App\Strategy\Console\GenerateSignalsCommand;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withCommands([
        SyncCandlesCommand::class,
        StreamCandlesCommand::class,
        GenerateSignalsCommand::class,
        ProcessPaperTradingCommand::class,
        PaperPositionsCommand::class,
        PaperStatsCommand::class,
        PaperAccountCommand::class,
        RunBacktestCommand::class,
        WalkForwardCommand::class,
        BacktestReportCommand::class,
        CompareBacktestsCommand::class,
        CancelBacktestCommand::class,
    ])
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
