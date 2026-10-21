<?php

declare(strict_types=1);

namespace App\Research\Domain;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * PROFIT-1 / P-5 — a cluster member (position lifecycle) reference (§10.2).
 *
 * @property string $id
 * @property string $cluster_row_id
 * @property string $generation_id
 * @property string $cluster_id
 * @property string $position_id
 * @property string $paper_account_id
 * @property string $symbol
 * @property string $side
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable $ended_at
 * @property bool $open_at_cutoff
 * @property bool $partial_close_not_ending
 * @property string $net_pnl
 * @property string $unrealized_pnl
 * @property string $entry_notional
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ResearchConcentrationClusterMemberRecord extends Model
{
    public $incrementing = false;

    protected $table = 'research_concentration_cluster_members';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'open_at_cutoff' => 'boolean',
            'partial_close_not_ending' => 'boolean',
            'net_pnl' => 'decimal:12',
            'unrealized_pnl' => 'decimal:12',
            'entry_notional' => 'decimal:12',
            'opened_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }
}
