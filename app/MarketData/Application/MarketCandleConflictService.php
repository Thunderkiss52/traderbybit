<?php

declare(strict_types=1);

namespace App\MarketData\Application;

use App\MarketData\Domain\CandleConflictEvidence;
use App\MarketData\Domain\CandleIntegrityUnavailable;
use App\MarketData\Infrastructure\Persistence\CandleConflictJournal;
use App\MarketData\Infrastructure\Persistence\CandleIntegrityDatabase;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Connection;
use Throwable;

final class MarketCandleConflictService
{
    /**
     * Durably and idempotently record a candle observation conflict.
     * Commits on a new primary connection; a pending shared journal blocks failed writes.
     *
     * @param  array<string, mixed>  $storedValues
     * @param  array<string, mixed>  $incomingValues
     * @return array<string, mixed>
     */
    public function recordConflict(
        string $exchange,
        string $category,
        string $symbol,
        string $interval,
        string $openTimeUtc,
        array $storedValues,
        array $incomingValues,
        ?string $conflictColumn,
        string $errorMessage,
        string $sourceProvenance = 'binance_public_rest'
    ): array {
        $now = CarbonImmutable::now('UTC');
        $nowStr = $now->toISOString();

        $conflictData = [
            'schema_version' => 1, 'event_id' => bin2hex(random_bytes(32)),
            'exchange' => $exchange,
            'category' => $category,
            'symbol' => $symbol,
            'interval' => $interval,
            'open_time' => $openTimeUtc,
            'stored_values' => $storedValues,
            'incoming_values' => $incomingValues,
            'conflict_column' => $conflictColumn,
            'error_message' => $errorMessage,
            'source_provenance' => $sourceProvenance,
            'first_seen_at' => $nowStr,
            'last_seen_at' => $nowStr,
            'occurrence_count' => 1,
            'status' => 'UNRESOLVED',
        ];
        $conflictData['payload_fingerprint'] = CandleConflictEvidence::fingerprint($conflictData);

        $journal = app(CandleConflictJournal::class);
        $pending = null;
        $journalFailure = null;
        try {
            $pending = $journal->begin($conflictData);
        } catch (Throwable $e) {
            $journalFailure = $e;
        }
        $recovery = app(CandleConflictRecovery::class);
        try {
            if ($pending !== null) {
                return $recovery->recover($pending)['conflict'];
            }
            // Preserve independent DB evidence even if journal creation failed, then reject success.
            $recovery->persist($conflictData, $conflictData['event_id'], hash('sha256', json_encode($conflictData, JSON_THROW_ON_ERROR)."\n"), $conflictData['event_id'].'.json');
            throw new CandleIntegrityUnavailable('CANDLE_INTEGRITY_UNAVAILABLE: shared journal unavailable; database evidence committed.', previous: $journalFailure);
        } catch (Throwable $e) {
            throw new CandleIntegrityUnavailable('CANDLE_INTEGRITY_UNAVAILABLE: conflict persistence not acknowledged.', previous: $e, reasonCode: $e instanceof CandleIntegrityUnavailable ? $e->reasonCode : 'CANDLE_INTEGRITY_UNAVAILABLE');
        }
    }

    /**
     * Check if an active unresolved conflict overlaps a specific strategy candle window.
     */
    public function hasActiveConflict(
        string $exchange,
        string $symbol,
        string $interval,
        ?DateTimeInterface $windowStart = null,
        ?DateTimeInterface $windowEnd = null
    ): bool {
        $db = app(CandleIntegrityDatabase::class)->connect();
        try {
            return $this->hasActiveConflictOn($db, $exchange, $symbol, $interval, $windowStart, $windowEnd);
        } finally {
            $db->disconnect();
        }
    }

