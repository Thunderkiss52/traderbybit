<?php

declare(strict_types=1);

namespace App\MarketData\Infrastructure\Persistence;

use App\MarketData\Domain\CandleConflictEvidence;
use App\MarketData\Domain\CandleIntegrityUnavailable;
use DateTimeInterface;
use Throwable;

/** Shared app_storage write-ahead evidence; pending entries always block certification. */
final class CandleConflictJournal
{
    /** @param array<string, mixed> $evidence */
    public function begin(array $evidence): string
    {
        $evidence += ['schema_version' => 1, 'event_id' => bin2hex(random_bytes(32))];
        $directory = $this->directory();
        $identity = is_string($evidence['event_id']) && preg_match('/^[a-f0-9]{64}$/D', $evidence['event_id']) ? $evidence['event_id'] : bin2hex(random_bytes(32));
        $path = $directory.'/'.$identity.'.json';
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
        // Even rejected producer evidence survives; validation must never erase the only observation.
        $this->validate($evidence);

        return $path;
    }

    /** Source bytes remain inspectable after acknowledgement; PostgreSQL recovery is authoritative. */
    public function acknowledge(string $path, string $expectedHash): void
    {
        $entry = $this->read($path);
        if (! hash_equals($expectedHash, $entry['hash'])) {
            throw new CandleIntegrityUnavailable('CANDLE_JOURNAL_RECORD_INVALID: evidence changed before acknowledgement.', reasonCode: 'CANDLE_JOURNAL_RECORD_INVALID');
        }
        $db = app(CandleIntegrityDatabase::class)->connect();
        try {
            $proven = $db->table('market_candle_conflict_journal as journal')
                ->join('market_candle_conflicts as conflict', 'conflict.id', '=', 'journal.conflict_id')
                ->where('journal.event_id', $entry['event_id'])->where('journal.evidence_hash', $expectedHash)
                ->where('journal.state', 'RECOVERED')->whereNotNull('journal.recovered_at')->exists();
            if (! $proven) {
                throw new CandleIntegrityUnavailable('CANDLE_RECOVERY_RECEIPT_INVALID: acknowledgement requires a committed recovery receipt.', reasonCode: 'CANDLE_RECOVERY_RECEIPT_INVALID');
            }
        } catch (Throwable $e) {
            throw new CandleIntegrityUnavailable('CANDLE_INTEGRITY_UNAVAILABLE: acknowledgement proof unavailable.', previous: $e, reasonCode: $e instanceof CandleIntegrityUnavailable ? $e->reasonCode : 'CANDLE_INTEGRITY_UNAVAILABLE');
        } finally {
            $db->disconnect();
        }
        $archive = $this->directory().'/recovered';
        if (! is_dir($archive) && ! @mkdir($archive, 0770) && ! is_dir($archive)) {
            throw new CandleIntegrityUnavailable('CANDLE_INTEGRITY_UNAVAILABLE: journal archive unavailable.');
        }
        if (is_file($archive.'/'.basename($path)) && ! hash_equals($expectedHash, (string) @hash_file('sha256', $archive.'/'.basename($path)))) {
            throw new CandleIntegrityUnavailable('CANDLE_JOURNAL_IDENTITY_MISMATCH: archive differs from source.', reasonCode: 'CANDLE_JOURNAL_IDENTITY_MISMATCH');
        }
        if (! @rename($path, $archive.'/'.basename($path))) {
            clearstatcache(true, $path);
            if (is_file($path) || ! hash_equals($expectedHash, (string) @hash_file('sha256', $archive.'/'.basename($path)))) {
                throw new CandleIntegrityUnavailable('CANDLE_INTEGRITY_UNAVAILABLE: journal acknowledgement failed.');
            }
        }
        $this->syncDirectory($archive);
        $this->syncDirectory($this->directory());
    }

