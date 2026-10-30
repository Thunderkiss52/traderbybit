<?php

declare(strict_types=1);

namespace App\MarketData\Domain;

use DateTimeImmutable;
use Throwable;

/** Versioned write-ahead evidence. Legacy V2 records retain their original file identity. */
final class CandleConflictEvidence
{
    public static function fingerprint(array $entry): string
    {
        return hash('sha256', json_encode([
            'exchange' => $entry['exchange'], 'category' => $entry['category'],
            'symbol' => $entry['symbol'], 'interval' => $entry['interval'],
            'open_time' => $entry['open_time'], 'incoming' => $entry['incoming_values'],
        ], JSON_THROW_ON_ERROR));
    }

    public static function validate(mixed $entry, array $symbols, array $exchanges): array
    {
        $required = ['exchange', 'category', 'symbol', 'interval', 'open_time', 'stored_values', 'incoming_values', 'conflict_column', 'error_message', 'source_provenance', 'payload_fingerprint', 'first_seen_at', 'last_seen_at', 'occurrence_count', 'status'];
        $allowed = [...$required, 'schema_version', 'event_id'];
        try {
            if (! is_array($entry) || array_is_list($entry) || array_diff($required, array_keys($entry)) !== [] || array_diff(array_keys($entry), $allowed) !== []) {
                throw new \InvalidArgumentException;
            }
            if (count(self::scope($entry, $symbols, $exchanges)) !== 5) {
                throw new \InvalidArgumentException;
            }
            foreach (['stored_values', 'incoming_values'] as $key) {
                if (! is_array($entry[$key]) || $entry[$key] === [] || array_is_list($entry[$key])) {
                    throw new \InvalidArgumentException;
                }
                foreach (['exchange', 'category', 'symbol', 'interval'] as $identity) {
                    if (array_key_exists($identity, $entry[$key]) && $entry[$key][$identity] !== $entry[$identity]) {
                        throw new \InvalidArgumentException;
                    }
                }
                foreach (['open', 'high', 'low', 'close', 'volume', 'turnover'] as $decimal) {
                    if (array_key_exists($decimal, $entry[$key]) && ((! is_string($entry[$key][$decimal]) && ! is_int($entry[$key][$decimal])) || ! preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]{1,12})?$/D', (string) $entry[$key][$decimal]))) {
                        throw new \InvalidArgumentException;
                    }
                }
            }
            foreach (['error_message' => 4096, 'source_provenance' => 64] as $key => $max) {
                if (! is_string($entry[$key]) || $entry[$key] === '' || strlen($entry[$key]) > $max) {
                    throw new \InvalidArgumentException;
                }
            }
            if ($entry['conflict_column'] !== null && (! is_string($entry['conflict_column']) || ! in_array($entry['conflict_column'], ['open', 'high', 'low', 'close', 'volume', 'turnover', 'is_closed'], true))) {
                throw new \InvalidArgumentException;
            }
            if (! self::timestamp($entry['first_seen_at']) || ! self::timestamp($entry['last_seen_at']) || strtotime($entry['first_seen_at']) > strtotime($entry['last_seen_at']) || $entry['occurrence_count'] !== 1 || $entry['status'] !== 'UNRESOLVED') {
                throw new \InvalidArgumentException;
            }
            if (! is_string($entry['payload_fingerprint']) || ! hash_equals(self::fingerprint($entry), $entry['payload_fingerprint'])) {
                throw new \InvalidArgumentException;
            }
            if (array_key_exists('schema_version', $entry) || array_key_exists('event_id', $entry)) {
                if (($entry['schema_version'] ?? null) !== 1 || ! is_string($entry['event_id'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $entry['event_id'])) {
                    throw new \InvalidArgumentException;
                }
            }
        } catch (Throwable $e) {
            throw new CandleIntegrityUnavailable('CANDLE_JOURNAL_RECORD_INVALID: pending evidence failed schema validation.', previous: $e, reasonCode: 'CANDLE_JOURNAL_RECORD_INVALID');
        }

        return $entry;
    }

    /** A malformed symbol cannot narrow an entry to a different or empty symbol scope. */
    public static function scope(mixed $entry, array $symbols, array $exchanges): array
    {
        $scope = [];
        if (! is_array($entry) || ! is_string($entry['exchange'] ?? null) || ! in_array($entry['exchange'], $exchanges, true)) {
            return $scope;
        }
        $scope['exchange'] = $entry['exchange'];
        if (($entry['category'] ?? null) !== 'linear') {
            return $scope;
        }
        $scope['category'] = 'linear';
        if (! is_string($entry['symbol'] ?? null) || ! preg_match('/^[A-Z0-9]{4,32}$/D', $entry['symbol']) || ! in_array($entry['symbol'], $symbols, true)) {
            return $scope;
        }
        $scope['symbol'] = $entry['symbol'];
        if (! is_string($entry['interval'] ?? null) || Interval::tryFrom($entry['interval']) === null) {
            return $scope;
        }
        $scope['interval'] = $entry['interval'];
        if (! self::timestamp($entry['open_time'] ?? null) || strtotime($entry['open_time']) % Interval::from($entry['interval'])->seconds() !== 0) {
            return $scope;
        }
        $scope['open_time'] = $entry['open_time'];

        return $scope;
    }

    private static function timestamp(mixed $value): bool
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,6})?(?:Z|\+00:00)$/D', $value)) {
            return false;
        }
        try {
            new DateTimeImmutable($value);

            return DateTimeImmutable::getLastErrors() === false;
        } catch (Throwable) {
            return false;
        }
    }
}
