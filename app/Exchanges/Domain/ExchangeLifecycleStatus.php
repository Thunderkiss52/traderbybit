<?php

declare(strict_types=1);

namespace App\Exchanges\Domain;

enum ExchangeLifecycleStatus: string
{
    case OfflineDataSource = 'OFFLINE_DATA_SOURCE';
    case ConnectedPublicData = 'CONNECTED_PUBLIC_DATA';
    case ResearchEnabled = 'RESEARCH_ENABLED';
    case PaperEligible = 'PAPER_ELIGIBLE';
    case ActivePaper = 'ACTIVE_PAPER';

    public function canResearch(): bool
    {
        return match ($this) {
            self::ResearchEnabled, self::PaperEligible, self::ActivePaper => true,
            default => false,
        };
    }

    public function canPaper(): bool
    {
        return match ($this) {
            self::PaperEligible, self::ActivePaper => true,
            default => false,
        };
    }

    public function isActivePaper(): bool
    {
        return $this === self::ActivePaper;
    }

    public function displayName(): string
    {
        return match ($this) {
            self::OfflineDataSource => 'Offline Historical Data Source',
            self::ConnectedPublicData => 'Connected Public Market-Data Source',
            self::ResearchEnabled => 'Research-Enabled Data Source',
            self::PaperEligible => 'PAPER-Eligible Exchange',
            self::ActivePaper => 'Active Owner-Approved PAPER Exchange',
        };
    }
}
