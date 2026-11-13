<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class SignalQualityProfileRecord extends Model
{
    protected $table = 'signal_quality_profiles';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected static function booted(): void
    {
        self::updating(fn () => throw new \LogicException('Signal quality evidence is immutable.'));
        self::deleting(fn () => throw new \LogicException('Signal quality evidence is immutable.'));
    }

    protected function casts(): array
    {
        return ['scope' => 'array', 'result' => 'array', 'as_of' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
