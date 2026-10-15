<?php

declare(strict_types=1);

namespace App\Strategy\Research;

use App\Strategy\Contracts\StrategyInterface;
use App\Strategy\Domain\PricePlan;
use App\Strategy\Domain\QualityClass;
use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\SignalSide;
use App\Strategy\Domain\StrategyInput;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateInterval;
use Ramsey\Uuid\Uuid;

abstract readonly class ResearchStrategy implements StrategyInterface
{
    public function __construct(protected array $config) {}

    public function version(): string
    {
        return (string) $this->config['version'];
    }

    public function parameters(): array
    {
        return $this->config;
    }

    protected function preflight(StrategyInput $input): ?SignalDecision
    {
        if ($input->validationErrors !== []) {
            return $this->skip($input, $input->validationErrors);
        }
        foreach ([$input->trend, $input->entry, $input->confirmation] as $state) {
            if (! $state->warmupReady) {
                return $this->skip($input, ['insufficient_warmup']);
            }
            foreach ($state->bars as $bar) {
                if ($bar->closeTime > $input->decisionTime) {
                    return $this->skip($input, ['future_candle_rejected']);
                }
            }
        }
        if ($input->marketContext === null || $input->marketContext->tickSize === '') {
            return $this->skip($input, ['market_context_unavailable']);
        }

        return null;
    }

    protected function emit(StrategyInput $input, SignalSide $side, array $reasons, ?string $targetR = null): SignalDecision
    {
        $bar = $input->entry->latest();
        if ($bar === null || $bar->atr === null || BigDecimal::of($bar->atr)->isZero()) {
            return $this->skip($input, ['atr_unavailable_or_zero']);
        }
        $tick = BigDecimal::of($input->marketContext?->tickSize ?? '0');
        if ($tick->isLessThanOrEqualTo(BigDecimal::zero())) {
            return $this->skip($input, ['tick_size_unavailable']);
        }
        $close = BigDecimal::of($bar->close);
        $atr = BigDecimal::of($bar->atr);
        $zone = $atr->multipliedBy((string) ($this->config['entry_zone_atr'] ?? '0.10'));
        $low = $this->round($close->minus($zone), $tick, RoundingMode::Floor);
        $high = $this->round($close->plus($zone), $tick, RoundingMode::Ceiling);
        $stopDistance = $atr->multipliedBy((string) $this->config['stop_atr']);
        $stop = $this->round($side === SignalSide::Buy ? $close->minus($stopDistance) : $close->plus($stopDistance), $tick, $side === SignalSide::Buy ? RoundingMode::Floor : RoundingMode::Ceiling);
        $risk = $side === SignalSide::Buy ? $high->minus($stop) : $stop->minus($low);
        if ($risk->isLessThanOrEqualTo(BigDecimal::zero())) {
            return $this->skip($input, ['invalid_risk_plan']);
        }
        $r1 = (string) ($targetR ?? $this->config['tp1_r']);
        $r2 = (string) ($this->config['tp2_r'] ?? $r1);
        $anchor = $side === SignalSide::Buy ? $high : $low;
        $tp1 = $this->round($side === SignalSide::Buy ? $anchor->plus($risk->multipliedBy($r1)) : $anchor->minus($risk->multipliedBy($r1)), $tick, $side === SignalSide::Buy ? RoundingMode::Ceiling : RoundingMode::Floor);
        $tp2 = $this->round($side === SignalSide::Buy ? $anchor->plus($risk->multipliedBy($r2)) : $anchor->minus($risk->multipliedBy($r2)), $tick, $side === SignalSide::Buy ? RoundingMode::Ceiling : RoundingMode::Floor);
        if ($tp1->isNegative() || $tp2->isNegative()) {
            return $this->skip($input, ['invalid_risk_plan']);
        }

        return $this->decision($input, $side, QualityClass::B, 70, new PricePlan((string) $low, (string) $high, (string) $stop, (string) $tp1, (string) $tp2, $r1, $r2), $reasons);
    }

    protected function skip(StrategyInput $input, array $reasons): SignalDecision
    {
        return $this->decision($input, SignalSide::Skip, null, 0, null, $reasons);
    }

    private function decision(StrategyInput $input, SignalSide $side, ?QualityClass $quality, int $score, ?PricePlan $plan, array $reasons): SignalDecision
    {
        $identity = implode('|', [$input->exchange, $input->category, $input->symbol, $input->decisionTime->format('U.u'), $this->id(), $this->version()]);
        $bar = $input->entry->latest();
        $snapshot = ['strategy_id' => $this->id(), '15m' => $bar === null ? null : ['close' => $bar->close, 'atr_14' => $bar->atr, 'rsi_14' => $bar->rsi, 'adx_14' => $bar->adx, 'relative_volume' => $bar->relativeVolume]];

        return new SignalDecision(Uuid::uuid5(Uuid::NAMESPACE_URL, 'traderbybit:research:'.$identity)->toString(), $input->exchange, $input->category, $input->symbol, '15', $input->decisionTime, $input->decisionTime, $side, $quality, $score, $plan, $side === SignalSide::Skip ? null : (string) ($this->config['model_risk_percent'] ?? '0.25'), $input->decisionTime->add(new DateInterval('PT30M')), (int) ($this->config['maximum_holding_minutes'] ?? 240), $reasons, [], $snapshot, $this->version(), '1.0.0');
    }

    protected function d(?string $value): ?BigDecimal
    {
        return $value === null ? null : BigDecimal::of($value);
    }

    protected function gt(?string $a, ?string $b): bool
    {
        return $a !== null && $b !== null && BigDecimal::of($a)->isGreaterThan($b);
    }

    protected function lt(?string $a, ?string $b): bool
    {
        return $a !== null && $b !== null && BigDecimal::of($a)->isLessThan($b);
    }

    private function round(BigDecimal $value, BigDecimal $tick, RoundingMode $mode): BigDecimal
    {
        return $value->dividedBy($tick, 0, $mode)->multipliedBy($tick);
    }
}
