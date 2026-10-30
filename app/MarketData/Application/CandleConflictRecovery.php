<?php

declare(strict_types=1);

namespace App\MarketData\Application;

use App\MarketData\Domain\CandleIntegrityUnavailable;
use App\MarketData\Infrastructure\Persistence\CandleConflictJournal;
use App\MarketData\Infrastructure\Persistence\CandleIntegrityDatabase;
use Illuminate\Database\Connection;
use Throwable;

final readonly class CandleConflictRecovery
{
    public function __construct(private CandleConflictJournal $journal, private CandleIntegrityDatabase $database) {}

    public function recover(string $path): array
    {
        $entry = $this->journal->read($path);
        $result = $this->persist($entry['evidence'], $entry['event_id'], $entry['hash'], basename($path));
        // The committed receipt and conflict update are one transaction. A crash here is replay-safe.
        $this->journal->acknowledge($path, $entry['hash']);

        return $result;
    }

    public function unfinishedCount(): int
    {
        $db = $this->database->connect();
        try {
            return $db->table('market_candle_conflict_journal')->where('state', '<>', 'RECOVERED')->count();
        } catch (Throwable $e) {
            throw new CandleIntegrityUnavailable('CANDLE_INTEGRITY_UNAVAILABLE: recovery state cannot be queried.', previous: $e);
        } finally {
            $db->disconnect();
        }
    }

    /** Also preserves DB evidence when the shared filesystem itself is unavailable. */
    public function persist(array $evidence, string $eventId, string $hash, string $source): array
    {
        $this->journal->validate($evidence);
        $db = $this->database->connect();
        $token = bin2hex(random_bytes(32));
        $claimed = false;
        try {
            $now = $db->raw('CURRENT_TIMESTAMP');
            $db->table('market_candle_conflict_journal')->insertOrIgnore([
                'event_id' => $eventId, 'evidence_hash' => $hash, 'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR),
                'source_entry' => $source, ...array_intersect_key($evidence, array_flip(['exchange', 'category', 'symbol', 'interval', 'open_time'])),
                'state' => 'PENDING', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $receipt = $db->transaction(function () use ($db, $eventId, $hash, $token): object {
                $row = $db->table('market_candle_conflict_journal')->where('event_id', $eventId)
                    ->selectRaw('*, CASE WHEN claim_until > CURRENT_TIMESTAMP THEN 1 ELSE 0 END AS busy')
                    ->lockForUpdate()->first();
                if ($row === null || ! hash_equals($row->evidence_hash, $hash)) {
                    throw new CandleIntegrityUnavailable('CANDLE_JOURNAL_IDENTITY_MISMATCH: source differs from durable receipt.', reasonCode: 'CANDLE_JOURNAL_IDENTITY_MISMATCH');
                }
                if ($row->state === 'RECOVERED') {
                    return $row;
                }
                if ($row->state === 'FAILED' && ! $row->retryable) {
                    throw new CandleIntegrityUnavailable('CANDLE_RECOVERY_PERMANENT_FAILURE: entry requires operator review.', reasonCode: 'CANDLE_RECOVERY_PERMANENT_FAILURE');
                }
                if ($row->state === 'IN_PROGRESS' && (int) $row->busy === 1) {
                    throw new CandleIntegrityUnavailable('CANDLE_RECOVERY_IN_PROGRESS: entry is leased.', reasonCode: 'CANDLE_RECOVERY_IN_PROGRESS');
                }
                $db->table('market_candle_conflict_journal')->where('event_id', $eventId)->update([
                    'state' => 'IN_PROGRESS', 'claim_token' => $token, 'claim_until' => $db->raw("CURRENT_TIMESTAMP + INTERVAL '10 seconds'"),
                    'attempts' => $db->raw('attempts + 1'), 'last_error_code' => null, 'updated_at' => $db->raw('CURRENT_TIMESTAMP'),
                ]);

                return $db->table('market_candle_conflict_journal')->where('event_id', $eventId)->first();
            }, attempts: 1);
            if ($receipt->state !== 'RECOVERED') {
                $claimed = true;
                $receipt = $db->transaction(function () use ($db, $eventId, $token, $evidence): object {
                    $row = $db->table('market_candle_conflict_journal')->where('event_id', $eventId)->lockForUpdate()->first();
                    if ($row->state !== 'IN_PROGRESS' || $row->claim_token !== $token) {
                        throw new CandleIntegrityUnavailable('CANDLE_RECOVERY_IN_PROGRESS: claim changed.', reasonCode: 'CANDLE_RECOVERY_IN_PROGRESS');
                    }
                    $conflict = $this->apply($db, $evidence);
                    $db->table('market_candle_conflict_journal')->where('event_id', $eventId)->where('claim_token', $token)->update([
                        'state' => 'RECOVERED', 'conflict_id' => $conflict->id, 'recovered_at' => $db->raw('CURRENT_TIMESTAMP'),
                        'claim_token' => null, 'claim_until' => null, 'retryable' => false, 'last_error_code' => null, 'updated_at' => $db->raw('CURRENT_TIMESTAMP'),
                    ]);

                    return $db->table('market_candle_conflict_journal')->where('event_id', $eventId)->first();
                }, attempts: 1);
            }
            $conflict = $db->table('market_candle_conflicts')->where('id', $receipt->conflict_id)->first();
            if ($conflict === null) {
                throw new CandleIntegrityUnavailable('CANDLE_RECOVERY_RECEIPT_INVALID: committed evidence missing.', reasonCode: 'CANDLE_RECOVERY_RECEIPT_INVALID');
            }
            $values = (array) $conflict;
            foreach (['stored_values', 'incoming_values'] as $key) {
                $values[$key] = json_decode($values[$key], true, flags: JSON_THROW_ON_ERROR);
            }

            return ['event_id' => $eventId, 'state' => 'RECOVERED', 'attempts' => (int) $receipt->attempts, 'conflict' => $values];
        } catch (Throwable $e) {
            if ($claimed) {
                try {
                    $db->table('market_candle_conflict_journal')->where('event_id', $eventId)->where('state', 'IN_PROGRESS')->where('claim_token', $token)->update([
                        'state' => 'FAILED', 'retryable' => true, 'claim_token' => null, 'claim_until' => null,
                        'last_error_code' => 'CANDLE_RECOVERY_WRITE_FAILED', 'updated_at' => $db->raw('CURRENT_TIMESTAMP'),
                    ]);
                } catch (Throwable $stateFailure) {
                    throw new CandleIntegrityUnavailable('CANDLE_INTEGRITY_UNAVAILABLE: recovery failed and failure state could not be recorded; pending evidence and lease retained.', previous: $stateFailure);
                }
            }
            throw $e instanceof CandleIntegrityUnavailable ? $e : new CandleIntegrityUnavailable('CANDLE_INTEGRITY_UNAVAILABLE: recovery did not complete.', previous: $e);
        } finally {
            $db->disconnect();
        }
    }

    private function apply(Connection $db, array $entry): object
    {
        $values = $entry;
        unset($values['event_id'], $values['schema_version']);
        foreach (['stored_values', 'incoming_values'] as $key) {
            $values[$key] = json_encode($values[$key], JSON_THROW_ON_ERROR);
        }
        $values += ['created_at' => $db->raw('CURRENT_TIMESTAMP'), 'updated_at' => $db->raw('CURRENT_TIMESTAMP'), 'resolved_at' => null];
        $updates = ['status', 'resolved_at', 'updated_at', 'occurrence_count' => $db->raw('market_candle_conflicts.occurrence_count + 1')];
        foreach (['incoming_values', 'conflict_column', 'error_message', 'payload_fingerprint', 'last_seen_at'] as $key) {
            $updates[$key] = $db->raw('CASE WHEN EXCLUDED.last_seen_at >= market_candle_conflicts.last_seen_at THEN EXCLUDED.'.$key.' ELSE market_candle_conflicts.'.$key.' END');
        }
        $db->table('market_candle_conflicts')->upsert([$values], ['exchange', 'category', 'symbol', 'interval', 'open_time'], $updates);

        return $db->table('market_candle_conflicts')->where(array_intersect_key($values, array_flip(['exchange', 'category', 'symbol', 'interval', 'open_time'])))->first();
    }
}
