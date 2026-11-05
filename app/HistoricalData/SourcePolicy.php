<?php

declare(strict_types=1);

namespace App\HistoricalData;

use Illuminate\Validation\ValidationException;

final class SourcePolicy
{
    public function assertUse(string $use, array $license, bool $export = false): void
    {
        if (! in_array($use, $license['permitted_uses'] ?? [], true) || ! in_array($use, (array) config('historical_data.license.permitted_uses'), true) || ($export && config('historical_data.license.exports_enabled') !== true)) {
            throw ValidationException::withMessages(['intended_use' => 'Source policy prohibits this use or export. Commercial use requires separate licensing; technical availability is not permission.']);
        }
    }
}
