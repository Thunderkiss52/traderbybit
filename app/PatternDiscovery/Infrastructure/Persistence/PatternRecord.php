<?php

declare(strict_types=1);

namespace App\PatternDiscovery\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class PatternRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'research_patterns';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new \LogicException('Pattern definitions are immutable. Create a new research run instead.');
        });
        static::deleting(static function (): never {
            throw new \LogicException('Pattern definitions are immutable.');
        });
    }

    protected function casts(): array
    {
        return ['terms' => 'array', 'metrics' => 'array'];
    }
}
