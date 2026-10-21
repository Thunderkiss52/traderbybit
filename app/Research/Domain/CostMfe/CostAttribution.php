<?php

declare(strict_types=1);

namespace App\Research\Domain\CostMfe;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * PROFIT-1 / P-4 — cost attribution by strategy, symbol, account and fill
 * role (§9.1).
 *
 * Accounting identity (identical to P-1): Net PnL = Gross PnL - Fees + Signed
 * Funding. Slippage is price distortion already contained in Gross PnL and is
 * therefore reported as an attribution, never deducted a second time.
 *
 * Undefined ratios are NULL with an explicit reason, never zero.
 */
final class CostAttribution
{
    public const ROLE_ENTRY = 'ENTRY';

    public const ROLE_PARTIAL_EXIT = 'PARTIAL_EXIT';

    public const ROLE_FINAL_EXIT = 'FINAL_EXIT';

    public const DIMENSION_STRATEGY = 'strategy';

    public const DIMENSION_SYMBOL = 'symbol';

    public const DIMENSION_ACCOUNT = 'account';

    public const DIMENSION_FILL_ROLE = 'fill_role';

    private const SCALE = 12;

    /**
     * @param  list<array<string, mixed>>  $executions
     * @param  list<array<string, mixed>>  $fundingEvents
     * @param  list<array<string, mixed>>  $positions
     * @param  list<string>  $dimensions
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function aggregate(
        array $executions,
        array $fundingEvents,
        array $positions,
        array $dimensions = [self::DIMENSION_STRATEGY, self::DIMENSION_SYMBOL, self::DIMENSION_ACCOUNT, self::DIMENSION_FILL_ROLE],
    ): array {
        $groups = [];
        foreach ($dimensions as $dimension) {
            $groups[$dimension] = [];
        }

        $positionIndex = [];
        foreach ($positions as $position) {
            $positionIndex[(string) $position['id']] = $position;
        }

        $executionCounts = [];
        $executionsPerPosition = [];
        $rolesPerPosition = [];

        foreach ($executions as $execution) {
            $position = $positionIndex[(string) ($execution['position_id'] ?? '')] ?? null;
            $strategyId = (string) ($execution['strategy_id'] ?? ($position['strategy_id'] ?? 'UNKNOWN'));
            $symbol = (string) ($execution['symbol'] ?? ($position['symbol'] ?? 'UNKNOWN'));
            $accountId = (string) ($execution['account_id'] ?? ($position['account_id'] ?? 'UNKNOWN'));
            $role = $this->fillRole((string) ($execution['type'] ?? ''));

            $quantity = BigDecimal::of((string) ($execution['quantity'] ?? '0'));
            $price = BigDecimal::of((string) ($execution['price'] ?? '0'));
            $slippage = BigDecimal::of((string) ($execution['slippage'] ?? '0'));
            $commission = BigDecimal::of((string) ($execution['commission'] ?? '0'));
            $grossPnl = BigDecimal::of((string) ($execution['gross_pnl'] ?? '0'));

            $side = strtoupper((string) ($execution['side'] ?? ''));
            $referencePrice = $side === 'SELL' ? $price->plus($slippage) : $price->minus($slippage);
            $drag = $slippage->abs()->multipliedBy($quantity);
            $referenceNotional = $referencePrice->abs()->multipliedBy($quantity);

            $keys = [
                self::DIMENSION_STRATEGY => $strategyId,
                self::DIMENSION_SYMBOL => $symbol,
                self::DIMENSION_ACCOUNT => $accountId,
                self::DIMENSION_FILL_ROLE => $role,
            ];

            $positionKey = (string) ($execution['position_id'] ?? '');
            $executionsPerPosition[$positionKey] = ($executionsPerPosition[$positionKey] ?? 0) + 1;
            $rolesPerPosition[$positionKey][$role] = true;

            foreach ($keys as $dimension => $key) {
                if (! array_key_exists($dimension, $groups)) {
                    continue;
                }

                $bucket = $groups[$dimension][$key] ?? $this->emptyBucket();
                $groups[$dimension][$key] = $this->applyExecution($bucket, $role, $commission, $drag, $referenceNotional, $grossPnl, $execution);
                $executionCounts[$dimension][$key] = ($executionCounts[$dimension][$key] ?? 0) + 1;
            }
        }

        foreach ($positions as $position) {
            $strategyId = (string) ($position['strategy_id'] ?? 'UNKNOWN');
            $symbol = (string) ($position['symbol'] ?? 'UNKNOWN');
            $accountId = (string) ($position['account_id'] ?? 'UNKNOWN');

            $keys = [
                self::DIMENSION_STRATEGY => $strategyId,
                self::DIMENSION_SYMBOL => $symbol,
                self::DIMENSION_ACCOUNT => $accountId,
            ];

            foreach ($keys as $dimension => $key) {
                if (! array_key_exists($dimension, $groups)) {
                    continue;
                }

                $bucket = $groups[$dimension][$key] ?? $this->emptyBucket();
                $positionKey = (string) $position['id'];
                $groups[$dimension][$key] = $this->applyPosition(
                    $bucket,
                    $position,
                    (int) ($executionsPerPosition[$positionKey] ?? 0),
                    isset($rolesPerPosition[$positionKey][self::ROLE_ENTRY]),
                );
            }
        }

        foreach ($fundingEvents as $funding) {
            $position = $positionIndex[(string) ($funding['position_id'] ?? '')] ?? null;
            $strategyId = (string) ($funding['strategy_id'] ?? ($position['strategy_id'] ?? 'UNKNOWN'));
            $symbol = (string) ($funding['symbol'] ?? ($position['symbol'] ?? 'UNKNOWN'));
            $accountId = (string) ($funding['account_id'] ?? ($position['account_id'] ?? 'UNKNOWN'));

            $keys = [
                self::DIMENSION_STRATEGY => $strategyId,
                self::DIMENSION_SYMBOL => $symbol,
                self::DIMENSION_ACCOUNT => $accountId,
            ];

            foreach ($keys as $dimension => $key) {
                if (! array_key_exists($dimension, $groups)) {
                    continue;
                }

                $bucket = $groups[$dimension][$key] ?? $this->emptyBucket();
                $bucket['funding_signed'] = $bucket['funding_signed']->plus(BigDecimal::of((string) ($funding['amount'] ?? '0')));
                $bucket['funding_event_count']++;
                $groups[$dimension][$key] = $bucket;
            }
        }

        $result = [];
        foreach ($groups as $dimension => $buckets) {
            ksort($buckets);
            $result[$dimension] = [];
            foreach ($buckets as $key => $bucket) {
                $result[$dimension][$key] = $this->finalize($bucket);
            }
        }

        return $result;
    }

    public function fillRole(string $executionType): string
    {
        $type = strtoupper(trim($executionType));

        if ($type === 'ENTRY') {
            return self::ROLE_ENTRY;
        }

        if (str_starts_with($type, 'PARTIAL')) {
            return self::ROLE_PARTIAL_EXIT;
        }

        return self::ROLE_FINAL_EXIT;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyBucket(): array
    {
        return [
            'execution_count' => 0,
            'position_count' => 0,
            'closed_position_count' => 0,
            'open_position_count' => 0,
            'position_ids' => [],
            'gross_pnl' => BigDecimal::zero(),
            'commission_total' => BigDecimal::zero(),
            'slippage_drag_usdt' => BigDecimal::zero(),
            'reference_notional_total' => BigDecimal::zero(),
            'funding_signed' => BigDecimal::zero(),
            'funding_event_count' => 0,
            'net_pnl' => BigDecimal::zero(),
            'fees_total' => BigDecimal::zero(),
            'gross_positive_net_negative_count' => 0,
            'ambiguous_execution_count' => 0,
            'missing_cost_record_count' => 0,
            'missing_entry_execution_count' => 0,
            'positions_missing_entry_execution' => [],
            'cost_by_role' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $bucket
     * @param  array<string, mixed>  $execution
     * @return array<string, mixed>
     */
    private function applyExecution(array $bucket, string $role, BigDecimal $commission, BigDecimal $drag, BigDecimal $notional, BigDecimal $grossPnl, array $execution): array
    {
        $bucket['execution_count']++;
        $bucket['gross_pnl'] = $bucket['gross_pnl']->plus($grossPnl);
        $bucket['commission_total'] = $bucket['commission_total']->plus($commission);
        $bucket['slippage_drag_usdt'] = $bucket['slippage_drag_usdt']->plus($drag);
        $bucket['reference_notional_total'] = $bucket['reference_notional_total']->plus($notional);

        $roleKey = $role;
        $bucket['cost_by_role'][$roleKey] ??= [
            'role' => $roleKey,
            'execution_count' => 0,
            'commission_total' => BigDecimal::zero(),
            'slippage_drag_usdt' => BigDecimal::zero(),
            'reference_notional_total' => BigDecimal::zero(),
            'gross_pnl' => BigDecimal::zero(),
        ];
        $bucket['cost_by_role'][$roleKey]['execution_count']++;
        $bucket['cost_by_role'][$roleKey]['commission_total'] = $bucket['cost_by_role'][$roleKey]['commission_total']->plus($commission);
        $bucket['cost_by_role'][$roleKey]['slippage_drag_usdt'] = $bucket['cost_by_role'][$roleKey]['slippage_drag_usdt']->plus($drag);
        $bucket['cost_by_role'][$roleKey]['reference_notional_total'] = $bucket['cost_by_role'][$roleKey]['reference_notional_total']->plus($notional);
        $bucket['cost_by_role'][$roleKey]['gross_pnl'] = $bucket['cost_by_role'][$roleKey]['gross_pnl']->plus($grossPnl);

        if ((bool) ($execution['ambiguous_execution'] ?? false)) {
            $bucket['ambiguous_execution_count']++;
        }

        return $bucket;
    }

