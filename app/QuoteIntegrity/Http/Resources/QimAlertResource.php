<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QimAlertResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'alert_key' => $this->alert_key,
            'rule_version' => $this->rule_version,
            'rule_key' => $this->rule_key,
            'incident_group' => $this->incident_group,
            'window_start' => $this->window_start?->toIso8601String(),
            'window_end' => $this->window_end?->toIso8601String(),
            'severity' => $this->severity,
            'symbol' => $this->symbol,
            'title' => $this->title,
            'payload' => $this->payload,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
