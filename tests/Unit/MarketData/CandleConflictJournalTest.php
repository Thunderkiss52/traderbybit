<?php

declare(strict_types=1);

namespace Tests\Unit\MarketData;

use App\MarketData\Domain\CandleIntegrityUnavailable;
use App\MarketData\Infrastructure\Persistence\CandleConflictJournal;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\CandleConflictEvidenceFixture;
use Tests\TestCase;

final class CandleConflictJournalTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/vector-journal-schema-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        config(['market_data.conflict_journal_path' => $this->directory]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*.json') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    #[DataProvider('invalidEntries')]
    public function test_invalid_records_never_certify_affected_scopes_and_are_not_rewritten(string $key, mixed $value, bool $missing = false): void
    {
        $entry = CandleConflictEvidenceFixture::make();
        $entry[$key] = $value;
        if ($missing) {
            unset($entry[$key]);
        }
        $path = $this->directory.'/'.str_repeat('a', 64).'.json';
        $bytes = json_encode($entry, JSON_THROW_ON_ERROR);
        file_put_contents($path, $bytes);
        try {
            app(CandleConflictJournal::class)->assertClear('binance', 'BTCUSDT', '30');
            self::fail('Invalid journal evidence was accepted.');
        } catch (CandleIntegrityUnavailable $e) {
            self::assertSame('CANDLE_JOURNAL_RECORD_INVALID', $e->reasonCode);
        }
        self::assertSame($bytes, file_get_contents($path));
        if ($key !== 'exchange') {
            app(CandleConflictJournal::class)->assertClear('bybit', 'BTCUSDT', '30');
            self::assertTrue(true, 'A proven unrelated exchange remains isolated.');
        }
    }

    public static function invalidEntries(): array
    {
        return [
            'array symbol' => ['symbol', ['BTCUSDT']], 'integer symbol' => ['symbol', 1234],
            'boolean symbol' => ['symbol', true], 'null symbol' => ['symbol', null],
            'missing symbol' => ['symbol', null, true], 'empty symbol' => ['symbol', ''],
            'lowercase symbol' => ['symbol', 'btcusdt'], 'whitespace symbol' => ['symbol', 'BTCUSDT '],
            'invalid alphabet' => ['symbol', 'BTC/USDT'], 'unsupported symbol' => ['symbol', 'UNKNOWNUSDT'],
            'unsupported exchange' => ['exchange', 'unknown'], 'array exchange' => ['exchange', ['binance']],
            'unsupported market' => ['category', 'spot'], 'null interval' => ['interval', null],
            'unsupported interval' => ['interval', '7'], 'invalid timestamp' => ['open_time', '2026-02-30T00:00:00Z'],
            'missing observation' => ['incoming_values', null, true], 'wrong observation type' => ['stored_values', '102'],
            'fingerprint mismatch' => ['payload_fingerprint', str_repeat('a', 64)],
            'missing event id' => ['event_id', null, true], 'unknown version' => ['schema_version', 2],
            'invalid count' => ['occurrence_count', '1'], 'invalid status' => ['status', 'RESOLVED'],
        ];
    }

    public function test_valid_pending_evidence_is_scoped_and_legacy_bytes_remain_readable(): void
    {
        $journal = app(CandleConflictJournal::class);
        $entry = CandleConflictEvidenceFixture::make();
        unset($entry['schema_version'], $entry['event_id']);
        $path = $this->directory.'/'.str_repeat('b', 32).'.json';
        file_put_contents($path, json_encode($entry, JSON_THROW_ON_ERROR));
        $read = $journal->read($path);
        self::assertSame(hash('sha256', 'legacy:'.basename($path)), $read['event_id']);
        $journal->assertClear('binance', 'ETHUSDT', '30');
        $journal->assertClear('binance', 'BTCUSDT', '5');
        $this->expectException(CandleIntegrityUnavailable::class);
        $journal->assertClear('binance', 'BTCUSDT', '30');
    }

    public function test_invalid_producer_evidence_is_preserved_before_explicit_failure(): void
    {
        $entry = CandleConflictEvidenceFixture::make(['symbol' => ['BTCUSDT']]);
        try {
            app(CandleConflictJournal::class)->begin($entry);
            self::fail('Invalid producer evidence was accepted.');
        } catch (CandleIntegrityUnavailable $e) {
            self::assertSame('CANDLE_JOURNAL_RECORD_INVALID', $e->reasonCode);
        }
        $files = glob($this->directory.'/*.json');
        self::assertCount(1, $files);
        self::assertSame($entry, json_decode(file_get_contents($files[0]), true));
        $this->expectException(CandleIntegrityUnavailable::class);
        app(CandleConflictJournal::class)->assertClear('binance', 'BTCUSDT', '30');
    }
}
