<?php

declare(strict_types=1);

namespace App\HistoricalData;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class DownloadPlanner
{
    public const INTERVALS = ['1m' => 60, '5m' => 300, '15m' => 900, '30m' => 1800, '1h' => 3600];

    public function plan(array $input): array
    {
        $data = Validator::make($input, [
            'provider' => 'required|in:BINANCE_VISION', 'market' => 'required|in:futures/um',
            'symbols' => 'required|array|list|min:1|max:10', 'symbols.*' => 'required|distinct|in:'.implode(',', config('historical_data.symbols')),
            'intervals' => 'required|array|list|min:1|max:5', 'intervals.*' => 'required|distinct|in:'.implode(',', array_keys(self::INTERVALS)),
            'from' => 'required|string|date', 'to' => 'required|string|date|after:from', 'funding' => 'required|boolean',
            'mark_prices' => 'sometimes|boolean', 'intended_use' => 'required|in:NONCOMMERCIAL_RESEARCH,NONCOMMERCIAL_LEARNING',
        ])->validate();
        if (array_diff(array_keys($input), array_keys($data))) {
            throw ValidationException::withMessages(['plan' => 'Unknown planning fields or user URLs are prohibited.']);
        }
        $from = CarbonImmutable::parse($data['from'])->utc();
        $to = CarbonImmutable::parse($data['to'])->utc()->addMillisecond();
        if ($from->timestamp < 1567296000 || $to->isFuture() || $to->diffInDays($from, absolute: true) > 1096 || $from->getTimestampMs() % 60000 !== 0 || $to->getTimestampMs() % 60000 !== 0) {
            throw ValidationException::withMessages(['range' => 'Use UTC minute-aligned start and inclusive end (ending .999), at most 1096 historical days after September 2019.']);
        }
        sort($data['symbols']);
        usort($data['intervals'], fn ($a, $b) => self::INTERVALS[$a] <=> self::INTERVALS[$b]);
        $entries = [];
        $expected = 0;
        foreach ($data['symbols'] as $symbol) {
            foreach ($data['intervals'] as $tf) {
                $step = self::INTERVALS[$tf];
                $first = (int) ceil($from->timestamp / $step) * $step;
                $expected += max(0, intdiv($to->timestamp - $first, $step));
            }
            for ($month = $from->startOfMonth(); $month < $to; $month = $month->addMonth()) {
                foreach ($data['intervals'] as $tf) {
                    $entries[] = $this->entry($symbol, 'klines', $tf, $month, $from, $to);
                }
                if ($data['funding']) {
                    $entries[] = $this->entry($symbol, 'fundingRate', null, $month, $from, $to);
                    $entries[] = $this->entry($symbol, 'fundingApi', null, $month, $from, $to);
                }
                if ($data['mark_prices'] ?? false) {
                    $entries[] = $this->entry($symbol, 'markPriceKlines', '1m', $month, $from, $to);
                }
            }
        }
        $candleFiles = count(array_filter($entries, fn ($e) => $e['family'] === 'klines'));

        return ['provider' => 'BINANCE_VISION', 'exchange' => 'binance', 'market' => 'futures/um', 'category' => 'linear', 'from' => $from->toISOString(), 'to_exclusive' => $to->toISOString(), 'input' => $data, 'entries' => $entries,
            'estimate' => ['candle_archives' => $candleFiles, 'zip_archives' => count(array_filter($entries, fn ($e) => $e['family'] !== 'fundingApi')), 'funding_api_windows' => count(array_filter($entries, fn ($e) => $e['family'] === 'fundingApi')), 'expected_candles' => $expected, 'utc_days' => ($to->getTimestampMs() - $from->getTimestampMs()) / 86400000, 'estimated_database_bytes' => $expected * 800, 'estimated_download_bytes' => $expected * 100, 'temporary_peak_bytes' => config('historical_data.max_staging_bytes'), 'reserved_disk_bytes' => config('historical_data.reserve_disk_bytes'), 'estimates_only' => true], 'license' => config('historical_data.license')];
    }

    public function entry(string $symbol, string $family, ?string $interval, CarbonImmutable $period, CarbonImmutable $from, CarbonImmutable $to, string $granularity = 'monthly'): array
    {
        $end = $granularity === 'monthly' ? $period->addMonth() : $period->addDay();
        $stamp = $period->format($granularity === 'monthly' ? 'Y-m' : 'Y-m-d');
        $filename = $symbol.'-'.($interval ?? 'fundingRate').'-'.$stamp.($family === 'fundingApi' ? '.json' : '.zip');
        $url = $family === 'fundingApi' ? 'https://fapi.binance.com/fapi/v1/fundingRate' : 'https://data.binance.vision/data/futures/um/'.$granularity.'/'.$family.'/'.$symbol.'/'.($interval !== null ? $interval.'/' : '').$filename;

        return ['family' => $family, 'symbol' => $symbol, 'interval' => $interval, 'period' => $stamp, 'granularity' => $granularity, 'url' => $url, 'checksum_url' => $family === 'fundingApi' ? null : $url.'.CHECKSUM', 'filename' => $filename, 'source_from' => $period->toISOString(), 'source_to' => $end->toISOString(), 'requested_from' => $from->max($period)->toISOString(), 'requested_to' => $to->min($end)->toISOString()];
    }
}
