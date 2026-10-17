<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class AutonomySettingRecord extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'autonomy_settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'kill_switch_active' => 'boolean',
            'max_active_shadow_experiments' => 'integer',
            'max_forward_paper_trials' => 'integer',
            'max_promotions_per_week' => 'integer',
            'min_shadow_observation_days' => 'integer',
            'max_allowed_drawdown_percent' => 'decimal:4',
        ];
    }
}
