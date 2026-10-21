<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\Research\Domain\MetricRegistry;

final class MetricRegistryService
{
    /**
     * @return array{
     *     version: string,
     *     accounting_scope_rules: array<string, string>,
     *     prohibitions: list<string>,
     *     metrics: array<string, mixed>
     * }
     */
    public function getCatalog(): array
    {
        return [
            'version' => MetricRegistry::VERSION,
            'accounting_scope_rules' => [
                'FULL_LIFECYCLE' => 'Available complete lifecycle as of extraction cutoff. Includes all closed and partially closed positions. Never mix with event-window cashflows.',
                'WINDOW_FLOW' => 'Cashflows, fills, and equity snapshots strictly within [start, end). Must explicitly state inventory boundaries.',
                'EVENT_EXECUTION' => 'Atomic per-execution observation. Stored slippage is a price delta (|fill - ref|), not total currency drag.',
            ],
            'prohibitions' => [
                'Do not aggregate FULL_LIFECYCLE and WINDOW_FLOW metrics into a single mixed sum.',
                'Do not deduct slippage from Net PnL a second time (fill price already reflects slippage).',
                'Do not use 0 as a fallback for missing denominators or undefined financial ratios.',
                'Do not invent margin metrics when margin fields are not recorded in the ledger.',
            ],
            'metrics' => MetricRegistry::all(),
        ];
    }
}
