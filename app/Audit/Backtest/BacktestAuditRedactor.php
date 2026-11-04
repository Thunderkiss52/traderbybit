<?php

declare(strict_types=1);

namespace App\Audit\Backtest;

/** Defense in depth: persisted backtest configuration may contain user-supplied metadata. */
final class BacktestAuditRedactor
{
    public static function sanitize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $entry) {
            if (is_string($key) && preg_match('/(?:secret|password|credential|api[_-]?key|access[_-]?token|private[_-]?key|authorization)/i', $key)) {
                $out[$key] = '[REDACTED]';

                continue;
            }
            $out[$key] = self::sanitize($entry);
        }

        return $out;
    }
}
