<?php

declare(strict_types=1);

namespace App\Strategy\Domain;

enum MethodImplementationStatus: string
{
    case Catalogued = 'CATALOGUED';
    case Implemented = 'IMPLEMENTED';
    case ResearchReady = 'RESEARCH_READY';
    case ShadowReady = 'SHADOW_READY';
    case PaperReady = 'PAPER_READY';
    case BlockedByData = 'BLOCKED_BY_DATA';
    case Unsupported = 'UNSUPPORTED';
    case Deprecated = 'DEPRECATED';

    public function isExecutable(): bool
    {
        return match ($this) {
            self::Implemented, self::ResearchReady, self::ShadowReady, self::PaperReady => true,
            default => false,
        };
    }

    public function displayName(): string
    {
        return match ($this) {
            self::Catalogued => 'Catalogued (Specification Defined)',
            self::Implemented => 'Implemented in Algorithmic Code',
            self::ResearchReady => 'Research Ready (Executable & Backtestable)',
            self::ShadowReady => 'Shadow Ready (Eligible for Forward Shadow Testing)',
            self::PaperReady => 'PAPER Ready (Approved for Virtual PAPER Execution)',
            self::BlockedByData => 'Blocked by Missing Data Dependency',
            self::Unsupported => 'Unsupported on Selected Venue',
            self::Deprecated => 'Deprecated Method',
        };
    }
}
