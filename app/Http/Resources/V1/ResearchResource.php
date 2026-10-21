<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ResearchResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return ['schema_version' => '1.0.0', 'research_mode' => true, 'data' => $this->sanitize($this->resource)];
    }

    private function sanitize(mixed $value): mixed
    {
        if (is_float($value)) {
            return is_finite($value) ? $value : null;
        }
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->sanitize($item), $value);
        }
        if ($value instanceof \Illuminate\Contracts\Support\Arrayable) {
            return $this->sanitize($value->toArray());
        }
        if ($value instanceof \JsonSerializable) {
            return $this->sanitize($value->jsonSerialize());
        }

        return $value;
    }

    public function toResponse($request)
    {
        return parent::toResponse($request)->setStatusCode(200);
    }
}