    /**
     * @param  array<string, mixed>  $bucket
     * @param  array<string, mixed>  $position
     * @return array<string, mixed>
     */
    private function applyPosition(array $bucket, array $position, int $executionCount, bool $hasEntryExecution): array
    {
        $bucket['position_count']++;
        $bucket['position_ids'][] = (string) $position['id'];

        if ($executionCount === 0) {
            $bucket['missing_cost_record_count']++;
        }

        if (! $hasEntryExecution) {
            $bucket['missing_entry_execution_count']++;
            $bucket['positions_missing_entry_execution'][] = (string) $position['id'];
        }

        $status = strtoupper((string) ($position['status'] ?? ''));
        if ($status === 'OPEN') {
            $bucket['open_position_count']++;
        } else {
            $bucket['closed_position_count']++;
        }

        $netPnl = BigDecimal::of((string) ($position['net_pnl'] ?? '0'));
        $grossPnl = BigDecimal::of((string) ($position['gross_pnl'] ?? '0'));

        $bucket['net_pnl'] = $bucket['net_pnl']->plus($netPnl);
        $bucket['fees_total'] = $bucket['fees_total']->plus(BigDecimal::of((string) ($position['fees'] ?? '0')));

        if ($grossPnl->isPositive() && ! $netPnl->isPositive()) {
            $bucket['gross_positive_net_negative_count']++;
        }

        return $bucket;
    }

