<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QimStatusResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'feature_enabled' => $this->resource['feature_enabled'],
            'measurement_mode' => $this->resource['measurement_mode'],
            'measurement_cap_reached' => $this->resource['measurement_cap_reached'],
            'level_1_status' => $this->resource['level_1_status'],
            'level_2_status' => $this->resource['level_2_status'],
            'clock_sync_state' => $this->resource['clock_sync_state'],
            'rules_version' => $this->resource['rules_version'],
            'cross_source_reason' => 'Independent cross-source monitoring (Level 2) is unavailable; candidate sources remain UNDETERMINED.',
            'disclaimers' => [
                'Internal Bybit consistency monitoring does not establish independent market-price correctness.',
                'CROSS_SOURCE_UNAVAILABLE: No independent second source admitted; Level 2 comparison is not active.',
            ],
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
