<?php

declare(strict_types=1);

namespace App\MarketData\Application;

use App\MarketData\Domain\CandleIntegrityUnavailable;
use App\MarketData\Infrastructure\Persistence\CandleConflictJournal;
use App\MarketData\Infrastructure\Persistence\MarketCandleConflictRecord;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Support\Facades\DB;
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
        $nowStr = $now->toIso8601String();
        $fingerprint = hash('sha256', json_encode([
            'exchange' => $exchange,
            'category' => $category,
            'symbol' => $symbol,
            'interval' => $interval,
            'open_time' => $openTimeUtc,
            'incoming' => $incomingValues,
        ], JSON_THROW_ON_ERROR));

        $conflictData = [
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
            'payload_fingerprint' => $fingerprint,
            'first_seen_at' => $nowStr,
            'last_seen_at' => $nowStr,
            'occurrence_count' => 1,
            'status' => 'UNRESOLVED',
        ];

        $journal = app(CandleConflictJournal::class);
        $pending = null;
        $journalFailure = null;
        try {
            $pending = $journal->begin($conflictData);
        } catch (Throwable $e) {
            $journalFailure = $e;
        }
        $connection = null;
        $connectTimeout = getenv('PGCONNECT_TIMEOUT');
        try {
            // Clone the resolved WRITE configuration, including SSL, schema and socket settings.
            // ConnectionFactory creates a genuinely new PDO, outside any caller transaction.
            $config = DB::connection()->getConfig();
            if (($config['driver'] ?? null) !== 'pgsql') {
                throw new CandleIntegrityUnavailable('PostgreSQL integrity storage required.');
            }
            unset($config['read'], $config['write'], $config['url']);
            putenv('PGCONNECT_TIMEOUT=3');
            $connection = app(ConnectionFactory::class)->make($config, 'candle_integrity_writer');
            $persisted = $connection->transaction(function () use ($connection, $conflictData, $now): array {
                $connection->statement("SET LOCAL lock_timeout = '1000ms'");
                $connection->statement("SET LOCAL statement_timeout = '3000ms'");
                $values = $conflictData;
                $values['stored_values'] = json_encode($values['stored_values'], JSON_THROW_ON_ERROR);
                $values['incoming_values'] = json_encode($values['incoming_values'], JSON_THROW_ON_ERROR);
                $values['created_at'] = $now;
                $values['updated_at'] = $now;
                $values['resolved_at'] = null;
                // One PostgreSQL statement serializes concurrent insert/reopen/count updates.
                // First stored values, first_seen_at and prior resolution notes remain intact.
                $connection->table('market_candle_conflicts')->upsert([$values],
                    ['exchange', 'category', 'symbol', 'interval', 'open_time'], [
                        'incoming_values', 'conflict_column', 'error_message', 'payload_fingerprint',
                        'last_seen_at', 'updated_at', 'status', 'resolved_at',
                        'occurrence_count' => $connection->raw('market_candle_conflicts.occurrence_count + 1'),
                    ]);
                $identity = array_intersect_key($values, array_flip(['exchange', 'category', 'symbol', 'interval', 'open_time']));

                return (array) $connection->table('market_candle_conflicts')->where($identity)->first();
            }, attempts: 1);
            if ($pending !== null) {
                $journal->complete($pending);
            }
            if ($journalFailure !== null) {
                throw new CandleIntegrityUnavailable('Shared journal unavailable; database evidence was committed.', previous: $journalFailure);
            }
        } catch (Throwable $e) {
            // The write-ahead file remains on shared app_storage and blocks readiness.
            throw new CandleIntegrityUnavailable('CANDLE_INTEGRITY_UNAVAILABLE: conflict persistence not acknowledged.', previous: $e);
        } finally {
            $connection?->disconnect();
            $connectTimeout === false ? putenv('PGCONNECT_TIMEOUT') : putenv('PGCONNECT_TIMEOUT='.$connectTimeout);
        }

        foreach (['stored_values', 'incoming_values'] as $column) {
            $persisted[$column] = json_decode($persisted[$column], true, flags: JSON_THROW_ON_ERROR);
        }

        return $persisted;
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
        try {
            app(CandleConflictJournal::class)->assertClear($exchange, $symbol, $interval, $windowStart, $windowEnd);

            $query = MarketCandleConflictRecord::query()->useWritePdo()
                ->where('exchange', $exchange)
                ->where('symbol', $symbol)
                ->where('interval', $interval)
                ->where('status', 'UNRESOLVED');

            if ($windowStart !== null) {
                $query->where('open_time', '>=', $windowStart);
            }
            if ($windowEnd !== null) {
                $query->where('open_time', '<=', $windowEnd);
            }

            $found = $query->exists();
            app(CandleConflictJournal::class)->assertClear($exchange, $symbol, $interval, $windowStart, $windowEnd);

            return $found;
        } catch (Throwable $e) {
            throw new CandleIntegrityUnavailable('CANDLE_INTEGRITY_UNAVAILABLE: conflict lookup failed.', previous: $e);
        }
    }

    public function integrityStatus(string $exchange, string $symbol, string $interval, ?DateTimeInterface $windowStart = null, ?DateTimeInterface $windowEnd = null): string
    {
        try {
            return $this->hasActiveConflict($exchange, $symbol, $interval, $windowStart, $windowEnd) ? 'CONFLICTED' : 'VALID';
        } catch (CandleIntegrityUnavailable) {
            return 'UNAVAILABLE';
        }
    }

    /**
     * List all unresolved conflicts, optionally filtered by exchange, symbol, or interval.
     *
     * @return list<array<string, mixed>>
     */
    public function unresolvedConflicts(?string $exchange = null, ?string $symbol = null, ?string $interval = null): array
    {
        try {
            app(CandleConflictJournal::class)->assertClear($exchange, $symbol, $interval);

            $query = MarketCandleConflictRecord::query()->useWritePdo()->where('status', 'UNRESOLVED');
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
                'open_time' => $r->open_time?->toIso8601String(),
                'stored_values' => $r->stored_values,
                'incoming_values' => $r->incoming_values,
                'conflict_column' => $r->conflict_column,
                'error_message' => $r->error_message,
                'source_provenance' => $r->source_provenance,
                'first_seen_at' => $r->first_seen_at?->toIso8601String(),
                'last_seen_at' => $r->last_seen_at?->toIso8601String(),
                'occurrence_count' => $r->occurrence_count,
                'status' => $r->status,
            ])->all();
            app(CandleConflictJournal::class)->assertClear($exchange, $symbol, $interval);

            return $result;
        } catch (Throwable $e) {
            throw new CandleIntegrityUnavailable('CANDLE_INTEGRITY_UNAVAILABLE: conflict lookup failed.', previous: $e);
        }
    }

    /**
     * Count unresolved conflicts.
     */
    public function countUnresolved(?string $exchange = null): int
    {
        try {
            app(CandleConflictJournal::class)->assertClear($exchange);

            $query = MarketCandleConflictRecord::query()->useWritePdo()->where('status', 'UNRESOLVED');
            if ($exchange !== null) {
                $query->where('exchange', $exchange);
            }

            $count = $query->count();
            app(CandleConflictJournal::class)->assertClear($exchange);

            return $count;
        } catch (Throwable $e) {
            throw new CandleIntegrityUnavailable('CANDLE_INTEGRITY_UNAVAILABLE: conflict lookup failed.', previous: $e);
        }
    }
}
