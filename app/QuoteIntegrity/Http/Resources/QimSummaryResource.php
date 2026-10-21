<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QimSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'from' => $this->resource['from'],
            'to' => $this->resource['to'],
            'total_comparisons' => $this->resource['total_comparisons'],
            'matched_comparisons' => $this->resource['matched_comparisons'],
            'channel_inconsistencies' => $this->resource['channel_inconsistencies'],
            'duplicate_counts' => $this->resource['duplicate_counts'],
            'gap_events_count' => $this->resource['gap_events_count'],
            'stale_observations_count' => $this->resource['stale_observations_count'],
            'incidents_count' => $this->resource['incidents_count'],
            'cross_source_status' => 'CROSS_SOURCE_UNAVAILABLE',
            'calculated_at' => $this->resource['calculated_at'],
        ];
    }
}
