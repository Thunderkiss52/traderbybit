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
        return ['interval' => ['sometimes', 'in:5,15,60'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:1000'], 'to' => ['sometimes', 'date']];
    }
}
