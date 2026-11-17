<?php

declare(strict_types=1);

namespace App\Strategy\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class MarketContextRecord extends Model
{
    protected $table = 'market_context_snapshots';

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = [];

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            if ($model->observed_at !== null && $model->created_at === null) {
                $model->created_at = $model->observed_at;
                $model->updated_at = $model->observed_at;
            }
        });

        self::updating(function (self $model): void {
            if ($model->observed_at !== null && ! $model->isDirty('updated_at')) {
                $model->updated_at = $model->observed_at;
            }
        });
    }

    public function freshTimestamp()
    {
        return $this->observed_at ?? parent::freshTimestamp();
    }

    protected function casts(): array
    {
        return [
            'observed_at' => 'immutable_datetime', 'bid' => 'string', 'ask' => 'string', 'turnover_24h' => 'string', 'tick_size' => 'string',
            'funding_rate' => 'string', 'open_interest' => 'string', 'open_interest_change' => 'string',
            'qty_step' => 'string', 'min_order_qty' => 'string', 'max_order_qty' => 'string', 'min_notional_value' => 'string',
        ];
    }
}
