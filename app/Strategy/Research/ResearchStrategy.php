<?php

declare(strict_types=1);

namespace App\Strategy\Research;

use App\Strategy\Contracts\StrategyInterface;
use App\Strategy\Domain\BarState;
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
        foreach ([$input->trend, $input->setup, $input->entry, $input->confirmation] as $state) {
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

    /** @param list<string> $risks @param array<string, mixed> $extraSnapshot */
    protected function emit(StrategyInput $input, SignalSide $side, array $reasons, ?string $targetR = null, int $score = 70, array $risks = [], array $extraSnapshot = []): SignalDecision
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

        $quality = $score >= 85 ? QualityClass::A : ($score >= 70 ? QualityClass::B : QualityClass::C);

        return $this->decision($input, $side, $quality, $score, new PricePlan((string) $low, (string) $high, (string) $stop, (string) $tp1, (string) $tp2, $r1, $r2), $reasons, $risks, $extraSnapshot);
    }

    protected function setupAligned(StrategyInput $input, SignalSide $side): bool
    {
        $setup = $input->setup->latest();
        if ($setup === null || $setup->emaFast === null || $setup->emaSlow === null) {
            return false;
        }

        return $side === SignalSide::Buy
            ? $this->gt($setup->close, $setup->emaFast) && $this->gt($setup->emaFast, $setup->emaSlow)
            : $this->lt($setup->close, $setup->emaFast) && $this->lt($setup->emaFast, $setup->emaSlow);
    }

    protected function trendAligned(StrategyInput $input, SignalSide $side): bool
    {
        $trend = $input->trend->latest();
        if ($trend === null || $trend->emaFast === null || $trend->emaSlow === null) {
            return false;
        }

        return $side === SignalSide::Buy
            ? $this->gt($trend->close, $trend->emaFast) && $this->gt($trend->emaFast, $trend->emaSlow)
            : $this->lt($trend->close, $trend->emaFast) && $this->lt($trend->emaFast, $trend->emaSlow);
    }

    protected function confirmationAligned(StrategyInput $input, SignalSide $side): bool
    {
        $now = $input->confirmation->latest();
        $previous = $input->confirmation->previous();
        if ($now === null || $previous === null || $now->emaEntry === null || $now->rsi === null
            || $now->macdHistogram === null || $previous->macdHistogram === null) {
            return false;
        }

        return $side === SignalSide::Buy
            ? $this->gt($now->close, $now->emaEntry) && $this->gte($now->rsi, '50') && $this->gte($now->macdHistogram, $previous->macdHistogram)
            : $this->lt($now->close, $now->emaEntry) && $this->lte($now->rsi, '50') && $this->lte($now->macdHistogram, $previous->macdHistogram);
    }

    protected function confirmationMode(): string
    {
        $mode = strtoupper((string) ($this->config['confirmation_mode'] ?? 'STRICT'));

        return in_array($mode, ['STRICT', 'WEIGHTED', 'ADAPTIVE'], true) ? $mode : 'STRICT';
    }

    protected function acceptsWeightedScore(int $score, array $mandatory): bool
    {
        if (in_array(false, $mandatory, true)) {
            return false;
        }

        return $score >= (int) ($this->config['minimum_score'] ?? 70);
    }

    protected function skip(StrategyInput $input, array $reasons, int $score = 0): SignalDecision
    {
        return $this->decision($input, SignalSide::Skip, null, $score, null, $reasons);
    }

    private function decision(StrategyInput $input, SignalSide $side, ?QualityClass $quality, int $score, ?PricePlan $plan, array $reasons, array $risks = [], array $extraSnapshot = []): SignalDecision
    {
        $identity = implode('|', [$input->exchange, $input->category, $input->symbol, $input->decisionTime->format('U.u'), $this->id(), $this->version()]);
        $bar = static fn (?BarState $value): ?array => $value === null ? null : [
            'close_time' => $value->closeTime->format('Y-m-d\TH:i:s.v\Z'),
            'close' => $value->close,
            'ema_20' => $value->emaEntry,
            'ema_50' => $value->emaFast,
            'ema_200' => $value->emaSlow,
            'rsi_14' => $value->rsi,
            'macd_histogram' => $value->macdHistogram,
            'atr_14' => $value->atr,
            'adx_14' => $value->adx,
            'plus_di' => $value->plusDi,
            'minus_di' => $value->minusDi,
            'relative_volume' => $value->relativeVolume,
            'support' => $value->support,
            'resistance' => $value->resistance,
        ];
        $snapshot = [
            'strategy_id' => $this->id(),
            ...$extraSnapshot,
            'confirmation_mode' => $this->confirmationMode(),
            '1h' => $bar($input->trend->latest()),
            '30m' => $bar($input->setup->latest()),
            '15m' => $bar($input->entry->latest()),
            '5m' => $bar($input->confirmation->latest()),
        ];

        return new SignalDecision(Uuid::uuid5(Uuid::NAMESPACE_URL, 'traderbybit:research:'.$identity)->toString(), $input->exchange, $input->category, $input->symbol, '15', $input->decisionTime, $input->decisionTime, $side, $quality, $score, $plan, $side === SignalSide::Skip ? null : (string) ($this->config['model_risk_percent'] ?? '0.25'), $input->decisionTime->add(new DateInterval('PT30M')), (int) ($this->config['maximum_holding_minutes'] ?? 240), $reasons, $risks, $snapshot, $this->version(), '1.0.0');
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

    protected function gte(?string $a, ?string $b): bool
    {
        return $a !== null && $b !== null && BigDecimal::of($a)->isGreaterThanOrEqualTo($b);
    }

    protected function lte(?string $a, ?string $b): bool
    {
        return $a !== null && $b !== null && BigDecimal::of($a)->isLessThanOrEqualTo($b);
    }

    private function round(BigDecimal $value, BigDecimal $tick, RoundingMode $mode): BigDecimal
    {
        return $value->dividedBy($tick, 0, $mode)->multipliedBy($tick);
    }
}
