<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class CounterfactualEvaluationRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'counterfactual_evaluations';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'decision_time' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'decision_snapshot' => 'array',
            'hypothetical_entry' => 'string',
            'hypothetical_sl' => 'string',
            'hypothetical_tp' => 'string',
            'mfe' => 'string',
            'mae' => 'string',
            'r_multiple' => 'string',
            'net_return_percent' => 'string',
        ];
    }
}
