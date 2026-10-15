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
        return ['schema_version' => '1.0.0', 'research_mode' => true, 'data' => $this->resource];
    }
}
