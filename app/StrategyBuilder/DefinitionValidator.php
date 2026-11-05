<?php

declare(strict_types=1);

namespace App\StrategyBuilder;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class DefinitionValidator
{
    public const TYPES = ['SMA', 'EMA', 'RSI', 'ATR', 'ADX', 'VOLUME_SMA', 'PRICE', 'OPEN', 'HIGH', 'LOW', 'VOLUME', 'TURNOVER', 'BODY', 'RANGE', 'MACD', 'MACD_SIGNAL', 'MACD_HISTOGRAM', 'BB_UPPER', 'BB_LOWER', 'DONCHIAN_HIGH', 'DONCHIAN_LOW'];

    public function validate(array $definition): array
    {
        Validator::make($definition, [
            'schema_version' => 'required|integer|in:1,2',
            'id' => 'required|string|regex:/^user_[a-z0-9_]{1,48}$/D',
            'version' => 'required|integer|min:1|max:1000000',
            'symbols' => 'required|array|list|min:1|max:20', 'symbols.*' => 'required|string|regex:/^[A-Z0-9]{3,24}$/D|distinct',
            'exchanges' => 'required|array|list|min:1|max:10', 'exchanges.*' => 'required|string|regex:/^[a-z0-9_-]{2,32}$/D|distinct',
            'timeframe' => 'required|in:5,15,30,60',
            'indicators' => 'required|array|min:1|max:32',
            'indicators.*' => 'required|array:type,period,timeframe,slow_period,signal_period,deviation',
            'indicators.*.type' => 'required|in:'.implode(',', self::TYPES),
            'indicators.*.period' => 'required|integer|min:2|max:500',
            'indicators.*.timeframe' => 'required|in:5,15,30,60',
            'indicators.*.slow_period' => 'sometimes|integer|min:3|max:1000',
            'indicators.*.signal_period' => 'sometimes|integer|min:2|max:500',
            'indicators.*.deviation' => 'sometimes|numeric|gt:0|lte:10',
            'entry' => 'required|array:BUY,SELL', 'entry.BUY' => 'required_without:entry.SELL|array', 'entry.SELL' => 'required_without:entry.BUY|array',
            'stop_atr' => 'required|numeric|gt:0|lte:20', 'take_atr' => 'required|numeric|gt:0|lte:100',
            'risk_percent' => 'required|numeric|gt:0|lte:0.5',
            'timeout_bars' => 'required|integer|min:1|max:1000', 'cooldown_bars' => 'required|integer|min:0|max:1000',
            'exit' => 'sometimes|array:BUY,SELL', 'exit.*' => 'array',
            'regime' => 'sometimes|array',
            'partial_exit' => 'sometimes|array:atr,fraction',
            'partial_exit.atr' => 'required_with:partial_exit|numeric|gt:0|lt:take_atr',
            'partial_exit.fraction' => 'required_with:partial_exit|numeric|gt:0|lt:1',
            'funding' => 'sometimes|array:enabled,interval_hours',
            'funding.enabled' => 'required_with:funding|boolean',
            'funding.interval_hours' => 'required_with:funding|integer|in:1,4,8',
            'parameters' => 'sometimes|array|max:12',
            'parameters.*' => 'array:default,min,max,step',
            'parameters.*.default' => 'required|numeric', 'parameters.*.min' => 'required|numeric',
            'parameters.*.max' => 'required|numeric', 'parameters.*.step' => 'required|numeric|gt:0',
        ])->validate();
        foreach (['schema_version', 'version', 'timeout_bars', 'cooldown_bars'] as $key) {
            if (! is_int($definition[$key])) {
                $this->fail($key.' must be a JSON integer.');
            }
        }
        foreach (['stop_atr', 'take_atr', 'risk_percent'] as $key) {
            if ((! is_int($definition[$key]) && ! is_float($definition[$key])) || ! is_finite((float) $definition[$key])) {
                $this->fail($key.' must be a finite JSON number.');
            }
        }
        if (! is_string($definition['timeframe'])) {
            $this->fail('Timeframe must be a JSON string.');
        }
        $keys = ['schema_version', 'id', 'version', 'symbols', 'exchanges', 'timeframe', 'indicators', 'entry', 'stop_atr', 'take_atr', 'risk_percent', 'timeout_bars', 'cooldown_bars', 'exit', 'regime', 'partial_exit', 'funding', 'parameters'];
        if (array_diff(array_keys($definition), $keys)) {
            $this->fail('Unknown definition fields.');
        }
        foreach ($definition['indicators'] as $name => $indicator) {
            if (! is_int($indicator['period']) || ! is_string($indicator['timeframe'])) {
                $this->fail('Indicator period must be an integer and timeframe a string.');
            }
            foreach (['slow_period', 'signal_period'] as $field) {
                if (isset($indicator[$field]) && ! is_int($indicator[$field])) {
                    $this->fail('Indicator periods must be integers.');
                }
            }
            if (isset($indicator['slow_period']) && $indicator['slow_period'] <= $indicator['period']) {
                $this->fail('MACD slow period must exceed fast period.');
            }
            if (isset($indicator['deviation'])) {
                $this->number($indicator['deviation']);
            }
            if (! is_string($name) || ! preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $name) || (int) $indicator['timeframe'] < (int) $definition['timeframe']) {
                $this->fail('Invalid indicator name or timeframe below primary timeframe.');
            }
        }
        foreach ($definition['entry'] as $condition) {
            $this->condition($condition, array_keys($definition['indicators']), 0);
        }
        foreach ($definition['exit'] ?? [] as $condition) {
            $this->condition($condition, array_keys($definition['indicators']), 0);
        }
        if (isset($definition['regime'])) {
            $this->condition($definition['regime'], array_keys($definition['indicators']), 0);
        }
        foreach ($definition['partial_exit'] ?? [] as $number) {
            $this->number($number);
        }
        if (isset($definition['funding']) && (! is_bool($definition['funding']['enabled']) || ! is_int($definition['funding']['interval_hours']))) {
            $this->fail('Funding types must be boolean and integer.');
        }
        foreach ($definition['parameters'] ?? [] as $path => $range) {
            Validator::make($range, ['default' => 'required|numeric', 'min' => 'required|numeric', 'max' => 'required|numeric', 'step' => 'required|numeric|gt:0'])->validate();
            if (array_diff(array_keys($range), ['default', 'min', 'max', 'step'])) {
                $this->fail('Unknown parameter range fields.');
            }
            if (! preg_match('/^(?:stop_atr|take_atr|risk_percent|timeout_bars|cooldown_bars|indicators\.[a-z][a-z0-9_]*\.(?:period|slow_period|signal_period|deviation))$/D', $path)) {
                $this->fail('Unsupported optimization path.');
            }
            foreach ($range as $number) {
                $this->number($number);
            }
            $current = Arr::get($definition, $path);
            if (! is_numeric($current) || $range['default'] != $current || $range['min'] > $range['default'] || $range['default'] > $range['max']) {
                $this->fail('Parameter default must match the definition and lie inside its range.');
            }
            foreach (['min', 'max'] as $edge) {
                $candidate = $definition;
                unset($candidate['parameters']);
                $value = $range[$edge];
                if (is_int($current)) {
                    if (floor($value) != $value || floor($range['step']) != $range['step']) {
                        $this->fail('Integer parameters require integer bounds and steps.');
                    } $value = (int) $value;
                }
                Arr::set($candidate, $path, $value);
                $this->validate($candidate);
            }
        }

