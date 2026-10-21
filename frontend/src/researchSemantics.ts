/**
 * P-1 counterfactual presentation semantics.
 *
 * Unavailable values must never be rendered as numeric zero and the
 * realized-scope PnL must never fall back to the all-positions baseline:
 * they are different accounting scopes.
 */

const present = (value: unknown): boolean => value !== null && value !== undefined && value !== '';

export const formatMetric = (value: unknown): string => (present(value) ? String(value) : '—');

const VERIFIED_STATUSES = new Set(['COMPUTED']);

export type CounterfactualView = {
  status: string;
  verified: boolean;
  realizedScopeStatus: string;
  realizedScopeFormatted: string;
  realizedScopeAvailable: boolean;
  realizedScopePositive: boolean | null;
  attributableDragFormatted: string;
  attributableDragAvailable: boolean;
  openUnallocatedDragFormatted: string;
  openUnallocatedDragAvailable: boolean;
  hypotheticalFormatted: string;
  hypotheticalAvailable: boolean;
  hypotheticalPositive: boolean | null;
  baselineAllPositionsFormatted: string;
  unavailableReason: string | null;
  showUnavailable: boolean;
};

export function counterfactualView(counterfactual: any): CounterfactualView {
  const cf = counterfactual ?? {};
  const status = present(cf.status) ? String(cf.status) : 'UNKNOWN';
  const verified = VERIFIED_STATUSES.has(status);

  const realized = cf.realized_scope_net_pnl;
  const drag = present(cf.realized_allocated_slippage_drag)
    ? cf.realized_allocated_slippage_drag
    : cf.attributable_slippage_drag;
  const openDrag = cf.open_unallocated_slippage_drag;
  const hypothetical = cf.hypothetical_net_pnl_zero_slippage;

  const realizedScopeAvailable = present(realized);
  const attributableDragAvailable = present(drag);
  const openUnallocatedDragAvailable = present(openDrag);
  const hypotheticalAvailable = present(hypothetical) && verified;

  let unavailableReason: string | null = null;
  if (!verified || !hypotheticalAvailable) {
    if (present(cf.realized_pnl_scope_reason)) {
      unavailableReason = String(cf.realized_pnl_scope_reason);
    } else if (present(cf.reconciliation_note)) {
      unavailableReason = String(cf.reconciliation_note);
    } else {
      unavailableReason = `Контрфактический расчёт недоступен (статус: ${status}).`;
    }
  }

  return {
    status,
    verified,
    realizedScopeStatus: present(cf.realized_pnl_scope_status) ? String(cf.realized_pnl_scope_status) : 'UNKNOWN',
    realizedScopeFormatted: formatMetric(realized),
    realizedScopeAvailable,
    realizedScopePositive: realizedScopeAvailable ? Number(realized) >= 0 : null,
    attributableDragFormatted: formatMetric(drag),
    attributableDragAvailable,
    openUnallocatedDragFormatted: formatMetric(openDrag),
    openUnallocatedDragAvailable,
    hypotheticalFormatted: formatMetric(hypotheticalAvailable ? hypothetical : null),
    hypotheticalAvailable,
    hypotheticalPositive: hypotheticalAvailable ? Number(hypothetical) >= 0 : null,
    baselineAllPositionsFormatted: formatMetric(cf.baseline_actual_net_pnl),
    unavailableReason,
    showUnavailable: !verified || !hypotheticalAvailable,
  };
}
