<?php

declare(strict_types=1);

namespace App\Providers;

use App\Backtest\Application\BacktestRunService;
use App\Backtest\Application\BacktestSimulationEngine;
use App\Backtest\Application\ChronologicalSplitter;
use App\Backtest\Application\DatasetInspector;
use App\Backtest\Application\GridOptimizer;
use App\Backtest\Application\PerformanceMetricCalculator;
use App\Backtest\Application\StrategySignalEvaluator;
use App\Backtest\Contracts\HistoricalSignalEvaluator;
use App\Indicators\Application\IndicatorEngine;
use App\Indicators\Application\WarmupPolicy;
use App\Indicators\Domain\IndicatorParameters;
use App\Indicators\Math\Decimal;
use App\MarketData\Application\CandleSynchronizer;
use App\MarketData\Application\ClosedCandleIngestor;
use App\MarketData\Application\MarketStreamSupervisor;
use App\MarketData\Contracts\Backoff;
use App\MarketData\Contracts\BybitMarketDataClient;
use App\MarketData\Contracts\CandleStore;
use App\MarketData\Contracts\HttpTransport;
use App\MarketData\Contracts\InstrumentMetadataProvider;
use App\MarketData\Contracts\Sleeper;
use App\MarketData\Contracts\WebSocketConnector;
use App\MarketData\Infrastructure\Bybit\BybitRestClient;
use App\MarketData\Infrastructure\Http\ExponentialJitterBackoff;
use App\MarketData\Infrastructure\Http\LaravelHttpTransport;
use App\MarketData\Infrastructure\Http\NativeSleeper;
use App\MarketData\Infrastructure\Persistence\EloquentCandleStore;
use App\MarketData\Infrastructure\WebSocket\PhrityWebSocketConnector;
use App\PaperTrading\Application\CorrelationService;
use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Application\PaperExecutionEngine;
use App\PaperTrading\Application\PaperProcessingService;
use App\PaperTrading\Application\PositionLifecycleService;
use App\PaperTrading\Application\PositionSizingService;
use App\PaperTrading\Application\RiskEngine;
use App\PaperTrading\Application\TradingJournalService;
use App\Strategy\Application\LevelInteractionAnalyzer;
use App\Strategy\Application\SignalGenerator;
use App\Strategy\Application\StrategyEngine;
use App\Strategy\Application\StrategyInputFactory;
use App\Strategy\Application\StrategyRankingService;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Contracts\MarketContextRepository;
use App\Strategy\Contracts\SignalRepository;
use App\Strategy\Infrastructure\Persistence\EloquentMarketContextRepository;
use App\Strategy\Infrastructure\Persistence\EloquentSignalRepository;
use App\Strategy\Research\BaselineStrategy;
use App\Strategy\Research\BollingerMeanReversionStrategy;
use App\Strategy\Research\DonchianBreakoutStrategy;
use App\Strategy\Research\MarketRegimeClassifier;
use App\Strategy\Research\RegimeSwitchingStrategy;
use App\Strategy\Research\TrendPullbackStrategy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Decimal::class, fn (): Decimal => new Decimal(
            internalScale: (int) config('indicators.internal_scale'),
            outputScale: (int) config('indicators.output_scale'),
        ));
        $this->app->singleton(IndicatorParameters::class, fn (): IndicatorParameters => IndicatorParameters::fromConfig(
            (array) config('indicators'),
        ));
        $this->app->singleton(WarmupPolicy::class);
        $this->app->singleton(LevelInteractionAnalyzer::class, fn (): LevelInteractionAnalyzer => new LevelInteractionAnalyzer((array) config('strategy.level_interaction')));
        $this->app->singleton(StrategyEngine::class, fn (): StrategyEngine => new StrategyEngine((array) config('strategy')));
        $this->app->singleton(StrategyRegistry::class, function (): StrategyRegistry {
            $research = (array) config('research.strategies');
            $trend = new TrendPullbackStrategy($research['trend_pullback']);
            $range = new BollingerMeanReversionStrategy($research['bollinger_mean_reversion']);
            $breakout = new DonchianBreakoutStrategy($research['donchian_atr_breakout']);
            $regimeConfig = $research['regime_switching'];

            return new StrategyRegistry([
                new BaselineStrategy((array) config('strategy')), $trend, $range, $breakout,
                new RegimeSwitchingStrategy($regimeConfig, new MarketRegimeClassifier($regimeConfig), $trend, $breakout, $range),
            ]);
        });
        $this->app->singleton(StrategyRankingService::class, fn (): StrategyRankingService => new StrategyRankingService((array) config('research.ranking')));
        $this->app->singleton(MarketContextRepository::class, EloquentMarketContextRepository::class);
        $this->app->singleton(SignalRepository::class, EloquentSignalRepository::class);
        $this->app->singleton(StrategyInputFactory::class, fn ($app): StrategyInputFactory => new StrategyInputFactory(
            indicators: $app->make(IndicatorEngine::class),
            contexts: $app->make(MarketContextRepository::class),
            config: (array) config('strategy'),
        ));
        $this->app->singleton(SignalGenerator::class, fn ($app): SignalGenerator => new SignalGenerator(
            inputs: $app->make(StrategyInputFactory::class),
            engine: $app->make(StrategyEngine::class),
            signals: $app->make(SignalRepository::class),
            cache: $app->make(Factory::class),
            config: (array) config('strategy'),
        ));
        $this->app->singleton(PaperAccountService::class, fn (): PaperAccountService => new PaperAccountService((array) config('paper')));
        $this->app->singleton(PositionSizingService::class, fn (): PositionSizingService => new PositionSizingService((array) config('paper')));
        $this->app->singleton(CorrelationService::class, fn (): CorrelationService => new CorrelationService((array) config('paper')));
        $this->app->singleton(RiskEngine::class, fn (): RiskEngine => new RiskEngine((array) config('paper')));
        $this->app->singleton(PaperExecutionEngine::class, fn (): PaperExecutionEngine => new PaperExecutionEngine((array) config('paper')));
        $this->app->singleton(PositionLifecycleService::class, fn ($app): PositionLifecycleService => new PositionLifecycleService(
            execution: $app->make(PaperExecutionEngine::class), journal: $app->make(TradingJournalService::class), config: (array) config('paper'),
        ));
        $this->app->singleton(PaperProcessingService::class, fn ($app): PaperProcessingService => new PaperProcessingService(
            accounts: $app->make(PaperAccountService::class), risk: $app->make(RiskEngine::class), correlation: $app->make(CorrelationService::class),
            sizing: $app->make(PositionSizingService::class), execution: $app->make(PaperExecutionEngine::class), lifecycle: $app->make(PositionLifecycleService::class),
            journal: $app->make(TradingJournalService::class), cache: $app->make(Factory::class), config: (array) config('paper'),
        ));
        $this->app->singleton(HistoricalSignalEvaluator::class, StrategySignalEvaluator::class);
        $this->app->singleton(BacktestSimulationEngine::class, fn ($app): BacktestSimulationEngine => new BacktestSimulationEngine(
            signals: $app->make(HistoricalSignalEvaluator::class), risk: $app->make(RiskEngine::class), correlation: $app->make(CorrelationService::class), paperConfig: (array) config('paper'),
        ));
        $this->app->singleton(GridOptimizer::class, fn ($app): GridOptimizer => new GridOptimizer(
            engine: $app->make(BacktestSimulationEngine::class), metrics: $app->make(PerformanceMetricCalculator::class), config: (array) config('backtest.optimization'),
        ));
        $this->app->singleton(BacktestRunService::class, fn ($app): BacktestRunService => new BacktestRunService(
            datasets: $app->make(DatasetInspector::class), splitter: $app->make(ChronologicalSplitter::class),
            engine: $app->make(BacktestSimulationEngine::class), metrics: $app->make(PerformanceMetricCalculator::class), optimizer: $app->make(GridOptimizer::class), config: (array) config('backtest'),
        ));

        $this->app->singleton(HttpTransport::class, fn (): HttpTransport => new LaravelHttpTransport(
            baseUrl: (string) config('market_data.rest.base_url'),
            timeoutSeconds: (int) config('market_data.rest.timeout_seconds'),
            connectTimeoutSeconds: (int) config('market_data.rest.connect_timeout_seconds'),
        ));
        $this->app->singleton(Backoff::class, fn (): Backoff => new ExponentialJitterBackoff(
            baseMilliseconds: (int) config('market_data.rest.backoff_base_ms'),
            maxMilliseconds: (int) config('market_data.rest.backoff_max_ms'),
        ));
        $this->app->singleton(Sleeper::class, NativeSleeper::class);
        $this->app->singleton(CandleStore::class, EloquentCandleStore::class);
        $this->app->singleton(BybitMarketDataClient::class, fn ($app): BybitMarketDataClient => new BybitRestClient(
            transport: $app->make(HttpTransport::class),
            backoff: $app->make(Backoff::class),
            sleeper: $app->make(Sleeper::class),
            log: $app->make('log'),
            maxRetries: (int) config('market_data.rest.max_retries'),
            pageSize: (int) config('market_data.rest.page_size'),
        ));
        $this->app->alias(BybitMarketDataClient::class, InstrumentMetadataProvider::class);
        $this->app->singleton(WebSocketConnector::class, fn ($app): WebSocketConnector => new PhrityWebSocketConnector(
            logger: $app->make('log'),
            receiveTimeoutSeconds: (int) config('market_data.websocket.receive_timeout_seconds'),
        ));
        $this->app->when(CandleSynchronizer::class)
            ->needs('$backfillPasses')
            ->giveConfig('market_data.sync.backfill_passes');
        $this->app->when(CandleSynchronizer::class)
            ->needs('$lockSeconds')
            ->giveConfig('market_data.sync.lock_seconds');
        $this->app->singleton(MarketStreamSupervisor::class, fn ($app): MarketStreamSupervisor => new MarketStreamSupervisor(
            connector: $app->make(WebSocketConnector::class),
            ingestor: $app->make(ClosedCandleIngestor::class),
            synchronizer: $app->make(CandleSynchronizer::class),
            store: $app->make(CandleStore::class),
            backoff: $app->make(Backoff::class),
            sleeper: $app->make(Sleeper::class),
            log: $app->make('log'),
            url: (string) config('market_data.websocket.url'),
            symbols: (array) config('market_data.symbols'),
            intervals: (array) config('market_data.intervals'),
            heartbeatSeconds: (int) config('market_data.websocket.heartbeat_seconds'),
            recoveryLookbackMinutes: (int) config('market_data.websocket.recovery_lookback_minutes'),
            reconnectMaxSeconds: (int) config('market_data.websocket.reconnect_max_seconds'),
        ));
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', fn ($request) => Limit::perMinute(120)->by($request->ip()));
    }
}
