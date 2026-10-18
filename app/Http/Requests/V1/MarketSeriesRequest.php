<?php

declare(strict_types=1);

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

final class MarketSeriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxLimit = $this->input('view_mode') === '7d' ? 'max:1000' : 'max:300';

        return [
            'interval' => ['sometimes', 'in:5,15,60'],
            'limit' => ['sometimes', 'integer', 'min:1', $maxLimit],
            'view_mode' => ['sometimes', 'in:today,7d'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ];
    }
}
