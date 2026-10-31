<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exchanges\Binance\BinanceCandleBackfillService;
use App\Exchanges\Binance\BinanceCandleIngestion;
use App\Exchanges\Binance\BinancePaperProvisioner;
use App\PaperTrading\Application\PaperAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\Concerns\CleansCandleConflictEvidence;
use Tests\TestCase;

final class BinanceCandleBackfillIntegrationTest extends TestCase
{
    use CleansCandleConflictEvidence;
    use RefreshDatabase;

    private const STEP_MS = 1_800_000;

    private const FROM_MS = 1793257200000; // 2026-10-29 07:00 UTC

    private const TO_MS = 1793343600000; // 2026-10-30 07:00 UTC

    private array $apiRows = [];

    private ?\Closure $responseOverride = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerConflictCleanup();
        config(['market_data.symbols' => ['BTCUSDT', 'ETHUSDT']]);
        Http::preventStrayRequests();
        $this->fakeRows();
    }

    public function test_exactly_31_missing_30m_candles_are_recovered_for_each_symbol_without_paper_effects(): void
    {
        config(['exchanges.binance_paper_provisioning_approved' => true]);
        app(PaperAccountService::class)->allPrimaryAccounts();
        app(BinancePaperProvisioner::class)->provision();
        self::assertSame(26, DB::table('paper_accounts')->where('is_primary', true)->count());
        self::assertSame(13, DB::table('paper_accounts')->where('exchange_id', 'binance')->where('status', 'BLOCKED')->count());
        foreach (['BTCUSDT', 'ETHUSDT'] as $symbol) {
            $this->apiRows[$symbol] = $this->rows(self::FROM_MS, self::TO_MS, self::STEP_MS);
            foreach ($this->apiRows[$symbol] as $index => $row) {
                if ($index < 16 || $index === 47) {
                    $this->seedCandle($symbol, '30', $row);
                }
            }
        }
        $this->fakeRows();
        $before = $this->financialCounts();
        $accountRowsBefore = DB::table('paper_accounts')->orderBy('id')->get()->toJson();
        $pipelineEventsBefore = DB::table('trading_pipeline_events')->count();

        foreach (['BTCUSDT', 'ETHUSDT'] as $symbol) {
            $report = app(BinanceCandleBackfillService::class)->backfill($symbol, '30m', self::FROM_MS, self::TO_MS);
            self::assertSame('COMPLETED', $report['status']);
            self::assertSame(31, $report['inserted_count']);
            self::assertSame(0, $report['remaining_gap_count']);
            self::assertSame(48, DB::table('market_candles')->where('exchange', 'binance')->where('symbol', $symbol)->where('interval', '30')->count());
        }
        self::assertSame($before, $this->financialCounts());
        self::assertSame($accountRowsBefore, DB::table('paper_accounts')->orderBy('id')->get()->toJson());
        self::assertSame($pipelineEventsBefore, DB::table('trading_pipeline_events')->count());
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://fapi.binance.com/fapi/v1/klines')
            && $request['interval'] === '30m'
            && (int) $request['startTime'] === self::FROM_MS
            && (int) $request['endTime'] === self::TO_MS - 1
            && (int) $request['limit'] === 500);
    }

    public function test_repeated_backfill_preserves_every_existing_candle(): void
    {
        $this->apiRows['BTCUSDT'] = $this->rows(self::FROM_MS, self::FROM_MS + 3 * self::STEP_MS, self::STEP_MS);
        $this->fakeRows();
        $first = app(BinanceCandleBackfillService::class)->backfill('BTCUSDT', '30m', self::FROM_MS, self::FROM_MS + 3 * self::STEP_MS);
        self::assertSame(3, $first['inserted_count']);
        $before = DB::table('market_candles')->orderBy('open_time')->get()->toJson();
        $second = app(BinanceCandleBackfillService::class)->backfill('BTCUSDT', '30m', self::FROM_MS, self::FROM_MS + 3 * self::STEP_MS);
        self::assertSame('COMPLETED', $second['status']);
        self::assertSame(0, $second['inserted_count']);
        self::assertSame($before, DB::table('market_candles')->orderBy('open_time')->get()->toJson());
    }

    public function test_501_candles_use_two_bounded_api_windows_without_loss(): void
    {
        $fromMs = strtotime('2026-10-01T00:00:00Z') * 1000;
        $toMs = $fromMs + 501 * self::STEP_MS;
        $this->apiRows['BTCUSDT'] = $this->rows($fromMs, $toMs, self::STEP_MS);
        $report = app(BinanceCandleBackfillService::class)->backfill('BTCUSDT', '30m', $fromMs, $toMs);
        self::assertSame('COMPLETED', $report['status']);
        self::assertSame(501, $report['inserted_count']);
        self::assertSame(2, $report['requested_count']);
        self::assertSame(501, DB::table('market_candles')->where('symbol', 'BTCUSDT')->count());
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => (int) $request['startTime'] === $fromMs
            && (int) $request['endTime'] === $fromMs + 500 * self::STEP_MS - 1);
        Http::assertSent(fn (Request $request): bool => (int) $request['startTime'] === $fromMs + 500 * self::STEP_MS
            && (int) $request['endTime'] === $toMs - 1);
    }

    public function test_current_unclosed_candle_is_never_persisted(): void
    {
        $currentOpen = intdiv((int) floor(microtime(true) * 1000), self::STEP_MS) * self::STEP_MS;
        $earlierOpen = $currentOpen - 2 * self::STEP_MS;
        $this->apiRows['BTCUSDT'] = [
            $this->rows($earlierOpen, $earlierOpen + self::STEP_MS, self::STEP_MS)[0],
            $this->rows($currentOpen, $currentOpen + self::STEP_MS, self::STEP_MS)[0],
        ];
        $result = app(BinanceCandleIngestion::class)->ingestRange('BTCUSDT', '30m', $earlierOpen, $currentOpen + self::STEP_MS);
        self::assertSame(1, $result['new_closed_candles']);
        self::assertSame(0, DB::table('market_candles')->where('open_time', gmdate('Y-m-d H:i:s', intdiv($currentOpen, 1000)))->count());
    }

    public function test_no_progress_reports_the_exact_missing_slots(): void
    {
        $toMs = self::FROM_MS + 3 * self::STEP_MS;
        $report = app(BinanceCandleBackfillService::class)->backfill('BTCUSDT', '30m', self::FROM_MS, $toMs);
        self::assertSame('PARTIAL', $report['status']);
        self::assertSame(3, $report['remaining_gap_count']);
        self::assertSame([self::FROM_MS, self::FROM_MS + self::STEP_MS, self::FROM_MS + 2 * self::STEP_MS], $report['remaining_from_ms']);
        self::assertSame(0, DB::table('market_candles')->count());
    }

    public function test_conflicting_observation_is_quarantined_and_original_candle_is_immutable(): void
    {
        $row = $this->rows(self::FROM_MS, self::FROM_MS + self::STEP_MS, self::STEP_MS)[0];
        $this->seedCandle('BTCUSDT', '30', $row);
        $before = DB::table('market_candles')->first();
        $row[2] = '106';
        $this->apiRows['BTCUSDT'] = [$row];
        $this->fakeRows();
        $report = app(BinanceCandleBackfillService::class)->backfill('BTCUSDT', '30m', self::FROM_MS, self::FROM_MS + self::STEP_MS);
        self::assertSame('QUARANTINED', $report['status']);
        self::assertSame(1, $report['conflict_count']);
        self::assertEquals($before, DB::table('market_candles')->first());
        self::assertSame(1, DB::table('market_candle_conflicts')->where('exchange', 'binance')->where('symbol', 'BTCUSDT')->count());
    }

    public function test_partial_api_failure_retains_committed_candles_and_resume_fills_only_missing_slots(): void
    {
        $all = $this->rows(self::FROM_MS, self::FROM_MS + 4 * self::STEP_MS, self::STEP_MS);
        $calls = 0;
        $this->responseOverride = function () use (&$calls, $all) {
            $calls++;

            return $calls === 1 ? Http::response(array_slice($all, 0, 2)) : Http::response(['code' => -1], 400);
        };
        try {
            app(BinanceCandleBackfillService::class)->backfill('BTCUSDT', '30m', self::FROM_MS, self::FROM_MS + 4 * self::STEP_MS);
            self::fail('The failed API page must not be acknowledged.');
        } catch (RuntimeException) {
            self::assertSame(2, DB::table('market_candles')->where('symbol', 'BTCUSDT')->count());
        }

        $this->apiRows['BTCUSDT'] = $all;
        $this->responseOverride = null;
        $report = app(BinanceCandleBackfillService::class)->backfill('BTCUSDT', '30m', self::FROM_MS, self::FROM_MS + 4 * self::STEP_MS);
        self::assertSame('COMPLETED', $report['status']);
        self::assertSame(2, $report['inserted_count']);
        self::assertSame(4, DB::table('market_candles')->where('symbol', 'BTCUSDT')->count());
    }

    public function test_failed_response_and_out_of_range_response_write_no_history(): void
    {
        $this->responseOverride = static fn () => Http::response(['code' => -1], 400);
        try {
            app(BinanceCandleBackfillService::class)->backfill('BTCUSDT', '30m', self::FROM_MS, self::FROM_MS + self::STEP_MS);
            self::fail('API error must fail recovery.');
        } catch (RuntimeException) {
            self::assertSame(0, DB::table('market_candles')->count());
        }

        $this->responseOverride = fn () => Http::response($this->rows(self::FROM_MS + self::STEP_MS, self::FROM_MS + 2 * self::STEP_MS, self::STEP_MS));
        $this->expectException(\DomainException::class);
        try {
            app(BinanceCandleIngestion::class)->ingestRange('BTCUSDT', '30m', self::FROM_MS, self::FROM_MS + self::STEP_MS);
        } finally {
            self::assertSame(0, DB::table('market_candles')->count());
        }
    }

    public function test_oversized_or_unaligned_range_is_rejected_before_an_api_request(): void
    {
        try {
            app(BinanceCandleIngestion::class)->ingestRange('BTCUSDT', '30m', self::FROM_MS + 1, self::TO_MS);
            self::fail('Unaligned range must fail.');
        } catch (InvalidArgumentException) {
            Http::assertNothingSent();
        }
        $this->expectException(InvalidArgumentException::class);
        app(BinanceCandleIngestion::class)->ingestRange('BTCUSDT', '30m', self::FROM_MS, self::FROM_MS + 501 * self::STEP_MS);
    }

    private function fakeRows(): void
    {
        Http::fake(['https://fapi.binance.com/fapi/v1/klines*' => function (Request $request) {
            if ($this->responseOverride !== null) {
                return ($this->responseOverride)($request);
            }
            $rows = $this->apiRows[(string) $request['symbol']] ?? [];

            return Http::response(array_values(array_filter($rows, static fn (array $row): bool => $row[0] >= (int) $request['startTime'] && $row[0] <= (int) $request['endTime'])));
        }]);
    }

    private function rows(int $fromMs, int $toMs, int $stepMs): array
    {
        $rows = [];
        for ($openMs = $fromMs; $openMs < $toMs; $openMs += $stepMs) {
            $rows[] = [$openMs, '100', '105', '99', '102', '10', $openMs + $stepMs - 1, '1010'];
        }

        return $rows;
    }

    private function seedCandle(string $symbol, string $interval, array $row): void
    {
        DB::table('market_candles')->insert([
            'exchange' => 'binance', 'category' => 'linear', 'symbol' => $symbol, 'interval' => $interval,
            'open_time' => gmdate('Y-m-d H:i:s', intdiv($row[0], 1000)),
            'open' => $row[1], 'high' => $row[2], 'low' => $row[3], 'close' => $row[4],
            'volume' => $row[5], 'turnover' => $row[7], 'is_closed' => true,
        ]);
    }

    private function financialCounts(): array
    {
        $counts = [];
        foreach (['paper_accounts', 'virtual_positions', 'virtual_executions', 'equity_snapshots', 'trading_journal_events', 'risk_decisions', 'signals'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
