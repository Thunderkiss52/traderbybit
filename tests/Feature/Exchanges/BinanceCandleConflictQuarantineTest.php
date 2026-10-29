<?php

declare(strict_types=1);

namespace Tests\Feature\Exchanges;

use App\Exchanges\Binance\BinanceCandleIngestion;
use App\Exchanges\Binance\BinanceCandleWindow;
use App\MarketData\Application\MarketCandleConflictService;
use App\MarketData\Infrastructure\Persistence\MarketCandleConflictRecord;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class BinanceCandleConflictQuarantineTest extends TestCase
{
    use RefreshDatabase;

    private array $responseRows = [];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('market_data.symbols', ['BTCUSDT', 'ETHUSDT']);
        Http::preventStrayRequests();
        Http::fake(['https://fapi.binance.com/fapi/v1/klines*' => fn () => Http::response($this->responseRows)]);
    }

    public function test_strict_mode_rolls_back_candles_but_persists_durable_conflict_record(): void
    {
        // 1. Seed an initial stored candle at index 1
        $this->responseRows = [$this->makeRow(1)];
        app(BinanceCandleIngestion::class)->ingest('BTCUSDT', '5m', 1);

        self::assertSame(1, DB::table('market_candles')->where('symbol', 'BTCUSDT')->count());
        self::assertSame(0, MarketCandleConflictRecord::query()->count());

        // 2. Incoming batch with index 0 (missing), index 1 (conflicting close), index 2 (missing)
        $conflictingRow = $this->makeRow(1);
        $conflictingRow[4] = '103.000000000000'; // Close conflict (103 instead of 102, within high=105, low=99)

        $this->responseRows = [
            $this->makeRow(0),
            $conflictingRow,
            $this->makeRow(2),
        ];

        try {
            app(BinanceCandleIngestion::class)->ingest('BTCUSDT', '5m', 3, quarantineConflicts: false);
            self::fail('Conflicting batch accepted in strict mode.');
        } catch (DomainException $e) {
            self::assertStringContainsString('Conflicting immutable Binance close observation', $e->getMessage());
        }

        // 3. Verification: market_candles rolled back (only index 1 exists, unmodified)
        self::assertSame(1, DB::table('market_candles')->where('symbol', 'BTCUSDT')->count());
        $stored = DB::table('market_candles')->where('symbol', 'BTCUSDT')->first();
        self::assertSame('102', rtrim(rtrim((string) $stored->close, '0'), '.'));

        // 4. Verification: conflict evidence durably persisted in market_candle_conflicts
        self::assertSame(1, MarketCandleConflictRecord::query()->count());
        $conflict = MarketCandleConflictRecord::query()->first();
        self::assertSame('binance', $conflict->exchange);
        self::assertSame('BTCUSDT', $conflict->symbol);
        self::assertSame('5', $conflict->interval);
        self::assertSame('close', $conflict->conflict_column);
        self::assertSame(1, $conflict->occurrence_count);
        self::assertSame('UNRESOLVED', $conflict->status);
        self::assertNotNull($conflict->payload_fingerprint);
    }

    public function test_quarantine_mode_commits_valid_candles_and_quarantines_conflicting_bar(): void
    {
        // 1. Seed initial stored candle at index 1
        $this->responseRows = [$this->makeRow(1)];
        app(BinanceCandleIngestion::class)->ingest('BTCUSDT', '5m', 1);

        // 2. Incoming batch with index 0 (missing), index 1 (conflicting close), index 2 (missing)
        $conflictingRow = $this->makeRow(1);
        $conflictingRow[4] = '103.000000000000'; // Close conflict (103 instead of 102, within high=105, low=99)

        $this->responseRows = [
            $this->makeRow(0),
            $conflictingRow,
            $this->makeRow(2),
        ];

        // 3. Ingest with quarantineConflicts = true
        $result = app(BinanceCandleIngestion::class)->ingest('BTCUSDT', '5m', 3, quarantineConflicts: true);

        // 4. Verification: non-discarding partial ingestion
        self::assertSame('PARTIAL_INGESTION_WITH_CONFLICTS', $result['status']);
        self::assertSame(2, $result['new_closed_candles'], 'Bars 0 and 2 were inserted');
        self::assertSame(1, $result['conflicts_count']);
        self::assertCount(1, $result['conflicts']);

        // 5. Verification: total stored candles is now 3 (bars 0, 1, 2)
        self::assertSame(3, DB::table('market_candles')->where('symbol', 'BTCUSDT')->count());

        // Stored bar 1 was NOT overwritten with 99999
        $storedBar1 = DB::table('market_candles')->where('symbol', 'BTCUSDT')->where('open_time', date('Y-m-d H:i:s', (int) ($conflictingRow[0] / 1000)))->first();
        self::assertSame('102', rtrim(rtrim((string) $storedBar1->close, '0'), '.'));

        // 6. Verification: conflict is durably stored in database
        self::assertSame(1, MarketCandleConflictRecord::query()->count());
        $conflict = MarketCandleConflictRecord::query()->first();
        self::assertSame(1, $conflict->occurrence_count);

        // 7. Repeated ingestion with the same conflict increments occurrence_count without duplicate rows
        $result2 = app(BinanceCandleIngestion::class)->ingest('BTCUSDT', '5m', 3, quarantineConflicts: true);
        self::assertSame(0, $result2['new_closed_candles']);
        self::assertSame(1, MarketCandleConflictRecord::query()->count());
        $conflict->refresh();
        self::assertSame(2, $conflict->occurrence_count);
    }

    public function test_scoped_fail_closed_gate_blocks_only_overlapping_candle_window(): void
    {
        $conflictService = app(MarketCandleConflictService::class);

        // Record a conflict at 2026-10-27T00:00:00Z on BTCUSDT 30m
        $conflictService->recordConflict(
            'binance',
            'linear',
            'BTCUSDT',
            '30',
            '2026-10-27T00:00:00Z',
            ['close' => '80100.00'],
            ['close' => '80241.30'],
            'close',
            'Conflicting immutable Binance close observation.'
        );

        // Reference time: 2026-10-29 21:00:00 UTC
        $now = strtotime('2026-10-29 21:00:00 UTC');

        // 1. BTCUSDT 5m window: 220 bars of 5m (300s) spans 66000s (~18.3 hours).
        // Oldest bar is at $now - 66000 = 2026-10-29 02:40:00.
        // Oct 27 00:00 is outside the window.
        $fiveMinWindow = range(0, 219);
        $fiveMinTimes = array_map(fn ($i) => $now - 300 - ($i * 300), $fiveMinWindow);
        $status5m = BinanceCandleWindow::status($fiveMinTimes, 300, $now, 220, $conflictService, 'binance', 'BTCUSDT', '5');
        self::assertSame('READY', $status5m, '5m window does NOT overlap Oct 27 conflict');

        // 2. BTCUSDT 30m window: 220 bars of 30m (1800s) spans 396000s (~4.58 days).
        // Oldest bar is at $now - 396000 = 2026-10-25 07:00:00.
        // Oct 27 00:00 IS inside the window!
        $thirtyMinWindow = range(0, 219);
        $thirtyMinTimes = array_map(fn ($i) => $now - 1800 - ($i * 1800), $thirtyMinWindow);
        $status30m = BinanceCandleWindow::status($thirtyMinTimes, 1800, $now, 220, $conflictService, 'binance', 'BTCUSDT', '30');
        self::assertSame('CANDLE_HISTORY_INTEGRITY_CONFLICT', $status30m, '30m window DOES overlap Oct 27 conflict');

        // 3. ETHUSDT 30m window: ETHUSDT has no conflict in this test!
        $statusEth30m = BinanceCandleWindow::status($thirtyMinTimes, 1800, $now, 220, $conflictService, 'binance', 'ETHUSDT', '30');
        self::assertSame('READY', $statusEth30m, 'ETHUSDT has no conflict and remains READY');
    }

    private function makeRow(int $index): array
    {
        $start = 1767225600000 + $index * 300000;

        return [
            $start,
            '100.000000000000',
            '105.000000000000',
            '99.000000000000',
            '102.000000000000',
            '10.000000000000',
            $start + 299999,
            '1010.000000000000',
        ];
    }
}
