<?php

declare(strict_types=1);

namespace App\Audit\Backtest;

/** Immutable CSV encoding rules; never allow a broker/user string to become a spreadsheet formula. */
final class BacktestAuditCsv
{
    public static function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
        $text = (string) $value;
        // Legitimate signed decimal values remain machine-readable in CSV.
        if (preg_match('/^[+\-]?(?:[0-9]+)(?:\.[0-9]+)?$/D', $text)) {
            return $text;
        }
        if (preg_match('/^[\s\p{Z}\p{Cf}]*[=+\-@]/u', $text)) {
            return "'".$text;
        }

        return $text;
    }
}
