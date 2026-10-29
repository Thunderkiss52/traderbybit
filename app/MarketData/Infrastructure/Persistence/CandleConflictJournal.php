<?php

declare(strict_types=1);

namespace App\MarketData\Infrastructure\Persistence;

use App\MarketData\Domain\CandleIntegrityUnavailable;
use DateTimeInterface;
use Throwable;

/** Shared app_storage write-ahead evidence; pending entries always block certification. */
final class CandleConflictJournal
{
    /** @param array<string, mixed> $evidence */
    public function begin(array $evidence): string
    {
        $directory = $this->directory();
        $path = $directory.'/'.bin2hex(random_bytes(16)).'.json';
        $stream = @fopen($path, 'x');
        if ($stream === false) {
            throw new CandleIntegrityUnavailable('CANDLE_INTEGRITY_UNAVAILABLE: journal creation failed.');
        }
        try {
            chmod($path, 0600);
            $payload = json_encode($evidence, JSON_THROW_ON_ERROR)."\n";
            if (fwrite($stream, $payload) !== strlen($payload) || ! fflush($stream) || ! fsync($stream)) {
                throw new CandleIntegrityUnavailable('CANDLE_INTEGRITY_UNAVAILABLE: journal flush failed.');
            }
        } finally {
            fclose($stream);
        }
        $this->syncDirectory($directory);

        return $path;
    }

    public function complete(string $path): void
    {
        if (! @unlink($path)) {
            throw new CandleIntegrityUnavailable('CANDLE_INTEGRITY_UNAVAILABLE: journal acknowledgement failed.');
        }
        $this->syncDirectory(dirname($path));
    }

    public function assertClear(?string $exchange = null, ?string $symbol = null, ?string $interval = null, ?DateTimeInterface $start = null, ?DateTimeInterface $end = null): void
    {
        $files = glob($this->directory().'/*.json');
        if ($files === false) {
            throw new CandleIntegrityUnavailable('CANDLE_INTEGRITY_UNAVAILABLE: journal listing failed.');
        }
        foreach ($files as $path) {
            try {
                $raw = @file_get_contents($path);
                // A writer may acknowledge its own entry between listing and reading.
                if ($raw === false && ! file_exists($path)) {
                    continue;
                }
                $entry = json_decode((string) $raw, true, flags: JSON_THROW_ON_ERROR);
                $time = strtotime($entry['open_time'] ?? '');
                if (! is_array($entry) || $time === false || ! isset($entry['exchange'], $entry['symbol'], $entry['interval'])) {
                    throw new CandleIntegrityUnavailable('Invalid pending evidence.');
                }
            } catch (Throwable $e) {
                throw new CandleIntegrityUnavailable('CANDLE_INTEGRITY_UNAVAILABLE: unreadable pending evidence.', previous: $e);
            }
            if (($exchange === null || $entry['exchange'] === $exchange)
                && ($symbol === null || $entry['symbol'] === $symbol)
                && ($interval === null || $entry['interval'] === $interval)
                && ($start === null || $time >= $start->getTimestamp())
                && ($end === null || $time <= $end->getTimestamp())) {
                throw new CandleIntegrityUnavailable('CANDLE_INTEGRITY_UNAVAILABLE: unacknowledged conflict evidence.');
            }
        }
    }

    private function directory(): string
    {
        $path = (string) config('market_data.conflict_journal_path', storage_path('app/candle-integrity'));
        if ((! is_dir($path) && ! @mkdir($path, 0770, true) && ! is_dir($path)) || ! is_readable($path) || ! is_writable($path)) {
            throw new CandleIntegrityUnavailable('CANDLE_INTEGRITY_UNAVAILABLE: shared journal storage unavailable.');
        }

        return $path;
    }

    private function syncDirectory(string $path): void
    {
        $stream = @fopen($path, 'r');
        if ($stream === false) {
            throw new CandleIntegrityUnavailable('CANDLE_INTEGRITY_UNAVAILABLE: journal directory unavailable.');
        }
        try {
            if (! fsync($stream)) {
                throw new CandleIntegrityUnavailable('CANDLE_INTEGRITY_UNAVAILABLE: journal directory flush failed.');
            }
        } finally {
            fclose($stream);
        }
    }
}
