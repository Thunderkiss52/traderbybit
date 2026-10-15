<?php

declare(strict_types=1);

namespace App\Strategy\Infrastructure\Persistence;

use App\Strategy\Contracts\MarketContextRepository;
use App\Strategy\Domain\MarketContext;
use DateTimeImmutable;

final class EloquentMarketContextRepository implements MarketContextRepository
{
    public function latestAt(string $exchange, string $category, string $symbol, DateTimeImmutable $at): ?MarketContext
    {
        $record = MarketContextRecord::query()->where(compact('exchange', 'category', 'symbol'))->where('observed_at', '<=', $at)->latest('observed_at')->first();
        if ($record === null) {
            return null;
        }

        return new MarketContext(
            $record->observed_at->toDateTimeImmutable(), $record->bid, $record->ask, $record->turnover_24h, $record->tick_size,
            $record->funding_rate, $record->open_interest, $record->open_interest_change,
            $record->qty_step, $record->min_order_qty, $record->max_order_qty, $record->min_notional_value,
        );
    }

    public function store(string $exchange, string $category, string $symbol, MarketContext $context): void
    {
        MarketContextRecord::query()->updateOrCreate(
            ['exchange' => $exchange, 'category' => $category, 'symbol' => $symbol, 'observed_at' => $context->observedAt],
            [
                'bid' => $context->bid, 'ask' => $context->ask, 'turnover_24h' => $context->turnover24h, 'tick_size' => $context->tickSize,
                'funding_rate' => $context->fundingRate, 'open_interest' => $context->openInterest, 'open_interest_change' => $context->openInterestChange,
                'qty_step' => $context->qtyStep, 'min_order_qty' => $context->minOrderQty, 'max_order_qty' => $context->maxOrderQty,
                'min_notional_value' => $context->minNotionalValue,
            ],
        );
    }
}
