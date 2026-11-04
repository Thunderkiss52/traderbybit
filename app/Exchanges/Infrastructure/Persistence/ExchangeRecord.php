<?php

declare(strict_types=1);

namespace App\Exchanges\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class ExchangeRecord extends Model
{
    protected $table = 'exchanges';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'cost_model' => 'array',
            'paper_execution_assumptions' => 'array',
            'paper_eligible' => 'boolean',
            'paper_approved' => 'boolean',
            'paper_approved_at' => 'immutable_datetime:Y-m-d H:i:s.vP',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime:Y-m-d H:i:s.vP',
            'updated_at' => 'immutable_datetime:Y-m-d H:i:s.vP',
        ];
    }
}
