<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\MarketData\Domain\CandleConflictEvidence;

final class CandleConflictEvidenceFixture
{
    public static function make(array $overrides = []): array
    {
        $entry = array_replace([
            'schema_version' => 1, 'event_id' => bin2hex(random_bytes(32)),
            'exchange' => 'binance', 'category' => 'linear', 'symbol' => 'BTCUSDT',
            'interval' => '30', 'open_time' => '2026-01-01T00:30:00Z',
            'stored_values' => ['close' => '102'], 'incoming_values' => ['close' => '103'],
            'conflict_column' => 'close', 'error_message' => 'Conflicting immutable Binance close observation.',
            'source_provenance' => 'isolated_test', 'first_seen_at' => '2026-01-01T01:00:00Z',
            'last_seen_at' => '2026-01-01T01:00:00Z', 'occurrence_count' => 1, 'status' => 'UNRESOLVED',
        ], $overrides);
        $entry['payload_fingerprint'] = CandleConflictEvidence::fingerprint($entry);

        return $entry;
    }
}
