<?php

declare(strict_types=1);

namespace App\Research\Domain\SlippageAttribution;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/**
 * PROFIT-1 / P-1 — realized-scope slippage allocation (§7, partial-close sanity).
 *
 * The full-fill execution population and the realized counterfactual are two
 * different populations and must never be mixed:
 *
 *  - a fully closed position realizes its whole slippage drag;
 *  - a partially closed position realizes the entry drag proportionally to the
 *    closed volume and realizes its exit drag in full (exits only ever close
 *    volume);
 *  - a still open position realizes no entry drag at all, because the entry
 *    slippage is embedded in the entry price and therefore lives in the
 *    unrealized PnL, not in the realized net PnL.
 *
 * Realized PnL scope for partial closes is deliberately NOT inferred here.
 * `virtual_positions.net_pnl` is realized-only (gross realized on the closed
 * quantity + funding - fees), but for a partially closed position it still
 * carries the entry fee of the *initial* quantity and funding accrued on the
 * remaining quantity. The persisted position snapshot does not separate the
 * closed-quantity share of those costs, so a partial-close realized-scope Net
 * PnL cannot be established without inventing a split. In that case the
 * counterfactual stays NULL and the status is PARTIAL_CLOSE_UNRESOLVED. The
 * proportional *drag* allocation is still emitted because it is a pure
 * quantity-based attribution, not a PnL figure.
 *
 * The realized-scope counterfactual is therefore
 *     realized_scope_net_pnl + realized_allocated_slippage_drag
 * and the full-fill figure is reported separately with an explicit
 * NOT_VERIFIED_SCOPE_MISMATCH status. Slippage is never deducted twice: it is
 * an attribution of the realized drag, never a second cost.
 */
final class SlippageAllocation
{
    public const STATUS_COMPUTED = 'COMPUTED';

    public const STATUS_COMPUTED_RECONCILED = 'COMPUTED_RECONCILED';

    public const STATUS_INSUFFICIENT_DATA = 'INSUFFICIENT_DATA';

    public const STATUS_PARTIAL_CLOSE_UNRESOLVED = 'PARTIAL_CLOSE_UNRESOLVED';

    public const RAW_STATUS_COMPUTED = 'COMPUTED';

    public const RAW_STATUS_NOT_VERIFIED_SCOPE_MISMATCH = 'NOT_VERIFIED_SCOPE_MISMATCH';

    public const RAW_STATUS_INSUFFICIENT_DATA = 'INSUFFICIENT_DATA';

    public const SCOPE_ESTABLISHED = 'ESTABLISHED';

    public const SCOPE_PARTIAL_CLOSE_UNRESOLVED = 'PARTIAL_CLOSE_UNRESOLVED';

    public const SCOPE_POPULATION_MISMATCH = 'POPULATION_MISMATCH';

    public const SCOPE_INVALID_POSITION_DATA = 'INVALID_POSITION_DATA';

    private const SCALE = 12;

