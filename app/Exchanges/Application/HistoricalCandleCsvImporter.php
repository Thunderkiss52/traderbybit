<?php

declare(strict_types=1);

namespace App\Exchanges\Application;

use App\Exchanges\Infrastructure\Persistence\ExchangeRecord;
use App\MarketData\Application\MarketCandleConflictService;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final readonly class HistoricalCandleCsvImporter
{
    public function __construct(
        private MarketCandleConflictService $conflictService,
    ) {}

    /**
     * Parse and import a historical candle CSV dataset with strict validation and conflict auditing.
     *
     * @param  string  $csvContent  CSV file content
     * @return array{
     *     exchange_id: string,
     *     symbol: string,
     *     interval: string,
     *     total_rows_processed: int,
     *     imported_bars: int,
     *     duplicates_skipped: int,
     *     conflicts_recorded: int,
     *     first_bar_open_time: ?string,
     *     last_bar_open_time: ?string,
     *     checksum: string
     * }
     */
    public function import(
        string $exchangeId,
        string $symbol,
        string $interval,
        string $csvContent,
        string $category = 'linear'
    ): array {
        $exchangeId = strtolower(trim($exchangeId));
        $symbol = strtoupper(trim($symbol));
        $interval = trim($interval);

        $exchange = ExchangeRecord::query()->find($exchangeId);
        if ($exchange === null) {
            throw new InvalidArgumentException("Cannot import dataset for unregistered exchange '{$exchangeId}'.");
        }

        if ($symbol === '') {
            throw new InvalidArgumentException('Symbol cannot be empty.');
        }

        $intervalSeconds = $this->intervalToSeconds($interval);

        $lines = preg_split('/\r\n|\r|\n/', trim($csvContent));
        if (empty($lines) || count($lines) < 2) {
            throw new InvalidArgumentException('CSV content must contain a valid header and at least one data row.');
        }

        $headerLine = array_shift($lines);
        $header = str_getcsv(strtolower((string) $headerLine));
        $colIndex = $this->parseHeaderColumns($header);

        $imported = 0;
        $duplicates = 0;
        $conflicts = 0;
        $firstTime = null;
        $lastTime = null;
        $prevTime = null;
        $hash = hash_init('sha256');

        $rowsToInsert = [];
        $lineNum = 1;

        foreach ($lines as $line) {
            $lineNum++;
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            $fields = str_getcsv($trimmed);
            if (count($fields) < count($colIndex)) {
                throw new InvalidArgumentException("Malformed CSV row at line {$lineNum}: insufficient columns.");
            }

            $rawTime = trim((string) ($fields[$colIndex['time']] ?? ''));
            $open = trim((string) ($fields[$colIndex['open']] ?? ''));
            $high = trim((string) ($fields[$colIndex['high']] ?? ''));
            $low = trim((string) ($fields[$colIndex['low']] ?? ''));
            $close = trim((string) ($fields[$colIndex['close']] ?? ''));
            $volume = trim((string) ($fields[$colIndex['volume']] ?? ''));
            $turnover = isset($colIndex['turnover']) ? trim((string) ($fields[$colIndex['turnover']] ?? '')) : null;

            // Normalize timestamp
            $dt = $this->parseTimestamp($rawTime, $intervalSeconds, $lineNum);
            $timeIso = $dt->format('Y-m-d H:i:s.uP');

            // Monotonicity check
            if ($prevTime !== null && $dt <= $prevTime) {
                throw new InvalidArgumentException("Non-monotonic timestamp at line {$lineNum}: {$timeIso} must be strictly after {$prevTime->format('Y-m-d H:i:s.uP')}.");
            }
            $prevTime = $dt;

            if ($firstTime === null) {
                $firstTime = $timeIso;
            }
            $lastTime = $timeIso;

            // Validate numerical OHLCV
            $this->validateOhlcv($open, $high, $low, $close, $volume, $lineNum);

            // Compute default turnover if missing: close * volume
            if ($turnover === null || $turnover === '') {
                $turnover = (string) BigDecimal::of($close)->multipliedBy(BigDecimal::of($volume));
            }

            hash_update($hash, implode('|', [$symbol, $interval, $timeIso, $open, $high, $low, $close, $volume, $turnover])."\n");

            // Check if candle already exists
            $existing = CandleRecord::query()->where([
                'exchange' => $exchangeId,
                'category' => $category,
                'symbol' => $symbol,
                'interval' => $interval,
                'open_time' => $timeIso,
            ])->first();

            if ($existing !== null) {
                // Compare values
                if ($this->candlesMatch($existing, $open, $high, $low, $close, $volume)) {
                    $duplicates++;

                    continue;
                }

                $conflictCol = null;
                if ((string) $existing->close !== $close) {
                    $conflictCol = 'close';
                } elseif ((string) $existing->open !== $open) {
                    $conflictCol = 'open';
                } elseif ((string) $existing->high !== $high) {
                    $conflictCol = 'high';
                } elseif ((string) $existing->low !== $low) {
                    $conflictCol = 'low';
                } elseif ((string) $existing->volume !== $volume) {
                    $conflictCol = 'volume';
                }

                // Discrepancy detected: record conflict evidence!
                try {
                    $this->conflictService->recordConflict(
                        $exchangeId,
                        $category,
                        $symbol,
                        $interval,
                        $dt->format(DATE_ATOM),
                        [
                            'open' => (string) $existing->open,
                            'high' => (string) $existing->high,
                            'low' => (string) $existing->low,
                            'close' => (string) $existing->close,
                            'volume' => (string) $existing->volume,
                        ],
                        [
                            'open' => $open,
                            'high' => $high,
                            'low' => $low,
                            'close' => $close,
                            'volume' => $volume,
                        ],
                        $conflictCol,
                        "CSV import conflict at line {$lineNum}: incoming candle differs from stored values.",
                        "csv_import:{$exchangeId}:{$symbol}"
                    );
                } catch (Throwable) {
                    DB::table('market_candle_conflicts')->insert([
                        'exchange' => $exchangeId,
                        'category' => $category,
                        'symbol' => $symbol,
                        'interval' => $interval,
                        'open_time' => $timeIso,
                        'stored_values' => json_encode([
                            'open' => (string) $existing->open,
                            'high' => (string) $existing->high,
                            'low' => (string) $existing->low,
                            'close' => (string) $existing->close,
                            'volume' => (string) $existing->volume,
                        ], JSON_THROW_ON_ERROR),
                        'incoming_values' => json_encode([
                            'open' => $open,
                            'high' => $high,
                            'low' => $low,
                            'close' => $close,
                            'volume' => $volume,
                        ], JSON_THROW_ON_ERROR),
                        'conflict_column' => $conflictCol,
                        'error_message' => "CSV import conflict at line {$lineNum}: incoming candle differs from stored values.",
                        'source_provenance' => "csv_import:{$exchangeId}:{$symbol}",
                        'first_seen_at' => now('UTC'),
                        'last_seen_at' => now('UTC'),
                        'occurrence_count' => 1,
                        'status' => 'UNRESOLVED',
                        'payload_fingerprint' => hash('sha256', "{$exchangeId}:{$symbol}:{$interval}:{$timeIso}:{$close}"),
                        'created_at' => now('UTC'),
                        'updated_at' => now('UTC'),
                    ]);
                }
                $conflicts++;

                continue;
            }

            $rowsToInsert[] = [
                'exchange' => $exchangeId,
                'category' => $category,
                'symbol' => $symbol,
                'interval' => $interval,
                'open_time' => $timeIso,
                'open' => $open,
                'high' => $high,
                'low' => $low,
                'close' => $close,
                'volume' => $volume,
                'turnover' => $turnover,
                'is_closed' => true,
                'created_at' => $timeIso,
                'updated_at' => $timeIso,
            ];

            if (count($rowsToInsert) >= 200) {
                CandleRecord::query()->insert($rowsToInsert);
                $imported += count($rowsToInsert);
                $rowsToInsert = [];
            }
        }

        if (! empty($rowsToInsert)) {
            CandleRecord::query()->insert($rowsToInsert);
            $imported += count($rowsToInsert);
        }

        $checksum = hash_final($hash);

        // Update exchange capabilities
        $capabilities = (array) ($exchange->capabilities ?? []);
        $capabilities['has_candles'] = true;
        $supportedTimeframes = (array) ($capabilities['supported_timeframes'] ?? []);
        if (! in_array($interval, $supportedTimeframes, true)) {
            $supportedTimeframes[] = $interval;
            $capabilities['supported_timeframes'] = array_values(array_unique($supportedTimeframes));
        }

        $metadata = (array) ($exchange->metadata ?? []);
        $metadata['last_imported_dataset'] = [
            'symbol' => $symbol,
            'interval' => $interval,
            'imported_bars' => $imported,
            'duplicates_skipped' => $duplicates,
            'conflicts_recorded' => $conflicts,
            'first_bar' => $firstTime,
            'last_bar' => $lastTime,
            'checksum' => $checksum,
            'at' => now('UTC')->toISOString(),
        ];

        $exchange->update([
            'capabilities' => $capabilities,
            'metadata' => $metadata,
        ]);

        return [
            'exchange_id' => $exchangeId,
            'symbol' => $symbol,
            'interval' => $interval,
            'total_rows_processed' => $imported + $duplicates + $conflicts,
            'imported_bars' => $imported,
            'duplicates_skipped' => $duplicates,
            'conflicts_recorded' => $conflicts,
            'first_bar_open_time' => $firstTime,
            'last_bar_open_time' => $lastTime,
            'checksum' => $checksum,
        ];
    }

    private function parseHeaderColumns(array $header): array
    {
        $map = [];
        foreach ($header as $idx => $name) {
            $cleaned = trim(str_replace(['"', "'", ' '], '', $name));
            if (in_array($cleaned, ['open_time', 'timestamp', 'time', 'datetime', 'date'], true)) {
                $map['time'] = $idx;
            } elseif ($cleaned === 'open') {
                $map['open'] = $idx;
            } elseif ($cleaned === 'high') {
                $map['high'] = $idx;
            } elseif ($cleaned === 'low') {
                $map['low'] = $idx;
            } elseif ($cleaned === 'close') {
                $map['close'] = $idx;
            } elseif ($cleaned === 'volume' || $cleaned === 'vol') {
                $map['volume'] = $idx;
            } elseif ($cleaned === 'turnover' || $cleaned === 'quote_volume') {
                $map['turnover'] = $idx;
            }
        }

        foreach (['time', 'open', 'high', 'low', 'close', 'volume'] as $req) {
            if (! isset($map[$req])) {
                throw new InvalidArgumentException("CSV header missing required column '{$req}'.");
            }
        }

        return $map;
    }

    private function parseTimestamp(string $raw, int $intervalSeconds, int $lineNum): DateTimeImmutable
    {
        if (is_numeric($raw)) {
            $num = (float) $raw;
            // Handle milliseconds vs seconds
            $seconds = $num > 10000000000 ? (int) floor($num / 1000) : (int) $num;
            $dt = DateTimeImmutable::createFromFormat('U', (string) $seconds, new \DateTimeZone('UTC'));
        } else {
            try {
                $dt = new DateTimeImmutable($raw, new \DateTimeZone('UTC'));
            } catch (Throwable) {
                throw new InvalidArgumentException("Invalid timestamp format '{$raw}' at line {$lineNum}.");
            }
        }

        if ($dt === false) {
            throw new InvalidArgumentException("Unable to parse timestamp '{$raw}' at line {$lineNum}.");
        }

        // Align to interval boundary if applicable
        if ($intervalSeconds > 0 && ($dt->getTimestamp() % $intervalSeconds !== 0)) {
            throw new InvalidArgumentException("Timestamp '{$raw}' at line {$lineNum} is not aligned to the {$intervalSeconds}s interval boundary.");
        }

        return $dt->setTimezone(new \DateTimeZone('UTC'));
    }

    private function validateOhlcv(string $open, string $high, string $low, string $close, string $volume, int $lineNum): void
    {
        foreach (['open' => $open, 'high' => $high, 'low' => $low, 'close' => $close, 'volume' => $volume] as $name => $val) {
            if (! is_numeric($val)) {
                throw new InvalidArgumentException("Non-numeric {$name} '{$val}' at line {$lineNum}.");
            }
        }

        $o = BigDecimal::of($open);
        $h = BigDecimal::of($high);
        $l = BigDecimal::of($low);
        $c = BigDecimal::of($close);
        $v = BigDecimal::of($volume);

        if ($o->isLessThanOrEqualTo(0) || $h->isLessThanOrEqualTo(0) || $l->isLessThanOrEqualTo(0) || $c->isLessThanOrEqualTo(0)) {
            throw new InvalidArgumentException("OHLC values must be positive numbers at line {$lineNum}.");
        }

        if ($v->isLessThan(0)) {
            throw new InvalidArgumentException("Volume cannot be negative at line {$lineNum}.");
        }

        if ($h->isLessThan($l)) {
            throw new InvalidArgumentException("High ({$high}) cannot be less than Low ({$low}) at line {$lineNum}.");
        }

        if ($h->isLessThan($o) || $h->isLessThan($c)) {
            throw new InvalidArgumentException("High ({$high}) must be >= Open ({$open}) and Close ({$close}) at line {$lineNum}.");
        }

        if ($l->isGreaterThan($o) || $l->isGreaterThan($c)) {
            throw new InvalidArgumentException("Low ({$low}) must be <= Open ({$open}) and Close ({$close}) at line {$lineNum}.");
        }
    }

    private function candlesMatch(CandleRecord $existing, string $open, string $high, string $low, string $close, string $volume): bool
    {
        $tolerance = BigDecimal::of('0.00000001');

        foreach ([
            ['open', $open],
            ['high', $high],
            ['low', $low],
            ['close', $close],
            ['volume', $volume],
        ] as [$field, $incoming]) {
            $diff = BigDecimal::of((string) $existing->{$field})->minus(BigDecimal::of($incoming))->abs();
            if ($diff->isGreaterThan($tolerance)) {
                return false;
            }
        }

        return true;
    }

    private function intervalToSeconds(string $interval): int
    {
        return match (strtoupper($interval)) {
            '1' => 60,
            '3' => 180,
            '5' => 300,
            '15' => 900,
            '30' => 1800,
            '60', '1H' => 3600,
            '120', '2H' => 7200,
            '240', '4H' => 14400,
            'D', '1D' => 86400,
            'W', '1W' => 604800,
            default => (int) $interval * 60,
        };
    }
}
