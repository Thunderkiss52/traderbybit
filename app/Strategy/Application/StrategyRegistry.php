<?php

declare(strict_types=1);

namespace App\Strategy\Application;

use App\PaperTrading\Application\PaperTopologySynchronizer;
use App\Strategy\Contracts\StrategyInterface;
use App\Strategy\Research\BaselineStrategy;
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
use App\StrategyBuilder\ForwardEvaluator;
use App\StrategyBuilder\PaperDslStrategy;
use App\StrategyBuilder\PrimaryStrategies;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

final class StrategyRegistry
{
    /** @var array<string, StrategyInterface> */
    private array $dynamicStrategies = [];

    /** @param list<StrategyInterface> $strategies */
    public function __construct(private array $strategies, private LevelInteractionAnalyzer $levels, private LevelProbabilityService $probabilities) {}

    public function register(StrategyInterface $strategy): void
    {
        $this->dynamicStrategies[$strategy->id()] = $strategy;

        if (Schema::hasTable('paper_accounts')) {
            try {
                app(PaperTopologySynchronizer::class)->sync(true);
            } catch (\Throwable $e) {
                Log::error('Topology synchronization failed during strategy registration', [
                    'strategy_id' => $strategy->id(),
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }
    }

    /** @param list<StrategyInterface> $strategies */
    public function registerBatch(array $strategies): void
    {
        foreach ($strategies as $strategy) {
            $this->dynamicStrategies[$strategy->id()] = $strategy;
        }

        if (Schema::hasTable('paper_accounts')) {
            try {
                app(PaperTopologySynchronizer::class)->sync(true);
            } catch (\Throwable $e) {
                Log::error('Topology synchronization failed during batch strategy registration', [
                    'count' => count($strategies),
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }
    }

    public function all(): array
    {
        $all = array_merge(
            $this->strategies,
            array_values($this->dynamicStrategies),
            array_map(fn ($row) => $this->dsl($row), PrimaryStrategies::registrations())
        );

        $unique = [];
        foreach ($all as $strategy) {
            $unique[$strategy->id()] = $strategy;
        }

        return array_values($unique);
    }

    /** Registered executable identities only; library methods and drafts are not strategies. */
    public function ids(): array
    {
        return array_map(static fn (StrategyInterface $strategy): string => $strategy->id(), $this->all());
    }

    public function has(string $id): bool
    {
        return in_array($id, $this->ids(), true);
    }

    public function get(string $id): StrategyInterface
    {
        if (isset($this->dynamicStrategies[$id])) {
            return $this->dynamicStrategies[$id];
        }

        foreach ($this->strategies as $strategy) {
            if ($strategy->id() === $id) {
                return $strategy;
            }
        }

        foreach (PrimaryStrategies::registrations() as $row) {
            if ($row->strategy_id === $id) {
                return $this->dsl($row);
            }
        }

        throw new \InvalidArgumentException("Unknown strategy: {$id}");
    }

    public function make(string $id, array $parameters): StrategyInterface
    {
        if (isset($this->dynamicStrategies[$id])) {
            return $this->dynamicStrategies[$id];
        }

        foreach (PrimaryStrategies::registrations() as $row) {
            if ($row->strategy_id === $id) {
                $strategy = $this->dsl($row);
                if (self::hash($parameters) !== self::hash($strategy->parameters())) {
                    throw new \InvalidArgumentException('Strategy parameters are immutable; publish and approve another version.');
                }

                return $strategy;
            }
        }
        if ($id === 'baseline') {
            $overrides = Arr::undot($parameters);
            unset($overrides['_research_strategy_id'], $overrides['_research_parameters'], $overrides['_research_optimization']);
            $config = array_replace_recursive((array) config('strategy'), $overrides);
            if (isset($parameters['version'])) {
                $config['version'] = (string) $parameters['version'];
            }

            return new BaselineStrategy($config);
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
            'funding_open_interest_squeeze' => new FundingOpenInterestSqueezeStrategy($parameters),
            'liquidity_sweep_reversal' => new LiquiditySweepReversalStrategy($parameters),
            'volatility_compression_breakout' => new VolatilityCompressionBreakoutStrategy($parameters),
            'btc_eth_lead_lag' => new BtcEthLeadLagStrategy($parameters),
            'multi_timeframe_trend_following' => new MultiTimeframeTrendFollowingStrategy($parameters),
            default => throw new \InvalidArgumentException("Unknown strategy: {$id}"),
        };
    }

    public function metadata(): array
    {
        $signalOnly = (array) config('research.forward_paper.signal_only_strategies', []);

        return array_map(fn (StrategyInterface $s) => ['id' => $s->id(), 'version' => $s->version(), 'description' => $s->description(),
            'timeframes' => ['trend' => '1h', 'setup' => '30m', 'entry' => '15m', 'confirmation' => '5m'],
            'execution_eligible' => ! in_array($s->id(), $signalOnly, true) && (! $s instanceof PaperDslStrategy || $s->isEnabled()),
            'configuration_hash' => $this->hash($s->parameters()), 'parameters' => $s->parameters()], $this->all());
    }

    public function configurationHash(string $id): string
    {
        return self::hash($this->get($id)->parameters());
    }

    private function dsl(object $row): StrategyInterface
    {
        return new PaperDslStrategy(json_decode($row->definition, true, flags: JSON_THROW_ON_ERROR), (string) $row->engine_checksum, (bool) $row->enabled, $row->active_from === null ? null : new \DateTimeImmutable($row->active_from), app(ForwardEvaluator::class));
    }

    public static function hash(array $parameters): string
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
