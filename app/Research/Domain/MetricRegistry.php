<?php

declare(strict_types=1);

namespace App\Research\Domain;

final class MetricRegistry
{
    public const VERSION = '1.0.0';

    /**
     * @return array<string, array{
     *     name: string,
     *     label: string,
     *     unit: string,
     *     accounting_scope: string,
     *     formula: string,
     *     denominator_rule: string,
     *     missing_data_semantics: string,
     *     provenance: string,
     *     double_deduction_prohibited: bool,
     *     notes: string
     * }>
     */
    public static function all(): array
    {
        return [
            'gross_pnl' => [
                'name' => 'gross_pnl',
                'label' => 'Gross PnL',
                'unit' => 'USDT',
                'accounting_scope' => 'FULL_LIFECYCLE',
                'formula' => 'sum(closed_position.gross_pnl) where gross_pnl = (exit_price - entry_price) * size * side_multiplier',
                'denominator_rule' => 'NOT_APPLICABLE',
                'missing_data_semantics' => 'NULL with INSUFFICIENT_DATA if unclosed or unpriced',
                'provenance' => 'RECORDED_SIMULATOR',
                'double_deduction_prohibited' => false,
                'notes' => 'Paper gross PnL already incorporates the effect of fill prices from the simulator.',
            ],
            'fees' => [
                'name' => 'fees',
                'label' => 'Total Commission Fees',
                'unit' => 'USDT',
                'accounting_scope' => 'FULL_LIFECYCLE',
                'formula' => 'sum(execution.commission) across position lifecycle',
                'denominator_rule' => 'NOT_APPLICABLE',
                'missing_data_semantics' => 'NULL with INSUFFICIENT_DATA if fee missing; never 0 fallback',
                'provenance' => 'RECORDED_SIMULATOR',
                'double_deduction_prohibited' => false,
                'notes' => 'VIP0 baseline taker 0.055%, maker 0.020%. Observed paper fills are 100% taker.',
            ],
            'signed_funding' => [
                'name' => 'signed_funding',
                'label' => 'Signed Funding Cashflow',
                'unit' => 'USDT',
                'accounting_scope' => 'FULL_LIFECYCLE',
                'formula' => 'sum(position_funding_event.amount)',
                'denominator_rule' => 'NOT_APPLICABLE',
                'missing_data_semantics' => 'NULL with INSUFFICIENT_DATA if funding event missing',
                'provenance' => 'RECORDED_SIMULATOR',
                'double_deduction_prohibited' => false,
                'notes' => 'Positive = received funding; Negative = paid funding.',
            ],
            'net_pnl' => [
                'name' => 'net_pnl',
                'label' => 'Net Realized PnL',
                'unit' => 'USDT',
                'accounting_scope' => 'FULL_LIFECYCLE',
                'formula' => 'gross_pnl - fees + signed_funding',
                'denominator_rule' => 'NOT_APPLICABLE',
                'missing_data_semantics' => 'NULL with INSUFFICIENT_DATA if any input component is undefined',
                'provenance' => 'RECOMPUTED_FROM_SOURCE',
                'double_deduction_prohibited' => true,
                'notes' => 'Strict accounting identity: gross - fees + funding = net. Slippage must NEVER be deducted again.',
            ],
            'slippage_price_delta' => [
                'name' => 'slippage_price_delta',
                'label' => 'Execution Slippage (Price Delta)',
                'unit' => 'USDT_PER_CONTRACT_PRICE_DELTA',
                'accounting_scope' => 'EVENT_EXECUTION',
                'formula' => 'abs(fill_price - reference_price)',
                'denominator_rule' => 'NOT_APPLICABLE',
                'missing_data_semantics' => 'NULL with NOT_RECORDED if reference price is missing',
                'provenance' => 'RECORDED_SIMULATOR',
                'double_deduction_prohibited' => true,
                'notes' => 'Stored in virtual_executions.slippage as price delta in quote currency. NOT total dollar drag, NOT bps.',
            ],
            'slippage_drag_attribution' => [
                'name' => 'slippage_drag_attribution',
                'label' => 'Simulated Slippage Dollar Drag (Diagnostic Attribution)',
                'unit' => 'USDT',
                'accounting_scope' => 'WINDOW_FLOW',
                'formula' => 'sum(execution.quantity * execution.slippage)',
                'denominator_rule' => 'NOT_APPLICABLE',
                'missing_data_semantics' => 'NULL with INSUFFICIENT_DATA if execution quantity or slippage undefined',
                'provenance' => 'RECORDED_SIMULATOR',
                'double_deduction_prohibited' => true,
                'notes' => 'Diagnostic attribution only. Fill price already reflects slippage; NEVER subtract from net PnL a second time.',
            ],
            'profit_factor' => [
                'name' => 'profit_factor',
                'label' => 'Profit Factor',
                'unit' => 'RATIO',
                'accounting_scope' => 'FULL_LIFECYCLE',
                'formula' => 'sum(positive_net_outcomes) / abs(sum(negative_net_outcomes))',
                'denominator_rule' => 'If abs(sum(negative_net_outcomes)) == 0, return NULL with UNDEFINED_NO_LOSSES',
                'missing_data_semantics' => 'NULL with UNDEFINED_NO_LOSSES or INSUFFICIENT_DATA',
                'provenance' => 'RECOMPUTED_FROM_SOURCE',
                'double_deduction_prohibited' => false,
                'notes' => 'Never fallback to 0 or division by zero.',
            ],
            'win_rate' => [
                'name' => 'win_rate',
                'label' => 'Win Rate (excluding scratches)',
                'unit' => 'PERCENT',
                'accounting_scope' => 'FULL_LIFECYCLE',
                'formula' => 'wins / (wins + losses)',
                'denominator_rule' => 'If (wins + losses) == 0, return NULL with UNDEFINED_NO_TRADES',
                'missing_data_semantics' => 'NULL with UNDEFINED_NO_TRADES',
                'provenance' => 'RECOMPUTED_FROM_SOURCE',
                'double_deduction_prohibited' => false,
                'notes' => 'Flat/scratch outcomes are excluded from win rate denominator but recorded separately.',
            ],
            'payoff_ratio' => [
                'name' => 'payoff_ratio',
                'label' => 'Payoff Ratio',
                'unit' => 'RATIO',
                'accounting_scope' => 'FULL_LIFECYCLE',
                'formula' => 'mean(winning_net) / abs(mean(losing_net))',
                'denominator_rule' => 'If losing trades count == 0, return NULL with UNDEFINED_NO_LOSSES',
                'missing_data_semantics' => 'NULL with UNDEFINED_NO_LOSSES or UNDEFINED_NO_WINS',
                'provenance' => 'RECOMPUTED_FROM_SOURCE',
                'double_deduction_prohibited' => false,
                'notes' => 'Undefined when winning or losing cohort is absent.',
            ],
            'twae' => [
                'name' => 'twae',
                'label' => 'Time-Weighted Average Equity',
                'unit' => 'USDT',
                'accounting_scope' => 'WINDOW_FLOW',
                'formula' => 'sum(equity_k * delta_t_k) / sum(delta_t_k)',
                'denominator_rule' => 'If sum(delta_t_k) <= 0, return NULL with UNDEFINED_WINDOW',
                'missing_data_semantics' => 'NULL with INSUFFICIENT_DATA if snapshot gaps exceed max carry-forward',
                'provenance' => 'RECOMPUTED_FROM_SOURCE',
                'double_deduction_prohibited' => false,
                'notes' => 'Piecewise constant equity between valid snapshots.',
            ],
            'maximum_drawdown_percent' => [
                'name' => 'maximum_drawdown_percent',
                'label' => 'Maximum Drawdown',
                'unit' => 'PERCENT',
                'accounting_scope' => 'WINDOW_FLOW',
                'formula' => 'max( (high_water_mark - equity_k) / high_water_mark * 100 )',
                'denominator_rule' => 'If high_water_mark <= 0, return NULL with INVALID_HWM',
                'missing_data_semantics' => 'NULL with INSUFFICIENT_DATA if equity series empty',
                'provenance' => 'RECORDED_SIMULATOR',
                'double_deduction_prohibited' => false,
                'notes' => 'Calculated against historical equity snapshots with high-water mark tracking.',
            ],
            'average_margin_used_pct' => [
                'name' => 'average_margin_used_pct',
                'label' => 'Average Margin Used % of Equity',
                'unit' => 'PERCENT',
                'accounting_scope' => 'WINDOW_FLOW',
                'formula' => 'average(used_margin / equity)',
                'denominator_rule' => 'margin fields not recorded in ledger',
                'missing_data_semantics' => 'NULL with NOT_RECORDED_IN_LEDGER',
                'provenance' => 'NOT_APPLICABLE',
                'double_deduction_prohibited' => false,
                'notes' => 'Explicitly unavailable in current PostgreSQL schema. Never invent an assumed margin number.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $name): ?array
    {
        return self::all()[$name] ?? null;
    }
}