    /**
     * Same as hasActiveConflict but reuses an already-open independent integrity connection.
     * Callers that scan many scopes must open one connection and pass it here; each call
     * otherwise builds a fresh primary session, which is correct but expensive in loops.
     */
    public function hasActiveConflictOn(
        Connection $db,
        string $exchange,
        string $symbol,
        string $interval,
        ?DateTimeInterface $windowStart = null,
        ?DateTimeInterface $windowEnd = null
    ): bool {
        $journal = app(CandleConflictJournal::class);
        try {
            $journal->assertClear($exchange, $symbol, $interval, $windowStart, $windowEnd);
            $this->assertRecovered($db, $exchange, $symbol, $interval, $windowStart, $windowEnd);
            $query = $db->table('market_candle_conflicts')->where('exchange', $exchange)->where('category', 'linear')
                ->where('symbol', $symbol)->where('interval', $interval)->where('status', 'UNRESOLVED');
            if ($windowStart !== null) {
                $query->where('open_time', '>=', $windowStart);
            }
            if ($windowEnd !== null) {
                $query->where('open_time', '<=', $windowEnd);
            }
            $found = $query->exists();
            $this->assertRecovered($db, $exchange, $symbol, $interval, $windowStart, $windowEnd);
            $journal->assertClear($exchange, $symbol, $interval, $windowStart, $windowEnd);

            return $found;
        } catch (Throwable $e) {
            throw new CandleIntegrityUnavailable('CANDLE_INTEGRITY_UNAVAILABLE: conflict lookup failed.', previous: $e, reasonCode: $e instanceof CandleIntegrityUnavailable ? $e->reasonCode : 'CANDLE_INTEGRITY_UNAVAILABLE');
        }
    }

    private function assertRecovered(Connection $db, ?string $exchange = null, ?string $symbol = null, ?string $interval = null, ?DateTimeInterface $start = null, ?DateTimeInterface $end = null): void
    {
        $query = $db->table('market_candle_conflict_journal')->where('state', '<>', 'RECOVERED');
        foreach (compact('exchange', 'symbol', 'interval') as $key => $value) {
            if ($value !== null) {
                $query->where($key, $value);
            }
        }
        if ($start !== null) {
            $query->where('open_time', '>=', $start);
        }
        if ($end !== null) {
            $query->where('open_time', '<=', $end);
        }
        if ($query->exists()) {
            throw new CandleIntegrityUnavailable('CANDLE_INTEGRITY_UNAVAILABLE: durable recovery is unfinished.', reasonCode: 'CANDLE_JOURNAL_RECOVERY_PENDING');
        }
    }

    public function integrityStatus(string $exchange, string $symbol, string $interval, ?DateTimeInterface $windowStart = null, ?DateTimeInterface $windowEnd = null): string
    {
        return $this->integrityReport($exchange, $symbol, $interval, $windowStart, $windowEnd)['status'];
    }

    /** @param list<array{exchange:string,symbol:string,interval:string,window_start:?DateTimeInterface,window_end:?DateTimeInterface}> $scopes */
    public function integrityReports(array $scopes, ?Connection $db = null): array
    {
        $owned = $db === null;
        try {
            $db ??= app(CandleIntegrityDatabase::class)->connect();
        } catch (CandleIntegrityUnavailable $e) {
            // Fail closed per scope: a shared-connection failure must not crash the whole scan,
            // and each affected scope reports UNAVAILABLE rather than a partial VALID.
            return array_map(static fn (array $s): array => ['status' => 'UNAVAILABLE', 'reason_code' => $e->reasonCode, 'exchange' => $s['exchange'], 'symbol' => $s['symbol'], 'interval' => $s['interval']], $scopes);
        }
        try {
            $results = [];
            foreach ($scopes as $scope) {
                $results[] = $this->integrityReportOn($db, $scope['exchange'], $scope['symbol'], $scope['interval'], $scope['window_start'] ?? null, $scope['window_end'] ?? null);
            }

            return $results;
        } finally {
            if ($owned) {
                $db->disconnect();
            }
        }
    }

    public function integrityReport(string $exchange, string $symbol, string $interval, ?DateTimeInterface $windowStart = null, ?DateTimeInterface $windowEnd = null): array
    {
        $db = null;
        try {
            $db = app(CandleIntegrityDatabase::class)->connect();

            return $this->integrityReportOn($db, $exchange, $symbol, $interval, $windowStart, $windowEnd);
        } catch (CandleIntegrityUnavailable $e) {
            return ['status' => 'UNAVAILABLE', 'reason_code' => $e->reasonCode];
        } finally {
            $db?->disconnect();
        }
    }

