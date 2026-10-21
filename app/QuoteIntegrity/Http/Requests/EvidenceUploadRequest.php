<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class EvidenceUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file'],
            'provenance_class' => ['required', 'string', 'in:ORIGINAL_EXPORT,SCREENSHOT,OPERATOR_TRANSCRIBED,SYNTHETIC_TEST_FIXTURE'],
            'declared_source' => ['required', 'string', 'max:64'],
            'declared_timezone' => ['nullable', 'string', 'max:32'],
            'missing_metadata' => ['nullable', 'array'],
        ];
    }
}
