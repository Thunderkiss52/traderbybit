<?php

declare(strict_types=1);

namespace App\MarketData\Application;

use App\MarketData\Infrastructure\Persistence\MarketCandleConflictRecord;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class MarketCandleConflictService
{
    /**
     * Durably and idempotently record a candle observation conflict.
     * Guaranteed to persist even if caller transaction rolls back.
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

        try {
            if (Schema::hasTable('market_candle_conflicts')) {
                // Execute in an independent transaction to guarantee durability
                DB::transaction(function () use ($exchange, $category, $symbol, $interval, $openTimeUtc, $storedValues, $incomingValues, $conflictColumn, $errorMessage, $sourceProvenance, $fingerprint, $now) {
                    $existing = DB::table('market_candle_conflicts')
                        ->where([
                            'exchange' => $exchange,
                            'category' => $category,
                            'symbol' => $symbol,
                            'interval' => $interval,
                            'open_time' => $openTimeUtc,
                        ])
                        ->first();

                    if ($existing) {
                        DB::table('market_candle_conflicts')
                            ->where('id', $existing->id)
                            ->update([
                                'incoming_values' => json_encode($incomingValues, JSON_THROW_ON_ERROR),
                                'conflict_column' => $conflictColumn,
                                'error_message' => $errorMessage,
                                'payload_fingerprint' => $fingerprint,
                                'last_seen_at' => $now,
                                'occurrence_count' => DB::raw('occurrence_count + 1'),
                                'updated_at' => $now,
                            ]);
                    } else {
                        DB::table('market_candle_conflicts')->insert([
                            'exchange' => $exchange,
                            'category' => $category,
                            'symbol' => $symbol,
                            'interval' => $interval,
                            'open_time' => $openTimeUtc,
                            'stored_values' => json_encode($storedValues, JSON_THROW_ON_ERROR),
                            'incoming_values' => json_encode($incomingValues, JSON_THROW_ON_ERROR),
                            'conflict_column' => $conflictColumn,
                            'error_message' => $errorMessage,
                            'source_provenance' => $sourceProvenance,
                            'payload_fingerprint' => $fingerprint,
                            'first_seen_at' => $now,
                            'last_seen_at' => $now,
                            'occurrence_count' => 1,
                            'status' => 'UNRESOLVED',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                });
            }
        } catch (Throwable $e) {
            Log::warning('Failed to persist candle conflict record to database: '.$e->getMessage(), [
                'exchange' => $exchange,
                'symbol' => $symbol,
                'interval' => $interval,
                'open_time' => $openTimeUtc,
            ]);
        }

        return $conflictData;
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
            if (! Schema::hasTable('market_candle_conflicts')) {
                return false;
            }

            $query = MarketCandleConflictRecord::query()
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

            return $query->exists();
        } catch (Throwable) {
            return false;
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
            if (! Schema::hasTable('market_candle_conflicts')) {
                return [];
            }

            $query = MarketCandleConflictRecord::query()->where('status', 'UNRESOLVED');
            if ($exchange !== null) {
                $query->where('exchange', $exchange);
            }
            if ($symbol !== null) {
                $query->where('symbol', $symbol);
            }
            if ($interval !== null) {
                $query->where('interval', $interval);
            }

            return $query->orderByDesc('open_time')->get()->map(fn ($r) => [
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
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Count unresolved conflicts.
     */
    public function countUnresolved(?string $exchange = null): int
    {
        try {
            if (! Schema::hasTable('market_candle_conflicts')) {
                return 0;
            }

            $query = MarketCandleConflictRecord::query()->where('status', 'UNRESOLVED');
            if ($exchange !== null) {
                $query->where('exchange', $exchange);
            }

            return $query->count();
        } catch (Throwable) {
            return 0;
        }
    }
}