        return $definition;
    }

    private function condition(array $node, array $names, int $depth): void
    {
        if ($depth > 8) {
            $this->fail('Condition nesting exceeds 8.');
        }
        if (count($node) === 1 && isset($node['not']) && is_array($node['not'])) {
            $this->condition($node['not'], $names, $depth + 1);

            return;
        }
        foreach (['and', 'or'] as $op) {
            if (count($node) === 1 && isset($node[$op]) && is_array($node[$op]) && array_is_list($node[$op]) && count($node[$op]) >= 1 && count($node[$op]) <= 16) {
                foreach ($node[$op] as $child) {
                    if (! is_array($child)) {
                        $this->fail('Invalid condition.');
                    }
                    $this->condition($child, $names, $depth + 1);
                }

                return;
            }
        }
        Validator::make($node, ['op' => 'required|in:gt,lt,gte,lte,crosses_above,crosses_below,within,change_pct', 'left' => 'required|string|in:'.implode(',', $names), 'right' => 'required'])->validate();
        if (array_diff(array_keys($node), ['op', 'left', 'right'])) {
            $this->fail('Unknown condition fields.');
        }
        $right = $node['right'];
        if ($node['op'] === 'within') {
            if (! is_array($right) || ! array_is_list($right) || count($right) !== 2 || (! is_int($right[0]) && ! is_float($right[0])) || (! is_int($right[1]) && ! is_float($right[1])) || $right[0] > $right[1]) {
                $this->fail('Range requires ordered numeric bounds.');
            }
        } elseif (! is_int($right) && ! is_float($right) && (! is_string($right) || ! in_array($right, $names, true))) {
            $this->fail('Operand must be an indicator name or JSON number.');
        }
        if ($node['op'] === 'change_pct' && ! is_int($right) && ! is_float($right)) {
            $this->fail('Percentage must be numeric.');
        }
        if (is_int($right) || is_float($right)) {
            $this->number($right);
        }
        if (is_array($right)) {
            foreach ($right as $bound) {
                $this->number($bound);
            }
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['definition' => $message]);
    }

    private function number(mixed $value): void
    {
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
            $this->fail('Finite JSON numbers are required.');
        }
    }
}
