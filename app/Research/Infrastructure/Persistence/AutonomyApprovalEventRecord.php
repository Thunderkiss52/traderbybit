<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class AutonomyApprovalEventRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $table = 'autonomy_approval_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function () {
            throw new \RuntimeException('Autonomy approval events are strictly append-only and immutable.');
        });

        self::deleting(function () {
            throw new \RuntimeException('Autonomy approval events are strictly append-only and immutable.');
        });
    }
}
