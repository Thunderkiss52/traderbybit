<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QimIncidentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'incident_key' => $this->incident_key,
            'status' => $this->status,
            'severity' => $this->severity,
            'priority' => $this->priority,
            'title' => $this->title,
            'description' => $this->description,
            'provider' => $this->provider,
            'category' => $this->category,
            'symbol' => $this->symbol,
            'first_detected_at' => $this->first_detected_at?->toIso8601String(),
            'last_detected_at' => $this->last_detected_at?->toIso8601String(),
            'occurrence_count' => $this->occurrence_count,
            'resolution_notes' => $this->resolution_notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
