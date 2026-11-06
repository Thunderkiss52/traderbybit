<?php

declare(strict_types=1);

namespace App\Strategy\Domain;

enum MethodType: string
{
    case Style = 'style';
    case Signal = 'signal';
    case Filter = 'filter';
    case RegimeFilter = 'regime_filter';
    case PositionSizing = 'position_sizing';
    case PortfolioAllocation = 'portfolio_allocation';
    case Execution = 'execution';
    case PositionManagement = 'position_management';
    case AlternativeData = 'alternative_data';

    public function displayName(): string
    {
        return match ($this) {
            self::Style => 'Trading Style',
            self::Signal => 'Signal Generator',
            self::Filter => 'Confirmation Filter',
            self::RegimeFilter => 'Market Regime Filter',
            self::PositionSizing => 'Position Sizing Model',
            self::PortfolioAllocation => 'Portfolio Allocation',
            self::Execution => 'Execution Method',
            self::PositionManagement => 'Position Management',
            self::AlternativeData => 'Alternative Data Source',
        };
    }
}
