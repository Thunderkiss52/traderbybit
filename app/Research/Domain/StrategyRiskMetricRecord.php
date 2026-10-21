<?php

declare(strict_types=1);

namespace App\Research\Domain;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $generation_id
 * @property string $dimension_key
 * @property string $paper_account_id
 * @property string $strategy_id
 * @property string $symbol
 * @property string $accounting_scope
 * @property CarbonImmutable $as_of
 * @property CarbonImmutable|null $window_start
 * @property CarbonImmutable|null $window_end
 * @property string $metric_schema_version
 * @property int $n_positions
 * @property int $n_wins
 * @property int $n_losses
 * @property int $n_flat
 * @property int $n_days
 * @property int $exclusions_count
 * @property string|null $net_r_mean
 * @property string|null $net_r_median
 * @property string|null $cost_to_risk_ratio
 * @property string|null $profit_factor
 * @property string|null $profit_factor_reason
 * @property string|null $win_rate
 * @property string|null $payoff_ratio
 * @property string|null $max_drawdown_pct
 * @property string|null $time_underwater_pct
 * @property string|null $twa_equity
 * @property int $max_carry_forward_age_seconds
 * @property int $coverage_gap_seconds
 * @property string|null $turnover
 * @property string|null $net_return_on_equity
 * @property string|null $net_pnl_per_risk_capital
 * @property string|null $average_gross_notional_exposure_pct
 * @property string|null $average_open_risk_capital_pct
 * @property string|null $time_with_open_exposure_pct
 * @property string|null $notional_per_initial_risk_capital
 * @property string|null $average_margin_used_pct_of_equity
 * @property string|null $average_margin_reason
 * @property string|null $sharpe_like
 * @property string|null $ess
 * @property bool $ess_clamped
 * @property string $ess_method
 * @property string $sample_sufficiency
 * @property array<string, mixed>|null $sample_sufficiency_reasons
 * @property string $provenance
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class StrategyRiskMetricRecord extends Model
{
    public $incrementing = false;

    protected $table = 'strategy_risk_metrics';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'as_of' => 'immutable_datetime',
            'window_start' => 'immutable_datetime',
            'window_end' => 'immutable_datetime',
            'n_positions' => 'integer',
            'n_wins' => 'integer',
            'n_losses' => 'integer',
            'n_flat' => 'integer',
            'n_days' => 'integer',
            'exclusions_count' => 'integer',
            'max_carry_forward_age_seconds' => 'integer',
            'coverage_gap_seconds' => 'integer',
            'ess_clamped' => 'boolean',
            'net_r_mean' => 'decimal:8',
            'net_r_median' => 'decimal:8',
            'cost_to_risk_ratio' => 'decimal:8',
            'profit_factor' => 'decimal:8',
            'payoff_ratio' => 'decimal:8',
            'win_rate' => 'decimal:6',
            'max_drawdown_pct' => 'decimal:8',
            'time_underwater_pct' => 'decimal:6',
            'twa_equity' => 'decimal:12',
            'turnover' => 'decimal:8',
            'net_return_on_equity' => 'decimal:8',
            'net_pnl_per_risk_capital' => 'decimal:8',
            'average_gross_notional_exposure_pct' => 'decimal:8',
            'average_open_risk_capital_pct' => 'decimal:8',
            'time_with_open_exposure_pct' => 'decimal:6',
            'notional_per_initial_risk_capital' => 'decimal:8',
            'average_margin_used_pct_of_equity' => 'decimal:8',
            'sharpe_like' => 'decimal:8',
            'ess' => 'decimal:6',
            'sample_sufficiency_reasons' => 'array',
            'exclusion_reasons' => 'array',
            'position_population' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
