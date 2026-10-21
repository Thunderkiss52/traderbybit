<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class QimAlertRecord extends Model
{
    public $timestamps = false;

    protected $table = 'qim_alerts';

    protected $guarded = [];

    protected $casts = [
        'window_start' => 'immutable_datetime',
        'window_end' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'payload' => 'array',
    ];
}
