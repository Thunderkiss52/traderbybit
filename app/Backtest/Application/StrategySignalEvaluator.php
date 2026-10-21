<?php

declare(strict_types=1);

namespace App\Backtest\Application;

use App\Backtest\Contracts\HistoricalSignalEvaluator;
use App\Strategy\Application\LevelInteractionAnalyzer;
use App\Strategy\Application\LevelProbabilityService;
use App\Strategy\Application\StrategyEngine;
use App\Strategy\Application\StrategyInputFactory;
use App\Strategy\Domain\SignalDecision;
use App\Strategy\Research\BollingerMeanReversionStrategy;
use App\Strategy\Research\DonchianBreakoutStrategy;
use App\Strategy\Research\MarketRegimeClassifier;
use App\Strategy\Research\MomentumStrategy;
use App\Strategy\Research\RegimeSwitchingStrategy;
use App\Strategy\Research\SupportResistanceBounceStrategy;
use App\Strategy\Research\SupportResistanceBreakoutStrategy;
use App\Strategy\Research\TrendPullbackStrategy;
use DateTimeImmutable;

final readonly class StrategySignalEvaluator implements HistoricalSignalEvaluator
{
    public function __construct(
        private StrategyInputFactory $inputs,
        private LevelInteractionAnalyzer $levels,
        private LevelProbabilityService $probabilities,
    ) {}

    public function evaluate(string $symbol, DateTimeImmutable $at, array $strategyConfig): SignalDecision
    {
        $input = $this->inputs->make('bybit', 'linear', $symbol, $at);
        $id = $strategyConfig['_research_strategy_id'] ?? null;
        if ($id === null || $id === 'baseline') {
            $config = $strategyConfig;
            unset($config['_research_strategy_id']);
            $config['version'] ??= (string) config('strategy.version');

            return (new StrategyEngine($config, $this->levels))->evaluate($input);
        }
        $parameters = (array) ($strategyConfig['_research_parameters'] ?? []);
        $strategy = match ($id) {
            'trend_pullback' => new TrendPullbackStrategy($parameters),
            'bollinger_mean_reversion' => new BollingerMeanReversionStrategy($parameters),
            'donchian_atr_breakout' => new DonchianBreakoutStrategy($parameters),
            'regime_switching' => $this->regime($parameters),
            'momentum' => new MomentumStrategy($parameters),
            'support_resistance_bounce' => new SupportResistanceBounceStrategy($parameters, $this->levels, $this->probabilities),
            'support_resistance_breakout' => new SupportResistanceBreakoutStrategy($parameters, $this->levels, $this->probabilities),
            default => throw new \InvalidArgumentException("Unknown research strategy: {$id}"),
        };

        return $strategy->evaluate($input);
    }

    private function regime(array $parameters): RegimeSwitchingStrategy
    {
        $all = (array) config('research.strategies');
        $trend = new TrendPullbackStrategy($all['trend_pullback']);
        $breakout = new DonchianBreakoutStrategy($all['donchian_atr_breakout']);
        $range = new BollingerMeanReversionStrategy($all['bollinger_mean_reversion']);

        return new RegimeSwitchingStrategy($parameters, new MarketRegimeClassifier($parameters), $trend, $breakout, $range);
    }
}
