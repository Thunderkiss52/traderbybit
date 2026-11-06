<?php

declare(strict_types=1);

namespace App\Strategy\Domain;

enum MethodCategory: string
{
    case TradingHorizons = 'trading_horizons';
    case PriceTechnical = 'price_technical';
    case VolatilityVolume = 'volatility_volume';
    case AutomationRelativeValue = 'automation_relative_value';
    case EventsAlternativeData = 'events_alternative_data';
    case TradeManagementRisk = 'trade_management_risk';

    public function displayName(): string
    {
        return match ($this) {
            self::TradingHorizons => 'Family A: Trading Horizons and Direction',
            self::PriceTechnical => 'Family B: Price and Technical Analysis',
            self::VolatilityVolume => 'Family C: Volatility and Volume',
            self::AutomationRelativeValue => 'Family D: Automation and Relative Value',
            self::EventsAlternativeData => 'Family E: Events and Alternative Data',
            self::TradeManagementRisk => 'Family F: Trade Management and Risk',
        };
    }
}
