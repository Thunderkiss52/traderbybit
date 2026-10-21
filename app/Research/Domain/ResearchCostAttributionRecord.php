<?php

declare(strict_types=1);

namespace App\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * PROFIT-1 / P-4 — research-owned cost attribution record (§9.1, §12.2).
 */
final class ResearchCostAttributionRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'research_cost_attribution_records';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metrics' => 'array',
        ];
    }
}
