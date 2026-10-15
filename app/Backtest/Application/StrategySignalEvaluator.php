<?php

declare(strict_types=1);

namespace App\Backtest\Application;

use App\Backtest\Contracts\HistoricalSignalEvaluator;
use App\Strategy\Application\StrategyEngine;
use App\Strategy\Application\StrategyInputFactory;
use App\Strategy\Domain\SignalDecision;
use App\Strategy\Research\BollingerMeanReversionStrategy;
use App\Strategy\Research\DonchianBreakoutStrategy;
use App\Strategy\Research\MarketRegimeClassifier;
use App\Strategy\Research\RegimeSwitchingStrategy;
use App\Strategy\Research\TrendPullbackStrategy;
use DateTimeImmutable;

final readonly class StrategySignalEvaluator implements HistoricalSignalEvaluator
{
    public function __construct(private StrategyInputFactory $inputs) {}

    public function evaluate(string $symbol, DateTimeImmutable $at, array $strategyConfig): SignalDecision
    {
        $input = $this->inputs->make('bybit', 'linear', $symbol, $at);
        $id = $strategyConfig['_research_strategy_id'] ?? null;
        if ($id === null || $id === 'baseline') {
            return (new StrategyEngine($strategyConfig))->evaluate($input);
        }
        $parameters = (array) ($strategyConfig['_research_parameters'] ?? []);
        $strategy = match ($id) {
            'trend_pullback' => new TrendPullbackStrategy($parameters),
            'bollinger_mean_reversion' => new BollingerMeanReversionStrategy($parameters),
            'donchian_atr_breakout' => new DonchianBreakoutStrategy($parameters),
            'regime_switching' => $this->regime($parameters),
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
