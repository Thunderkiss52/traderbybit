<?php

declare(strict_types=1);

namespace App\Strategy\Application;

use App\Strategy\Domain\BarState;
use App\Strategy\Domain\SignalSide;
use App\Strategy\Domain\StrategyInput;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

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
        $buyEvidenceScore = min(95, max(5, (int) round(100 * $buyEvidence / ($buyEvidence + $sellEvidence))));
        $sellEvidenceScore = 100 - $buyEvidenceScore;
        $atr = $this->d($now->atr);
        $zoneHalfWidth = $atr->multipliedBy((string) $this->config['zone_half_width_atr']);
        $proximity = $atr->multipliedBy((string) $this->config['proximity_atr']);
        $buffer = $atr->multipliedBy((string) $this->config['breakout_buffer_atr']);
        $candidates = [];

        if ($previous->resistance !== null) {
            $resistance = $this->d($previous->resistance);
            if ($this->d($now->close)->isGreaterThan($resistance->plus($buffer))) {
                $candidates[] = ['event' => 'resistance_breakout', 'side' => SignalSide::Buy->value, 'evidence_score' => $buyEvidenceScore];
            } elseif ($this->d($now->high)->isGreaterThanOrEqualTo($resistance->minus($proximity)) && $this->d($now->close)->isLessThan($resistance) && $this->lt($now->close, $now->open)) {
                $candidates[] = ['event' => 'resistance_rejection', 'side' => SignalSide::Sell->value, 'evidence_score' => $sellEvidenceScore];
            }
        }
        if ($previous->support !== null) {
            $support = $this->d($previous->support);
            if ($this->d($now->close)->isLessThan($support->minus($buffer))) {
                $candidates[] = ['event' => 'support_breakdown', 'side' => SignalSide::Sell->value, 'evidence_score' => $sellEvidenceScore];
            } elseif ($this->d($now->low)->isLessThanOrEqualTo($support->plus($proximity)) && $this->d($now->close)->isGreaterThan($support) && $this->gt($now->close, $now->open)) {
                $candidates[] = ['event' => 'support_rejection', 'side' => SignalSide::Buy->value, 'evidence_score' => $buyEvidenceScore];
            }
        }
        usort($candidates, static fn (array $left, array $right): int => $right['evidence_score'] <=> $left['evidence_score']);
        $selected = $candidates[0] ?? null;
        $supportZone = $previous->support === null ? null : $this->zone($this->d($previous->support), $zoneHalfWidth, $previous->supportStrength, $now);
        $resistanceZone = $previous->resistance === null ? null : $this->zone($this->d($previous->resistance), $zoneHalfWidth, $previous->resistanceStrength, $now);
        $rangePosition = null;
        if ($previous->support !== null && $previous->resistance !== null) {
            $width = $this->d($previous->resistance)->minus($previous->support);
            if ($width->isGreaterThan(BigDecimal::zero())) {
                $rangePosition = (string) $this->d($now->close)->minus($previous->support)->dividedBy($width, 8, RoundingMode::HalfEven);
            }
        }

        return [
            'support' => $previous->support,
            'resistance' => $previous->resistance,
            'support_zone' => $supportZone,
            'resistance_zone' => $resistanceZone,
            'range_position' => $rangePosition,
            'momentum_side' => $buyMomentum === $sellMomentum ? 'NEUTRAL' : ($buyMomentum > $sellMomentum ? 'BUY' : 'SELL'),
            'momentum_strength' => max($buyMomentum, $sellMomentum),
            'bullish_directional_evidence_score' => $buyEvidenceScore,
            'bearish_directional_evidence_score' => $sellEvidenceScore,
            'selected_event' => $selected['event'] ?? null,
            'selected_side' => $selected['side'] ?? null,
            'selected_evidence_score' => $selected['evidence_score'] ?? null,
            'probability_status' => 'INSUFFICIENT_DATA',
            'model' => 'deterministic_interaction_evidence_v1',
        ];
    }

    private function zone(BigDecimal $center, BigDecimal $halfWidth, int $strength, BarState $now): array
    {
        return [
            'lower' => (string) $center->minus($halfWidth),
            'upper' => (string) $center->plus($halfWidth),
            'center' => (string) $center,
            'strength' => $strength,
            'atr_normalized_half_width' => (string) $this->config['zone_half_width_atr'],
            'distance_atr' => (string) $this->d($now->close)->minus($center)->abs()->dividedBy($now->atr ?? '1', 8, RoundingMode::HalfEven),
            'timeframe' => '15',
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
