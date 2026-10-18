<?php

declare(strict_types=1);

namespace App\Strategy\Application;

use App\Research\Application\LearningFeatureStoreService;
use App\Strategy\Contracts\SignalRepository;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use RuntimeException;

final readonly class MarketScannerService
{
    public function __construct(
        private StrategyRegistry $strategies,
        private StrategyInputFactory $inputs,
        private StrategyObservationService $observations,
        private SignalGenerator $generator,
        private SignalRepository $signals,
        private CacheFactory $cache,
        private ?LearningFeatureStoreService $featureStore = null,
    ) {}

    /**
     * Scan market closed candles and coordinate evaluation for all symbols and registered strategies.
     * Evaluates 8 strategies * count($symbols) combinations with deduplication.
     */
    public function scan(?DateTimeImmutable $at = null, array $symbols = ['BTCUSDT', 'ETHUSDT'], string $exchange = 'bybit', string $category = 'linear'): array
    {
        $scannedAt = $at ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $strategyCount = count($this->strategies->all());
        $results = [];

        foreach ($symbols as $symbol) {
            $results[$symbol] = $this->scanSymbol($symbol, $at, $exchange, $category);
        }

        return [
            'scanned_at' => $scannedAt->format(DATE_ATOM),
            'symbols' => $symbols,
            'strategies_per_symbol' => $strategyCount,
            'total_evaluations' => count($symbols) * $strategyCount,
            'results' => $results,
        ];
    }

    /**
     * Coordinate scan for a single symbol across all registered strategies.
     */
    public function scanSymbol(string $symbol, ?DateTimeImmutable $at = null, string $exchange = 'bybit', string $category = 'linear'): array
    {
        $featureStore = $this->featureStore ?? app(LearningFeatureStoreService::class);
        $decisionTime = $at ?? $this->inputs->latestDecisionTime($exchange, $category, $symbol)
            ?? throw new RuntimeException("No closed 15m candle is available for {$symbol}.");

        // Evaluates all registered strategies, creates non-duplicate signals, records observations and pattern outcomes
        $observations = $this->observations->observe($symbol, $decisionTime, $exchange, $category);

        // Assemble feature snapshot for learning / predictor
        $baseline = collect($observations)->firstWhere('strategy_id', 'baseline');
        $regime = data_get($baseline, 'analysis.indicator_snapshot.regime');

        $featureStore->assembleSnapshot(
            symbol: $symbol,
            timeframe: '15',
            decisionTime: CarbonImmutable::instance($decisionTime),
            strategyId: 'baseline',
            regime: is_string($regime) ? $regime : null,
        );

        return [
            'symbol' => $symbol,
            'decision_time' => $decisionTime->format(DATE_ATOM),
            'evaluations_count' => count($observations),
            'observations' => $observations,
        ];
    }
}