    /**
     * @param  array<string, mixed>  $bucket
     * @return array<string, mixed>
     */
    private function normalize(BigDecimal $value): string
    {
        return (string) $value->toScale(self::SCALE, RoundingMode::HalfEven);
    }

    /**
     * @param  array<string, mixed>  $bucket
     * @return array<string, mixed>
     */
    private function finalize(array $bucket): array
    {
        $grossPnl = $bucket['gross_pnl'];
        $commission = $bucket['commission_total'];
        $notional = $bucket['reference_notional_total'];
        $drag = $bucket['slippage_drag_usdt'];

        $commissionShare = null;
        $commissionShareReason = null;
        if (! $grossPnl->isPositive()) {
            $commissionShareReason = 'GROSS_PNL_NOT_POSITIVE';
        } else {
            $commissionShare = $commission->dividedBy($grossPnl, self::SCALE, RoundingMode::HalfEven);
        }

        $weightedBps = null;
        $weightedBpsReason = null;
        if ($notional->isZero()) {
            $weightedBpsReason = 'ZERO_REFERENCE_NOTIONAL';
        } else {
            $weightedBps = $drag->dividedBy($notional, self::SCALE, RoundingMode::HalfEven)->multipliedBy(10000)->toScale(4, RoundingMode::HalfEven);
        }

        $reconciles = null;
        $expectedNet = $grossPnl->minus($bucket['fees_total'])->plus($bucket['funding_signed']);
        if ($bucket['position_count'] > 0) {
            $reconciles = $expectedNet->minus($bucket['net_pnl'])->abs()->isLessThanOrEqualTo(BigDecimal::of('0.000000000001'));
        }

        $roles = [];
        foreach ($bucket['cost_by_role'] as $roleKey => $role) {
            $roles[$roleKey] = [
                'role' => $role['role'],
                'execution_count' => $role['execution_count'],
                'commission_total' => $this->normalize($role['commission_total']),
                'slippage_drag_usdt' => $this->normalize($role['slippage_drag_usdt']),
                'reference_notional_total' => $this->normalize($role['reference_notional_total']),
                'gross_pnl' => $this->normalize($role['gross_pnl']),
            ];
        }
        ksort($roles);

        return [
            'execution_count' => $bucket['execution_count'],
            'position_count' => $bucket['position_count'],
            'closed_position_count' => $bucket['closed_position_count'],
            'open_position_count' => $bucket['open_position_count'],
            'gross_pnl' => $this->normalize($grossPnl),
            'commission_total' => $this->normalize($commission),
            'slippage_drag_usdt' => $this->normalize($drag),
            'reference_notional_total' => $this->normalize($notional),
            'weighted_slippage_bps' => $weightedBps === null ? null : (string) $weightedBps,
            'weighted_slippage_bps_reason' => $weightedBpsReason,
            'commission_share_of_gross_pnl' => $commissionShare === null ? null : $this->normalize($commissionShare),
            'commission_share_of_gross_pnl_reason' => $commissionShareReason,
            'funding_signed' => $this->normalize($bucket['funding_signed']),
            'funding_event_count' => $bucket['funding_event_count'],
            'net_pnl' => $this->normalize($bucket['net_pnl']),
            'fees_total' => $this->normalize($bucket['fees_total']),
            'reconciles_gross_minus_fees_plus_funding' => $reconciles,
            'gross_positive_net_negative_count' => $bucket['gross_positive_net_negative_count'],
            'ambiguous_execution_count' => $bucket['ambiguous_execution_count'],
            'positions_with_missing_execution_rows' => $bucket['missing_cost_record_count'],
            'positions_missing_entry_execution' => $bucket['missing_entry_execution_count'],
            'position_ids_missing_entry_execution' => $bucket['positions_missing_entry_execution'],
            'execution_commission_minus_position_fees_delta' => $this->normalize($commission->minus($bucket['fees_total'])),
            'cost_by_role' => $roles,
            'accounting_note' => 'NET_PNL_EQUALS_GROSS_MINUS_FEES_PLUS_SIGNED_FUNDING_SLIPPAGE_NOT_DEDUCTED_TWICE',
            'slippage_provenance' => 'P1_FORMULA_RECOMPUTED_FROM_EXECUTION_ROWS',
        ];
    }
}
