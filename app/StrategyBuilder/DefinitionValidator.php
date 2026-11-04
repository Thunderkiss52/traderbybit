<?php

declare(strict_types=1);

namespace App\StrategyBuilder;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class DefinitionValidator
{
    public const TYPES = ["SMA", "EMA", "RSI", "ATR", "VOLUME_SMA", "PRICE", "MACD", "ADX", "BB_UPPER", "BB_LOWER", "DONCHIAN_HIGH", "DONCHIAN_LOW"];

    public function validate(array $definition): array
    {
        Validator::make($definition, [
            "schema_version" => "required|integer|in:1",
            "id" => "required|string|regex:/^user_[a-z0-9_]{1,48}$/D",
            "version" => "required|integer|min:1|max:1000000",
            "symbols" => "required|array|min:1|max:20", "symbols.*" => "required|string|regex:/^[A-Z0-9]{3,24}$/D|distinct",
            "exchanges" => "required|array|min:1|max:10", "exchanges.*" => "required|string|regex:/^[a-z0-9_-]{2,32}$/D|distinct",
            "timeframe" => "required|in:5,15,30,60",
            "indicators" => "required|array|min:1|max:32",
            "indicators.*" => "required|array:type,period,timeframe",
            "indicators.*.type" => "required|in:".implode(",", self::TYPES),
            "indicators.*.period" => "required|integer|min:2|max:500",
            "indicators.*.timeframe" => "required|in:5,15,30,60",
            "entry" => "required|array:BUY,SELL", "entry.BUY" => "required_without:entry.SELL|array", "entry.SELL" => "required_without:entry.BUY|array",
            "stop_atr" => "required|numeric|gt:0|lte:20", "take_atr" => "required|numeric|gt:0|lte:100",
            "risk_percent" => "required|numeric|gt:0|lte:0.5",
            "timeout_bars" => "required|integer|min:1|max:1000", "cooldown_bars" => "required|integer|min:0|max:1000",
        ])->validate();
        $keys = ["schema_version", "id", "version", "symbols", "exchanges", "timeframe", "indicators", "entry", "stop_atr", "take_atr", "risk_percent", "timeout_bars", "cooldown_bars"];
        if (array_diff(array_keys($definition), $keys)) {
            $this->fail("Unknown definition fields.");
        }
        foreach ($definition["indicators"] as $name => $indicator) {
            if (! is_string($name) || ! preg_match("/^[a-z][a-z0-9_]{0,31}$/D", $name) || (int) $indicator["timeframe"] < (int) $definition["timeframe"]) {
                $this->fail("Invalid indicator name or timeframe below primary timeframe.");
            }
        }
        foreach ($definition["entry"] as $condition) {
            $this->condition($condition, array_keys($definition["indicators"]), 0);
        }

        return $definition;
    }

    private function condition(array $node, array $names, int $depth): void
    {
        if ($depth > 8) {
            $this->fail("Condition nesting exceeds 8.");
        }
        if (count($node) === 1 && isset($node["not"]) && is_array($node["not"])) {
            $this->condition($node["not"], $names, $depth + 1);
            return;
        }
        foreach (["and", "or"] as $op) {
            if (count($node) === 1 && isset($node[$op]) && is_array($node[$op]) && array_is_list($node[$op]) && count($node[$op]) >= 1 && count($node[$op]) <= 16) {
                foreach ($node[$op] as $child) {
                    if (! is_array($child)) { $this->fail("Invalid condition."); }
                    $this->condition($child, $names, $depth + 1);
                }
                return;
            }
        }
        Validator::make($node, ["op" => "required|in:gt,lt,gte,lte,crosses_above,crosses_below,within,change_pct", "left" => "required|string|in:".implode(",", $names), "right" => "required"])->validate();
        if (array_diff(array_keys($node), ["op", "left", "right"])) { $this->fail("Unknown condition fields."); }
        $right = $node["right"];
        if ($node["op"] === "within") {
            if (! is_array($right) || ! array_is_list($right) || count($right) !== 2 || ! is_numeric($right[0]) || ! is_numeric($right[1]) || $right[0] > $right[1]) { $this->fail("Range requires ordered numeric bounds."); }
        } elseif (! is_int($right) && ! is_float($right) && (! is_string($right) || ! in_array($right, $names, true))) {
            $this->fail("Operand must be an indicator name or JSON number.");
        }
        if ($node["op"] === "change_pct" && ! is_int($right) && ! is_float($right)) { $this->fail("Percentage must be numeric."); }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(["definition" => $message]);
    }
}
