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
use App\Strategy\Research\BtcEthLeadLagStrategy;
use App\Strategy\Research\DonchianBreakoutStrategy;
use App\Strategy\Research\FundingOpenInterestSqueezeStrategy;
use App\Strategy\Research\LiquiditySweepReversalStrategy;
use App\Strategy\Research\MarketRegimeClassifier;
use App\Strategy\Research\MomentumStrategy;
use App\Strategy\Research\MultiTimeframeTrendFollowingStrategy;
use App\Strategy\Research\RegimeSwitchingStrategy;
use App\Strategy\Research\SupportResistanceBounceStrategy;
use App\Strategy\Research\SupportResistanceBreakoutStrategy;
use App\Strategy\Research\TrendPullbackStrategy;
use App\Strategy\Research\VolatilityCompressionBreakoutStrategy;
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
        $id = $strategyConfig['_research_strategy_id'] ?? null;
        $peer = $id === 'btc_eth_lead_lag' ? StrategyInputFactory::peerSymbol($symbol) : null;
        $exchange = $strategyConfig['_exchange'] ?? 'bybit';
        if (! in_array($exchange, ['bybit', 'binance'], true)) {
            throw new \InvalidArgumentException('Unsupported backtest exchange.');
        }
        $input = $this->inputs->make($exchange, 'linear', $symbol, $at, $peer);
        if ($id === null || $id === 'baseline') {
            $config = $strategyConfig;
            unset($config['_research_strategy_id']);
            unset($config['_exchange']);
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
            'funding_open_interest_squeeze' => new FundingOpenInterestSqueezeStrategy($parameters),
            'liquidity_sweep_reversal' => new LiquiditySweepReversalStrategy($parameters),
            'volatility_compression_breakout' => new VolatilityCompressionBreakoutStrategy($parameters),
            'btc_eth_lead_lag' => new BtcEthLeadLagStrategy($parameters),
            'multi_timeframe_trend_following' => new MultiTimeframeTrendFollowingStrategy($parameters),
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
