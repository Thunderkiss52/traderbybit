<?php

declare(strict_types=1);

namespace App\PaperTrading\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class PaperAccountRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'paper_accounts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'cooldown_until' => 'immutable_datetime', 'drawdown_override' => 'boolean',
            'starting_balance' => 'string', 'current_equity' => 'string', 'peak_equity' => 'string',
            'realized_pnl' => 'string', 'unrealized_pnl' => 'string', 'reserved_risk' => 'string', 'maximum_drawdown_percent' => 'string', 'current_drawdown_percent' => 'string',
            'strategy_parameters' => 'array', 'sizing_settings' => 'array',
            'is_primary' => 'boolean',
        ];
    }
}
