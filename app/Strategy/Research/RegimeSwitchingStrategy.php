<?php

declare(strict_types=1);

namespace App\Strategy\Research;

use App\Strategy\Contracts\StrategyInterface;
use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\StrategyInput;
use Ramsey\Uuid\Uuid;

final readonly class RegimeSwitchingStrategy implements StrategyInterface
{
    public function __construct(private array $config, private MarketRegimeClassifier $classifier, private TrendPullbackStrategy $trend, private DonchianBreakoutStrategy $breakout, private BollingerMeanReversionStrategy $range) {}

    public function id(): string
    {
        return 'regime_switching';
    }

    public function version(): string
    {
        return (string) $this->config['version'];
    }

    public function description(): string
    {
        return 'Causal regime router: trend strategies, range mean reversion, otherwise SKIP.';
    }

    public function parameters(): array
    {
        return $this->config;
    }

    public function evaluate(StrategyInput $input): SignalDecision
    {
        $decision = match ($this->classifier->classify($input)) {
            'TREND' => ($first = $this->trend->evaluate($input))->side->value !== 'SKIP' ? $first : $this->breakout->evaluate($input),
            'RANGE' => $this->range->evaluate($input),
            default => $this->skip($input),
        };
        if (($decision->indicatorSnapshot['strategy_id'] ?? null) === $this->id()) {
            return $decision;
        }
        $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'traderbybit:research:'.implode('|', [$input->exchange, $input->category, $input->symbol, $input->decisionTime->format('U.u'), $this->id(), $this->version()]))->toString();

        return new SignalDecision($id, $decision->exchange, $decision->category, $decision->symbol, $decision->timeframe, $decision->decisionTime, $decision->candleCloseTime, $decision->side, $decision->qualityClass, $decision->score, $decision->pricePlan, $decision->modelRiskPercent, $decision->expiresAt, $decision->maximumHoldingMinutes, array_merge(['regime_'.$this->classifier->classify($input)], $decision->reasons), $decision->risks, array_merge($decision->indicatorSnapshot, ['strategy_id' => $this->id()]), $this->version(), $decision->schemaVersion);
    }

    private function skip(StrategyInput $input): SignalDecision
    {
        $cfg = $this->config + ['stop_atr' => '1.5', 'tp1_r' => '1.5', 'tp2_r' => '2.5'];

        return (new class($cfg, $this->classifier->classify($input)) extends ResearchStrategy
        {
            public function __construct(array $config, private string $regime)
            {
                parent::__construct($config);
            }

            public function id(): string
            {
                return 'regime_switching';
            }

            public function description(): string
            {
                return '';
            }

            public function evaluate(StrategyInput $input): SignalDecision
            {
                return $this->skip($input, ['regime_'.$this->regime.'_skip']);
            }
        })->evaluate($input);
    }
}
