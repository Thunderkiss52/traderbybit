<?php

declare(strict_types=1);

namespace App\PaperTrading\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class PaperSignalStateRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'paper_signal_states';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['processed_at' => 'immutable_datetime'];
    }
}
