<?php

declare(strict_types=1);

namespace App\Strategy\Application;

use App\Strategy\Contracts\StrategyInterface;
use Illuminate\Support\Arr;
use App\Strategy\Research\BollingerMeanReversionStrategy;
use App\Strategy\Research\DonchianBreakoutStrategy;
use App\Strategy\Research\MarketRegimeClassifier;
use App\Strategy\Research\MomentumStrategy;
use App\Strategy\Research\RegimeSwitchingStrategy;
use App\Strategy\Research\SupportResistanceBounceStrategy;
use App\Strategy\Research\SupportResistanceBreakoutStrategy;
use App\Strategy\Research\TrendPullbackStrategy;

final readonly class StrategyRegistry
{
    /** @param list<StrategyInterface> $strategies */
    public function __construct(private array $strategies, private LevelInteractionAnalyzer $levels, private LevelProbabilityService $probabilities) {}

    public function all(): array
    {
        return $this->strategies;
    }

    public function get(string $id): StrategyInterface
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->id() === $id) {
                return $strategy;
            }
        }
        throw new \InvalidArgumentException("Unknown strategy: {$id}");
    }

    public function make(string $id, array $parameters): StrategyInterface
    {
        if ($id === 'baseline') {
            $overrides = Arr::undot($parameters);
            unset($overrides['_research_strategy_id'], $overrides['_research_parameters'], $overrides['_research_optimization']);
            $config = array_replace_recursive((array) config('strategy'), $overrides);
            if (isset($parameters['version'])) {
                $config['version'] = (string) $parameters['version'];
            }

            return new \App\Strategy\Research\BaselineStrategy($config);
        }

        return match ($id) {
            'trend_pullback' => new TrendPullbackStrategy($parameters),
            'bollinger_mean_reversion' => new BollingerMeanReversionStrategy($parameters),
            'donchian_atr_breakout' => new DonchianBreakoutStrategy($parameters),
            'regime_switching' => new RegimeSwitchingStrategy(
                $parameters,
                new MarketRegimeClassifier($parameters),
                $this->get('trend_pullback'),
                $this->get('donchian_atr_breakout'),
                $this->get('bollinger_mean_reversion'),
            ),
            'momentum' => new MomentumStrategy($parameters),
            'support_resistance_bounce' => new SupportResistanceBounceStrategy($parameters, $this->levels, $this->probabilities),
            'support_resistance_breakout' => new SupportResistanceBreakoutStrategy($parameters, $this->levels, $this->probabilities),
            default => throw new \InvalidArgumentException("Unknown strategy: {$id}"),
        };
    }

    public function metadata(): array
    {
        return array_map(fn (StrategyInterface $s) => ['id' => $s->id(), 'version' => $s->version(), 'description' => $s->description(),
            'timeframes' => ['trend' => '1h', 'setup' => '30m', 'entry' => '15m', 'confirmation' => '5m'],
            'configuration_hash' => $this->hash($s->parameters()), 'parameters' => $s->parameters()], $this->strategies);
    }

    private function hash(array $parameters): string
    {
        $sort = function (&$v) use (&$sort): void {
            if (! is_array($v)) {
                return;
            } foreach ($v as &$x) {
                $sort($x);
            } unset($x);
            if (! array_is_list($v)) {
                ksort($v);
            }
        };
        $sort($parameters);

        return hash('sha256', json_encode($parameters, JSON_THROW_ON_ERROR));
    }
}
