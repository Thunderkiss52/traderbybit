<?php

declare(strict_types=1);

namespace App\Research\Domain;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * PROFIT-1 / P-5 — a stored overlap cluster (§10.2).
 *
 * `cluster_id` is content addressed: the same definition version, view,
 * symbol, side family and member set always produce the same identifier.
 *
 * @property string $id
 * @property string $generation_id
 * @property string $view_code
 * @property string $scope_code
 * @property string|null $paper_account_id
 * @property string $symbol
 * @property string|null $side_family
 * @property string $cluster_id
 * @property int $member_count
 * @property string $net_pnl
 * @property CarbonImmutable $cluster_start_at
 * @property CarbonImmutable $cluster_end_at
 * @property string $cluster_definition_version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ResearchConcentrationClusterRecord extends Model
{
    public $incrementing = false;

    protected $table = 'research_concentration_clusters';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'member_count' => 'integer',
            'net_pnl' => 'decimal:12',
            'cluster_start_at' => 'immutable_datetime',
            'cluster_end_at' => 'immutable_datetime',
        ];
    }
}
