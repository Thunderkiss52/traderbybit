<?php

declare(strict_types=1);

namespace App\Strategy\Application;

use App\Strategy\Contracts\StrategyInterface;
use App\Strategy\Research\BollingerMeanReversionStrategy;
use App\Strategy\Research\DonchianBreakoutStrategy;
use App\Strategy\Research\MarketRegimeClassifier;
use App\Strategy\Research\RegimeSwitchingStrategy;
use App\Strategy\Research\TrendPullbackStrategy;

final readonly class StrategyRegistry
{
    /** @param list<StrategyInterface> $strategies */
    public function __construct(private array $strategies) {}

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
            return $this->get($id);
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
            default => throw new \InvalidArgumentException("Unknown strategy: {$id}"),
        };
    }

    public function metadata(): array
    {
        return array_map(fn (StrategyInterface $s) => ['id' => $s->id(), 'version' => $s->version(), 'description' => $s->description(), 'configuration_hash' => $this->hash($s->parameters()), 'parameters' => $s->parameters()], $this->strategies);
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