    public function integrityReportOn(
        Connection $db,
        string $exchange,
        string $symbol,
        string $interval,
        ?DateTimeInterface $windowStart = null,
        ?DateTimeInterface $windowEnd = null
    ): array {
        try {
            $conflicted = $this->hasActiveConflictOn($db, $exchange, $symbol, $interval, $windowStart, $windowEnd);

            return ['status' => $conflicted ? 'CONFLICTED' : 'VALID', 'reason_code' => $conflicted ? 'CANDLE_HISTORY_INTEGRITY_CONFLICT' : null];
        } catch (CandleIntegrityUnavailable $e) {
            return ['status' => 'UNAVAILABLE', 'reason_code' => $e->reasonCode];
        }
    }

    /**
     * List all unresolved conflicts, optionally filtered by exchange, symbol, or interval.
     *
     * @return list<array<string, mixed>>
     */
    public function unresolvedConflicts(?string $exchange = null, ?string $symbol = null, ?string $interval = null): array
    {
        $db = null;
        try {
            app(CandleConflictJournal::class)->assertClear($exchange, $symbol, $interval);
            $db = app(CandleIntegrityDatabase::class)->connect();
            $this->assertRecovered($db, $exchange, $symbol, $interval);

            $query = $db->table('market_candle_conflicts')->where('status', 'UNRESOLVED');
            if ($exchange !== null) {
                $query->where('exchange', $exchange);
            }
            if ($symbol !== null) {
                $query->where('symbol', $symbol);
            }
            if ($interval !== null) {
                $query->where('interval', $interval);
            }

            $result = $query->orderByDesc('open_time')->get()->map(fn ($r) => [
                'id' => $r->id,
                'exchange' => $r->exchange,
                'category' => $r->category,
                'symbol' => $r->symbol,
                'interval' => $r->interval,
                'open_time' => CarbonImmutable::parse($r->open_time)->toIso8601String(),
                'stored_values' => json_decode($r->stored_values, true, flags: JSON_THROW_ON_ERROR),
                'incoming_values' => json_decode($r->incoming_values, true, flags: JSON_THROW_ON_ERROR),
                'conflict_column' => $r->conflict_column,
                'error_message' => $r->error_message,
                'source_provenance' => $r->source_provenance,
                'first_seen_at' => CarbonImmutable::parse($r->first_seen_at)->toIso8601String(),
                'last_seen_at' => CarbonImmutable::parse($r->last_seen_at)->toIso8601String(),
                'occurrence_count' => $r->occurrence_count,
                'status' => $r->status,
            ])->all();
            $this->assertRecovered($db, $exchange, $symbol, $interval);
            app(CandleConflictJournal::class)->assertClear($exchange, $symbol, $interval);

            return $result;
        } catch (Throwable $e) {
            throw new CandleIntegrityUnavailable('CANDLE_INTEGRITY_UNAVAILABLE: conflict lookup failed.', previous: $e, reasonCode: $e instanceof CandleIntegrityUnavailable ? $e->reasonCode : 'CANDLE_INTEGRITY_UNAVAILABLE');
        } finally {
            $db?->disconnect();
        }
    }

    /**
     * Count unresolved conflicts.
     */
    public function countUnresolved(?string $exchange = null): int
    {
        $db = null;
        try {
            app(CandleConflictJournal::class)->assertClear($exchange);
            $db = app(CandleIntegrityDatabase::class)->connect();
            $this->assertRecovered($db, $exchange);

            $query = $db->table('market_candle_conflicts')->where('status', 'UNRESOLVED');
            if ($exchange !== null) {
                $query->where('exchange', $exchange);
            }

            $count = $query->count();
            $this->assertRecovered($db, $exchange);
            app(CandleConflictJournal::class)->assertClear($exchange);

            return $count;
        } catch (Throwable $e) {
            throw new CandleIntegrityUnavailable('CANDLE_INTEGRITY_UNAVAILABLE: conflict lookup failed.', previous: $e, reasonCode: $e instanceof CandleIntegrityUnavailable ? $e->reasonCode : 'CANDLE_INTEGRITY_UNAVAILABLE');
        } finally {
            $db?->disconnect();
        }
    }
}
