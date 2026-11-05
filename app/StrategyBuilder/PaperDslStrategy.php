<?php

declare(strict_types=1);

namespace App\StrategyBuilder;

use App\Strategy\Contracts\StrategyInterface;
use App\Strategy\Domain\PricePlan;
use App\Strategy\Domain\QualityClass;
use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\SignalSide;
use App\Strategy\Domain\StrategyInput;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Ramsey\Uuid\Uuid;

final readonly class PaperDslStrategy implements StrategyInterface
{
    public function __construct(private array $definition, private string $engineChecksum, private bool $enabled, private ?DateTimeImmutable $activeFrom, private ForwardEvaluator $go) {}

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function id(): string
    {
        return $this->definition['id'];
    }

    public function version(): string
    {
        return (string) $this->definition['version'];
    }

    public function description(): string
    {
        return 'Owner-created deterministic Go DSL v'.$this->definition['schema_version'].' strategy.';
    }

    public function parameters(): array
    {
        return ['version' => $this->version(), '_dsl_definition' => $this->definition, '_dsl_engine_checksum' => $this->engineChecksum];
    }

    public function evaluate(StrategyInput $input): SignalDecision
    {
        $side = SignalSide::Skip;
        $plan = null;
        $reasons = [];
        $snapshot = ['strategy_id' => $this->id(), 'definition' => $this->definition, 'engine_checksum' => $this->engineChecksum];
        if (! $this->enabled || $this->activeFrom === null || $input->decisionTime < $this->activeFrom) {
            $reasons[] = 'strategy_not_owner_active_at_decision';
        } elseif ($input->validationErrors !== []) {
            $reasons = $input->validationErrors;
        } elseif ($input->category !== 'linear' || ! in_array($input->exchange, $this->definition['exchanges'], true) || ! in_array($input->symbol, $this->definition['symbols'], true)) {
            $reasons[] = 'unsupported_strategy_scope';
        } elseif (EvaluationProfile::paperBlockers($this->definition) !== []) {
            $reasons = EvaluationProfile::paperBlockers($this->definition);
        } elseif ($input->marketContext === null || $input->marketContext->observedAt > $input->decisionTime || $input->decisionTime->getTimestamp() - $input->marketContext->observedAt->getTimestamp() > (int) config('strategy.freshness.market_context_max_age_seconds', 300)) {
            $reasons[] = 'market_context_unavailable_or_stale';
        } else {
            try {
                $result = $this->go->evaluate($this->definition, $input->exchange, $input->symbol, $input->decisionTime, $this->engineChecksum);
                $signal = $result['signals'][0] ?? null;
                if ($signal === null) {
                    $reasons[] = 'dsl_entry_conditions_not_met';
                } else {
                    $entry = $result['reference_price'];
                    if ($input->entry->latest() === null || ! BigDecimal::of($input->entry->latest()->close)->isEqualTo($entry)) {
                        throw new \RuntimeException('Decision reference changed.');
                    }
                    $side = SignalSide::from($signal['side']);
                    $snapshot['15m'] = ['close' => $entry, 'atr_14' => (string) BigDecimal::of($entry)->minus($signal['stop_loss'])->abs()->dividedBy((string) $this->definition['stop_atr'], 24, RoundingMode::HalfEven)];
                    $rr = (string) BigDecimal::of((string) $this->definition['take_atr'])->dividedBy((string) $this->definition['stop_atr'], 12, RoundingMode::HalfEven);
                    $plan = new PricePlan($entry, $entry, $signal['stop_loss'], $signal['take_profit'], $signal['take_profit'], $rr, $rr);
                    $reasons[] = 'go_dsl_v2_closed_candle_rules_matched';
                }
            } catch (\Throwable $e) {
                $side = SignalSide::Skip;
                $plan = null;
                $reasons = ['go_dsl_evaluation_unavailable'];
            }
        }

        return new SignalDecision(
            id: Uuid::uuid5(Uuid::NAMESPACE_URL, implode('|', ['vector:dsl', $this->id(), $this->version(), $input->exchange, $input->symbol, $input->decisionTime->format('U.u')]))->toString(),
            exchange: $input->exchange, category: $input->category, symbol: $input->symbol, timeframe: $this->definition['timeframe'], decisionTime: $input->decisionTime, candleCloseTime: $input->decisionTime,
            side: $side, qualityClass: $side === SignalSide::Skip ? null : QualityClass::A, score: $side === SignalSide::Skip ? 0 : 100, pricePlan: $plan,
            modelRiskPercent: $side === SignalSide::Skip ? null : (string) $this->definition['risk_percent'], expiresAt: $input->decisionTime->modify('+15 minutes'), maximumHoldingMinutes: $this->definition['timeout_bars'] * (int) $this->definition['timeframe'],
            reasons: $reasons, risks: ['research_paper_only'], indicatorSnapshot: $snapshot, strategyVersion: $this->version(), schemaVersion: '1.0.0',
        );
    }
}