    /** @return array{evidence: array, event_id: string, hash: string} */
    public function read(string $path): array
    {
        $directory = $this->directory();
        if (dirname($path) !== $directory || ! preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{64})\.json$/D', basename($path))) {
            throw new CandleIntegrityUnavailable('CANDLE_JOURNAL_RECORD_INVALID: invalid entry identity.', reasonCode: 'CANDLE_JOURNAL_RECORD_INVALID');
        }
        // A repeated delivery can read the acknowledged original without making it pending again.
        clearstatcache(true, $path);
        $source = is_file($path) ? $path : $directory.'/recovered/'.basename($path);
        $raw = @file_get_contents($source);
        clearstatcache(true, $path);
        if ($raw === false && ! is_file($path)) {
            $raw = @file_get_contents($directory.'/recovered/'.basename($path));
        }
        try {
            if ($raw === false || strlen($raw) > 1048576) {
                throw new \RuntimeException;
            }
            $entry = $this->validate(json_decode($raw, true, flags: JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            throw new CandleIntegrityUnavailable('CANDLE_JOURNAL_RECORD_INVALID: unreadable or invalid evidence.', previous: $e, reasonCode: 'CANDLE_JOURNAL_RECORD_INVALID');
        }

        return ['evidence' => $entry, 'event_id' => $entry['event_id'] ?? hash('sha256', 'legacy:'.basename($path)), 'hash' => hash('sha256', $raw)];
    }

    public function pending(int $limit = PHP_INT_MAX): array
    {
        $files = glob($this->directory().'/*.json');
        if ($files === false) {
            throw new CandleIntegrityUnavailable('CANDLE_INTEGRITY_UNAVAILABLE: journal listing failed.');
        }
        sort($files, SORT_STRING);

        return array_slice($files, 0, $limit);
    }

    public function entryPath(string $basename): string
    {
        return $this->directory().'/'.$basename;
    }

    public function validate(mixed $entry): array
    {
        return CandleConflictEvidence::validate($entry, (array) config('market_data.symbols', []), (array) config('exchanges.enabled', []));
    }

    public function assertClear(?string $exchange = null, ?string $symbol = null, ?string $interval = null, ?DateTimeInterface $start = null, ?DateTimeInterface $end = null): void
    {
        foreach ($this->pending() as $path) {
            $entry = null;
            try {
                $entry = $this->read($path)['evidence'];
            } catch (CandleIntegrityUnavailable $e) {
                if (! is_file($path) && is_file($this->directory().'/recovered/'.basename($path))) {
                    continue;
                }
                // Preserve invalid bytes. Only independently valid scope prefixes may isolate their impact.
                $raw = @file_get_contents($path);
                $decoded = $raw === false ? null : json_decode($raw, true);
                $scope = CandleConflictEvidence::scope($decoded, (array) config('market_data.symbols', []), (array) config('exchanges.enabled', []));
                if ($this->overlaps($scope, $exchange, $symbol, $interval, $start, $end)) {
                    throw $e;
                }

                continue;
            }
            if ($this->overlaps($entry, $exchange, $symbol, $interval, $start, $end)) {
                throw new CandleIntegrityUnavailable('CANDLE_INTEGRITY_UNAVAILABLE: unacknowledged conflict evidence.', reasonCode: 'CANDLE_JOURNAL_PENDING');
            }
        }
    }

    private function overlaps(array $scope, ?string $exchange, ?string $symbol, ?string $interval, ?DateTimeInterface $start, ?DateTimeInterface $end): bool
    {
        return ($exchange === null || ! isset($scope['exchange']) || $scope['exchange'] === $exchange)
            && ($symbol === null || ! isset($scope['symbol']) || $scope['symbol'] === $symbol)
            && ($interval === null || ! isset($scope['interval']) || $scope['interval'] === $interval)
            && ($start === null || ! isset($scope['open_time']) || strtotime($scope['open_time']) >= $start->getTimestamp())
            && ($end === null || ! isset($scope['open_time']) || strtotime($scope['open_time']) <= $end->getTimestamp());
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
