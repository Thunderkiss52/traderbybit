<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QimComparisonResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'comparison_id' => $this->comparison_id,
            'version' => $this->version,
            'provider' => $this->provider,
            'category' => $this->category,
            'symbol' => $this->symbol,
            'interval' => $this->interval,
            'candle_open_time' => $this->candle_open_time?->toIso8601String(),
            'candle_close_time' => $this->candle_close_time?->toIso8601String(),
            'classification' => $this->classification,
            'price_diff_abs' => $this->price_diff_abs,
            'price_diff_rel' => $this->price_diff_rel,
            'volume_diff_abs' => $this->volume_diff_abs,
            'volume_diff_rel' => $this->volume_diff_rel,
            'timestamp_skew_ms' => $this->timestamp_skew_ms,
            'is_comparable' => $this->is_comparable,
            'explanation' => $this->explanation,
            'compared_at' => $this->compared_at?->toIso8601String(),
            'rest_observation' => $this->whenLoaded('restObservation', function () {
                return $this->restObservation ? [
                    'id' => $this->restObservation->id,
                    'open' => (string) $this->restObservation->open,
                    'high' => (string) $this->restObservation->high,
                    'low' => (string) $this->restObservation->low,
                    'close' => (string) $this->restObservation->close,
                    'volume' => (string) $this->restObservation->volume,
                    'request_sent_at' => $this->restObservation->request_sent_at?->toIso8601String(),
                    'response_received_at' => $this->restObservation->response_received_at?->toIso8601String(),
                    'payload_sha256' => $this->restObservation->payload_sha256,
                ] : null;
            }),
            'ws_observation' => $this->whenLoaded('wsObservation', function () {
                return $this->wsObservation ? [
                    'id' => $this->wsObservation->id,
                    'open' => (string) $this->wsObservation->open,
                    'high' => (string) $this->wsObservation->high,
                    'low' => (string) $this->wsObservation->low,
                    'close' => (string) $this->wsObservation->close,
                    'volume' => (string) $this->wsObservation->volume,
                    'provider_event_time' => $this->wsObservation->provider_event_time?->toIso8601String(),
                    'local_received_at' => $this->wsObservation->local_received_at?->toIso8601String(),
                    'payload_sha256' => $this->wsObservation->payload_sha256,
                ] : null;
            }),
        ];
    }
}