    /**
     * @param  list<array{position_id:string,initial_quantity:string,remaining_quantity:string,net_pnl:string,entry_slippage_usdt:string,exit_slippage_usdt:string}>  $positions
     * @param  int  $expectedPopulationSize  number of positions the caller expects to reconcile against.
     * @return array<string, mixed>
     */
    public function allocate(array $positions, int $expectedPopulationSize): array
    {
        $populationMatch = $expectedPopulationSize > 0 && count($positions) === $expectedPopulationSize;

        $realizedNetPnl = BigDecimal::zero();
        $realizedDrag = BigDecimal::zero();
        $openDrag = BigDecimal::zero();
        $fullFillDrag = BigDecimal::zero();
        $openNetPnl = BigDecimal::zero();

        $closedCount = 0;
        $partialCount = 0;
        $openCount = 0;
        $invalidCount = 0;

        foreach ($positions as $position) {
            $initial = $this->decimal($position, 'initial_quantity');
            $remaining = $this->decimal($position, 'remaining_quantity');
            $netPnl = $this->decimal($position, 'net_pnl');
            $entryDrag = $this->decimal($position, 'entry_slippage_usdt');
            $exitDrag = $this->decimal($position, 'exit_slippage_usdt');
            $positionId = isset($position['position_id']) ? trim((string) $position['position_id']) : '';

            if (
                $initial === null
                || $remaining === null
                || $netPnl === null
                || $entryDrag === null
                || $exitDrag === null
                || $positionId === ''
                || $initial->isLessThanOrEqualTo(BigDecimal::zero())
                || $remaining->isNegative()
                || $remaining->isGreaterThan($initial)
                || $entryDrag->isNegative()
                || $exitDrag->isNegative()
            ) {
                // Invalid rows never contribute to any total and can never be
                // part of a verified counterfactual.
                $invalidCount++;

                continue;
            }

            $fullFillDrag = $fullFillDrag->plus($entryDrag)->plus($exitDrag);

            $closedQuantity = $initial->minus($remaining);

            if ($closedQuantity->isZero()) {
                // Still open: the entry slippage is unrealized, the exit drag is zero by construction.
                $openCount++;
                $openNetPnl = $openNetPnl->plus($netPnl);
                $openDrag = $openDrag->plus($entryDrag);

                continue;
            }

            // Exact proportional allocation: multiply before dividing so the
            // contract fixture yields exact decimals (0.021 * 0.010 / 0.021 = 0.010).
            $realizedEntryDrag = $entryDrag
                ->multipliedBy($closedQuantity)
                ->dividedBy($initial, self::SCALE, RoundingMode::HalfEven);
            $openEntryDrag = $entryDrag->minus($realizedEntryDrag);

            $realizedNetPnl = $realizedNetPnl->plus($netPnl);
            $realizedDrag = $realizedDrag->plus($realizedEntryDrag)->plus($exitDrag);
            $openDrag = $openDrag->plus($openEntryDrag);

            if ($remaining->isZero()) {
                $closedCount++;
            } else {
                $partialCount++;
            }
        }

        $hasScopeMismatch = $partialCount > 0 || $openCount > 0;

        $scopeEstablished = $populationMatch && $invalidCount === 0 && $partialCount === 0;

        $status = match (true) {
            ! $populationMatch || $invalidCount > 0 => self::STATUS_INSUFFICIENT_DATA,
            $partialCount > 0 => self::STATUS_PARTIAL_CLOSE_UNRESOLVED,
            default => self::STATUS_COMPUTED,
        };

        $scopeStatus = match (true) {
            ! $populationMatch => self::SCOPE_POPULATION_MISMATCH,
            $invalidCount > 0 => self::SCOPE_INVALID_POSITION_DATA,
            $partialCount > 0 => self::SCOPE_PARTIAL_CLOSE_UNRESOLVED,
            default => self::SCOPE_ESTABLISHED,
        };

        $scopeReason = match (true) {
            ! $populationMatch => 'Population reconciliation failed: the attributed positions do not match the expected population. The counterfactual is NOT_VERIFIED.',
            $invalidCount > 0 => 'Position quantities or values are invalid (non-numeric data, non-positive initial quantity, negative remainder, or remainder above the initial quantity). The counterfactual is NOT_VERIFIED.',
            $partialCount > 0 => 'Partially closed positions are present. virtual_positions.net_pnl is realized-only but carries the entry fee of the full initial quantity and funding accrued on the remaining quantity; the closed-quantity share of those costs is not persisted. The realized-scope Net PnL and the counterfactual stay unavailable (PARTIAL_CLOSE_UNRESOLVED) rather than assuming the whole net_pnl is realized closed-quantity PnL.',
            default => null,
        };

        $rawStatus = match (true) {
            ! $populationMatch || $invalidCount > 0 => self::RAW_STATUS_INSUFFICIENT_DATA,
            $hasScopeMismatch => self::RAW_STATUS_NOT_VERIFIED_SCOPE_MISMATCH,
            default => self::RAW_STATUS_COMPUTED,
        };

        $note = match (true) {
            ! $populationMatch => 'Population reconciliation failed: the attributed positions do not match the expected population. The counterfactual is NOT_VERIFIED.',
            $invalidCount > 0 => 'One or more positions have invalid quantities or values and were excluded from every total. The counterfactual is NOT_VERIFIED.',
            $partialCount > 0 => 'Entry slippage of partially closed positions is allocated proportionally to the realized closed volume, but the realized-scope Net PnL is unresolved because net_pnl mixes full-quantity entry fees and open-remainder funding. The counterfactual stays unavailable.',
            $openCount > 0 => 'Entry slippage of still open positions is unrealized and is excluded from the realized counterfactual; the full-fill figure is reported separately as NOT_VERIFIED_SCOPE_MISMATCH.',
            default => 'All positions are fully closed: the realized drag equals the total full-fill execution drag.',
        };

        $realizedScopeNetPnl = $scopeEstablished ? $this->normalize($realizedNetPnl) : null;
        $realizedDragValue = ($populationMatch && $invalidCount === 0) ? $this->normalize($realizedDrag) : null;
        $hypothetical = ($scopeEstablished && $realizedScopeNetPnl !== null && $realizedDragValue !== null)
            ? $this->normalize($realizedNetPnl->plus($realizedDrag))
            : null;

        return [
            'status' => $status,
            'provenance' => $status === self::STATUS_COMPUTED ? 'HYPOTHETICAL' : 'NOT_VERIFIED',
            'population_match' => $populationMatch,
            'expected_population_size' => $expectedPopulationSize,
            'reconciled_population_size' => count($positions),
            'closed_position_count' => $closedCount,
            'partial_position_count' => $partialCount,
            'open_position_count' => $openCount,
            'invalid_position_count' => $invalidCount,
            'realized_pnl_scope_status' => $scopeStatus,
            'realized_pnl_scope_reason' => $scopeReason,
            'realized_scope_net_pnl' => $realizedScopeNetPnl,
            'open_position_net_pnl' => $this->normalize($openNetPnl),
            'realized_allocated_slippage_drag' => $realizedDragValue,
            'open_unallocated_slippage_drag' => $this->normalize($openDrag),
            'full_fill_slippage_drag' => $this->normalize($fullFillDrag),
            'hypothetical_net_pnl_zero_slippage' => $hypothetical,
            'raw_counterfactual_status' => $rawStatus,
            'reconciliation_note' => $note,
        ];
    }

    /**
     * @param  array<string, mixed>  $position
     */
    private function decimal(array $position, string $key): ?BigDecimal
    {
        if (! array_key_exists($key, $position) || $position[$key] === null) {
            return null;
        }

        $raw = trim((string) $position[$key]);
        if ($raw === '') {
            return null;
        }

        try {
            return BigDecimal::of($raw);
        } catch (MathException) {
            return null;
        }
    }

    /**
     * Render an exact decimal without a trailing zero scale, so the emitted
     * values stay comparable with the rest of the research evidence.
     */
    private function normalize(BigDecimal $value): string
    {
        try {
            return (string) $value->toScale(0, RoundingMode::Unnecessary);
        } catch (MathException) {
            $rendered = (string) $value;

            if (! str_contains($rendered, '.')) {
                return $rendered;
            }

            $trimmed = rtrim(rtrim($rendered, '0'), '.');

            return $trimmed === '-0' ? '0' : $trimmed;
        }
    }
}
