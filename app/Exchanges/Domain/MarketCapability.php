<?php

declare(strict_types=1);

namespace App\Exchanges\Domain;

enum MarketCapability: string
{
    case Candles = 'candles';
    case OrderBookL2 = 'order_book_l2';
    case Trades = 'trades';
    case Ticker = 'ticker';
    case MarkPrice = 'mark_price';
    case FundingHistory = 'funding_history';
    case OpenInterest = 'open_interest';
    case Liquidations = 'liquidations';
    case OptionsChain = 'options_chain';

    public function displayName(): string
    {
        return match ($this) {
            self::Candles => 'Historical & Live OHLCV Candles',
            self::OrderBookL2 => 'Order Book Level 2 Depth',
            self::Trades => 'Public Trade Ticks',
            self::Ticker => 'Latest 24h Ticker Price & Volume',
            self::MarkPrice => 'Mark and Index Settlement Prices',
            self::FundingHistory => 'Funding Settlement History',
            self::OpenInterest => 'Open Interest Aggregations',
            self::Liquidations => 'Liquidation Stream & History',
            self::OptionsChain => 'Options Chain & Contract Metadata',
        };
    }
}
