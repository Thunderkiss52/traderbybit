<?php

declare(strict_types=1);

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class BacktestStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'symbols' => ['required', 'array', 'min:1', 'max:2'],
            'symbols.*' => ['required', Rule::in(config('ui.symbols'))],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after:from'],
            'mode' => ['required', 'in:split,walk_forward'],
            'starting_balance' => ['sometimes', Rule::in([(string) config('backtest.starting_balance')])],
            'strategy_version' => ['sometimes', Rule::in([(string) config('strategy.version')])],
            'execution_model_version' => ['sometimes', Rule::in([(string) config('paper.execution_version')])],
        ];
    }
}
