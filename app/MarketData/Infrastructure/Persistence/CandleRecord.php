<?php

declare(strict_types=1);

namespace App\MarketData\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class CandleRecord extends Model
{
    protected $table = 'market_candles';

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'open_time' => 'immutable_datetime:Y-m-d H:i:s.vP',
            'is_closed' => 'boolean',
            'open' => 'string',
            'high' => 'string',
            'low' => 'string',
            'close' => 'string',
            'volume' => 'string',
            'turnover' => 'string',
        ];
    }
}
