<?php

declare(strict_types=1);

namespace App\PaperTrading\Infrastructure\Persistence;

use App\PaperTrading\Domain\TradeRiskClass;
use App\Strategy\Domain\QualityClass;
use App\Strategy\Domain\SignalQuality;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class VirtualPositionRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'virtual_positions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'opened_at' => 'immutable_datetime', 'maximum_holding_until' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'last_processed_event_at' => 'immutable_datetime',
            'ambiguous_execution' => 'boolean', 'funding_complete' => 'boolean', 'awaiting_market_data' => 'boolean',
            'entry_price' => 'string', 'stop_loss' => 'string', 'take_profit_1' => 'string', 'take_profit_2' => 'string',
            'initial_quantity' => 'string', 'remaining_quantity' => 'string', 'qty_step' => 'string', 'risk_capital' => 'string', 'gross_pnl' => 'string',
            'net_pnl' => 'string', 'unrealized_pnl' => 'string', 'fees' => 'string', 'funding' => 'string', 'r_multiple' => 'string',
        ];
    }

    public function getSignalQualityAttribute(?string $value): string
    {
        if ($value !== null && $value !== '') {
            return $value;
        }

        return SignalQuality::fromQualityClass(
            $this->quality_class !== null ? QualityClass::tryFrom($this->quality_class) : null
        )->value;
    }

    public function getTradeRiskAttribute(?string $value): string
    {
        return $value ?: TradeRiskClass::Unknown->value;
    }
}
