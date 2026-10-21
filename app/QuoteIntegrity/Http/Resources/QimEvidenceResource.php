<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QimEvidenceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'file_uuid' => $this->file_uuid,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'file_size_bytes' => $this->file_size_bytes,
            'sha256_hash' => $this->sha256_hash,
            'provenance_class' => $this->provenance_class,
            'declared_source' => $this->declared_source,
            'declared_timezone' => $this->declared_timezone,
            'missing_metadata' => $this->missing_metadata,
            'investigation_status' => $this->investigation_status,
            'uploaded_by' => $this->uploaded_by,
            'uploaded_at' => $this->uploaded_at?->toIso8601String(),
            'disclaimers' => [
                'SHA-256 proves bytes unchanged after capture. Not a verification of quote authenticity.',
                'A screenshot or transcribed export shows what was displayed in an interface, not official market data.',
            ],
        ];
    }
}
