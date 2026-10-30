<?php

use App\Backtest\Console\BacktestReportCommand;
use App\Backtest\Console\CancelBacktestCommand;
use App\Backtest\Console\CompareBacktestsCommand;
use App\Backtest\Console\RunBacktestCommand;
use App\Backtest\Console\WalkForwardCommand;
use App\Http\Middleware\ApiRequestDiagnostics;
use App\Http\Middleware\EnsureQuoteIntegrityEnabled;
use App\Http\Middleware\RequireResearchToken;
use App\Http\Middleware\ValidateBrowserOrigin;
use App\MarketData\Console\StreamCandlesCommand;
use App\MarketData\Console\SyncCandlesCommand;
use App\PaperTrading\Console\PaperAccountCommand;
use App\PaperTrading\Console\PaperPositionsCommand;
use App\PaperTrading\Console\PaperReconcileFundingCommand;
use App\PaperTrading\Console\PaperStatsCommand;
use App\PaperTrading\Console\ProcessPaperTradingCommand;
use App\QuoteIntegrity\Console\QimMeasurementReportCommand;
use App\QuoteIntegrity\Console\QimPurgeExpiredCommand;
use App\QuoteIntegrity\Console\QimRunChecksCommand;
use App\QuoteIntegrity\Console\QimSampleBarsCommand;
use App\Research\Console\ResearchBenchmarkCommand;
use App\Strategy\Console\GenerateSignalsCommand;
use App\Strategy\Domain\StrategyInputIntegrityException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [ApiRequestDiagnostics::class, ValidateBrowserOrigin::class]);
        $middleware->alias([
            'research.token' => RequireResearchToken::class,
            'quote_integrity.enabled' => EnsureQuoteIntegrityEnabled::class,
        ]);
    })
    ->withCommands([
        SyncCandlesCommand::class,
        StreamCandlesCommand::class,
        GenerateSignalsCommand::class,
        ProcessPaperTradingCommand::class,
        PaperPositionsCommand::class,
        PaperReconcileFundingCommand::class,
        PaperStatsCommand::class,
        PaperAccountCommand::class,
        RunBacktestCommand::class,
        WalkForwardCommand::class,
        BacktestReportCommand::class,
        CompareBacktestsCommand::class,
        CancelBacktestCommand::class,
        ResearchBenchmarkCommand::class,
        QimSampleBarsCommand::class,
        QimRunChecksCommand::class,
        QimMeasurementReportCommand::class,
        QimPurgeExpiredCommand::class,
    ])
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (Throwable $exception, Request $request): ?Response {
            if (! $request->is('api/*')) {
                return null;
            }
            if ($exception instanceof StrategyInputIntegrityException) {
                return response()->json([
                    'schema_version' => '1.0.0', 'research_mode' => true,
                    'error' => ['code' => $exception->reasonCode, 'message' => 'Strategy input is blocked by candle integrity.',
                        'integrity_status' => $exception->integrityStatus, 'scope' => $exception->scope,
                        'request_id' => $request->attributes->get('request_id')],
                ], 503);
            }
            if ($exception instanceof HttpExceptionInterface
                || $exception instanceof ValidationException
                || $exception instanceof ModelNotFoundException
                || $exception instanceof AuthenticationException
                || $exception instanceof AuthorizationException
                || $exception instanceof HttpResponseException) {
                return null;
            }

            return response()->json([
                'schema_version' => '1.0.0',
                'research_mode' => true,
                'error' => [
                    'code' => 'server_error',
                    'message' => 'The server could not complete the request.',
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], 500);
        });
    })->create();
