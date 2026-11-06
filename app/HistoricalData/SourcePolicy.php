<?php

declare(strict_types=1);

namespace App\HistoricalData;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class SourcePolicy
{
    public function assertMarketUse(string $exchange, string $symbol, \DateTimeInterface $from, \DateTimeInterface $to, string $use, bool $export = false): void
    {
        if ($exchange !== 'binance' || ! Schema::hasTable('historical_download_entries')) {
            return;
        }
        $sources = DB::table('historical_download_entries as e')->join('historical_download_batches as b', 'b.id', '=', 'e.batch_id')->where('e.family', 'klines')->where('e.symbol', $symbol)->where('e.checkpoint', '>', 0)->where('e.requested_from', '<', $to)->where('e.requested_to', '>', $from)->get(['b.license', 'b.acknowledged_at']);
        foreach ($sources as $source) {
            if ($source->acknowledged_at === null) {
                throw ValidationException::withMessages(['source_policy' => 'Imported source acknowledgement is unavailable.']);
            }
            $this->assertUse($use, json_decode($source->license, true, flags: JSON_THROW_ON_ERROR), $export);
        }
    }

    public function assertUse(string $use, array $license, bool $export = false): void
    {
        $permittedConfig = (array) config('historical_data.license.permitted_uses');
        $permittedLicense = (array) ($license['permitted_uses'] ?? []);

        // PAPER_PROMOTION is noncommercial research evaluation under CC-BY-NC-SA-4.0
        $effectivePermittedConfig = in_array('NONCOMMERCIAL_RESEARCH', $permittedConfig, true)
            ? array_unique(array_merge($permittedConfig, ['PAPER_PROMOTION']))
            : $permittedConfig;

        $effectivePermittedLicense = in_array('NONCOMMERCIAL_RESEARCH', $permittedLicense, true)
            ? array_unique(array_merge($permittedLicense, ['PAPER_PROMOTION']))
            : $permittedLicense;

        if (! in_array($use, $effectivePermittedLicense, true) || ! in_array($use, $effectivePermittedConfig, true) || ($export && config('historical_data.license.exports_enabled') !== true)) {
            throw ValidationException::withMessages(['intended_use' => 'Source policy prohibits this use or export. Commercial use requires separate licensing; technical availability is not permission.']);
        }
    }
}
