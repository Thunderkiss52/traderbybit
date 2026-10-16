<?php

declare(strict_types=1);

namespace App\Strategy\Application;

use App\Strategy\Domain\SignalSide;
use App\Strategy\Domain\StrategyInput;
use Brick\Math\BigDecimal;

final readonly class LevelInteractionAnalyzer
{
    public function __construct(private array $config) {}

    public function analyze(StrategyInput $input): array
    {
        $now = $input->entry->latest();
        $previous = $input->entry->previous();
        if ($now === null || $previous === null || $now->atr === null || $this->d($now->atr)->isZero()) {
            return [];
        }

        $buyMomentum = 0;
        $sellMomentum = 0;
        if ($now->rsi !== null) {
            $buyMomentum += $this->gte($now->rsi, '52') ? 10 : 0;
            $sellMomentum += $this->lte($now->rsi, '48') ? 10 : 0;
        }
        if ($now->macdHistogram !== null) {
            $buyMomentum += $this->gt($now->macdHistogram, '0') ? 15 : 0;
            $sellMomentum += $this->lt($now->macdHistogram, '0') ? 15 : 0;
            if ($previous->macdHistogram !== null) {
                $buyMomentum += $this->gt($now->macdHistogram, $previous->macdHistogram) ? 10 : 0;
                $sellMomentum += $this->lt($now->macdHistogram, $previous->macdHistogram) ? 10 : 0;
            }
        }
        $buyMomentum += $this->gt($now->close, $now->open) ? 10 : 0;
        $sellMomentum += $this->lt($now->close, $now->open) ? 10 : 0;
        if ($now->plusDi !== null && $now->minusDi !== null) {
            $buyMomentum += $this->gt($now->plusDi, $now->minusDi) ? 10 : 0;
            $sellMomentum += $this->gt($now->minusDi, $now->plusDi) ? 10 : 0;
        }

        $volume = $now->relativeVolume === null ? 0 : ($this->gte($now->relativeVolume, '1.50') ? 15 : ($this->gte($now->relativeVolume, '1.00') ? 8 : 0));
        $trendStrength = $now->adx !== null && $this->gte($now->adx, '18') ? 10 : 0;
        $buyEvidence = 20 + $buyMomentum + $volume + $trendStrength;
        $sellEvidence = 20 + $sellMomentum + $volume + $trendStrength;
        $buyProbability = min(95, max(5, (int) round(100 * $buyEvidence / ($buyEvidence + $sellEvidence))));
        $sellProbability = 100 - $buyProbability;
        $atr = $this->d($now->atr);
        $proximity = $atr->multipliedBy((string) $this->config['proximity_atr']);
        $buffer = $atr->multipliedBy((string) $this->config['breakout_buffer_atr']);
        $candidates = [];

        if ($previous->resistance !== null) {
            $resistance = $this->d($previous->resistance);
            if ($this->d($now->close)->isGreaterThan($resistance->plus($buffer))) {
                $candidates[] = ['event' => 'resistance_breakout', 'side' => SignalSide::Buy->value, 'probability' => $buyProbability];
            } elseif ($this->d($now->high)->isGreaterThanOrEqualTo($resistance->minus($proximity)) && $this->d($now->close)->isLessThan($resistance) && $this->lt($now->close, $now->open)) {
                $candidates[] = ['event' => 'resistance_rejection', 'side' => SignalSide::Sell->value, 'probability' => $sellProbability];
            }
        }
        if ($previous->support !== null) {
            $support = $this->d($previous->support);
            if ($this->d($now->close)->isLessThan($support->minus($buffer))) {
                $candidates[] = ['event' => 'support_breakdown', 'side' => SignalSide::Sell->value, 'probability' => $sellProbability];
            } elseif ($this->d($now->low)->isLessThanOrEqualTo($support->plus($proximity)) && $this->d($now->close)->isGreaterThan($support) && $this->gt($now->close, $now->open)) {
                $candidates[] = ['event' => 'support_rejection', 'side' => SignalSide::Buy->value, 'probability' => $buyProbability];
            }
        }
        usort($candidates, static fn (array $left, array $right): int => $right['probability'] <=> $left['probability']);
        $selected = $candidates[0] ?? null;

        return [
            'support' => $previous->support,
            'resistance' => $previous->resistance,
            'momentum_side' => $buyMomentum === $sellMomentum ? 'NEUTRAL' : ($buyMomentum > $sellMomentum ? 'BUY' : 'SELL'),
            'momentum_strength' => max($buyMomentum, $sellMomentum),
            'resistance_breakout_probability_percent' => $buyProbability,
            'resistance_rejection_probability_percent' => $sellProbability,
            'support_breakdown_probability_percent' => $sellProbability,
            'support_rejection_probability_percent' => $buyProbability,
            'selected_event' => $selected['event'] ?? null,
            'selected_side' => $selected['side'] ?? null,
            'selected_probability_percent' => $selected['probability'] ?? null,
            'model' => 'deterministic_rule_estimate_not_calibrated_probability',
        ];
    }

    private function d(string $value): BigDecimal
    {
        return BigDecimal::of($value);
    }

    private function gt(string $left, string $right): bool
    {
        return $this->d($left)->isGreaterThan($right);
    }

    private function gte(string $left, string $right): bool
    {
        return $this->d($left)->isGreaterThanOrEqualTo($right);
    }

    private function lt(string $left, string $right): bool
    {
        return $this->d($left)->isLessThan($right);
    }

    private function lte(string $left, string $right): bool
    {
        return $this->d($left)->isLessThanOrEqualTo($right);
    }
}
