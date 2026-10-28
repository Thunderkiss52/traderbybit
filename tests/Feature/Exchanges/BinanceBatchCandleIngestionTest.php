<?php

declare(strict_types=1);

namespace Tests\Feature\Exchanges;

use App\Exchanges\Binance\BinanceCandleIngestion;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class BinanceBatchCandleIngestionTest extends TestCase
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

    public function test_maximum_batch_inserts_once_and_repeated_duplicates_issue_no_writes(): void
    {
        $this->responseRows = array_map($this->row(...), range(0, 499));
        self::assertSame(500, $this->ingestWithQueryBudget(2));
        $before = DB::table('market_candles')->orderBy('id')->get()->toJson();
        // Equivalent source formatting must preserve exact 12-place decimals and all timestamps.
        $this->responseRows[0][2] = '105.000000000000';
        for ($retry = 0; $retry < 3; $retry++) {
            self::assertSame(0, $this->ingestWithQueryBudget(1));
            self::assertSame($before, DB::table('market_candles')->orderBy('id')->get()->toJson());
        }
    }

    public function test_mixed_batch_only_inserts_missing_identities(): void
    {
        $this->responseRows = [$this->row(1), $this->row(3)];
        self::assertSame(2, $this->ingestWithQueryBudget(2));
        $before = DB::table('market_candles')->orderBy('id')->get();
        $this->responseRows = array_map($this->row(...), range(0, 4));
        self::assertSame(3, $this->ingestWithQueryBudget(2));
        self::assertEquals($before, DB::table('market_candles')->whereIn('id', $before->pluck('id'))->orderBy('id')->get());
        self::assertSame(5, DB::table('market_candles')->count());
    }

    #[DataProvider('conflictingColumns')]
    public function test_each_exact_ohlcv_conflict_fails_closed_and_can_be_retried(int $column, string $value, string $name): void
    {
        $this->responseRows = [$this->row(1)];
        $this->ingestWithQueryBudget(2);
        $before = DB::table('market_candles')->get()->toJson();
        $this->responseRows = [$this->row(0), $this->row(1), $this->row(2)];
        $this->responseRows[1][$column] = $value;
        Log::spy();
        try {
            app(BinanceCandleIngestion::class)->ingest('BTCUSDT', '5m', 3);
            self::fail('Conflicting batch accepted.');
        } catch (DomainException $exception) {
            self::assertSame('Conflicting immutable Binance '.$name.' observation.', $exception->getMessage());
        }
        Log::shouldHaveReceived('error')->once()->with('Binance immutable candle validation failed', \Mockery::on(
            fn (array $context): bool => $context['exchange'] === 'binance'
                && $context['symbol'] === 'BTCUSDT' && $context['interval'] === '5'
                && $context['source'] === 'binance_public_rest'
                && isset($context['open_time_utc'], $context['stored'][$name], $context['incoming'][$name])
                && $context['incoming'][$name] === $value
        ));
        self::assertSame($before, DB::table('market_candles')->get()->toJson());
        $this->responseRows[1] = $this->row(1);
        self::assertSame(2, $this->ingestWithQueryBudget(2));
        self::assertSame(0, $this->ingestWithQueryBudget(1));
    }

    public static function conflictingColumns(): array
    {
        return [
            'open' => [1, '100.000000000002', 'open'],
            'high' => [2, '105.000000000001', 'high'],
            'low' => [3, '99.000000000001', 'low'],
            'close' => [4, '102.000000000001', 'close'],
            'volume' => [5, '10.000000000001', 'volume'],
            'turnover' => [7, '1010.000000000001', 'turnover'],
        ];
    }

    public function test_full_identity_scope_preserves_other_exchanges_categories_symbols_and_intervals(): void
    {
        $this->responseRows = [$this->row(0)];
        $this->ingestWithQueryBudget(2);
        $template = (array) DB::table('market_candles')->first();
        unset($template['id']);
        foreach ([['exchange' => 'bybit'], ['category' => 'spot'], ['symbol' => 'ETHUSDT'], ['interval' => '15']] as $scope) {
            DB::table('market_candles')->insert(array_replace($template, $scope, ['close' => '103']));
        }
        $before = DB::table('market_candles')->orderBy('id')->get();
        $this->responseRows[] = $this->row(1);
        self::assertSame(1, $this->ingestWithQueryBudget(2));
        self::assertEquals($before, DB::table('market_candles')->whereIn('id', $before->pluck('id'))->orderBy('id')->get());
    }

    public function test_unclosed_stored_identity_is_never_overwritten(): void
    {
        $this->responseRows = [$this->row(0)];
        $this->ingestWithQueryBudget(2);
        DB::table('market_candles')->update(['is_closed' => false]);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Conflicting existing Binance candle closed state.');
        app(BinanceCandleIngestion::class)->ingest('BTCUSDT', '5m', 1);
    }

    public function test_empty_and_unclosed_source_batches_do_not_write(): void
    {
        self::assertSame(0, $this->ingestWithQueryBudget(0));
        $open = intdiv(time(), 300) * 300000;
        $this->responseRows = [array_replace($this->row(0), [0 => $open, 6 => $open + 299999])];
        self::assertSame(0, $this->ingestWithQueryBudget(0));
        self::assertSame(0, DB::table('market_candles')->count());
    }

    public function test_nonrepresentable_decimal_rejects_entire_batch_before_insertion(): void
    {
        $this->responseRows = [$this->row(0), $this->row(1)];
        $this->responseRows[1][4] = '102.0000000000001';
        try {
            app(BinanceCandleIngestion::class)->ingest('BTCUSDT', '5m', 2);
            self::fail('Rounded source value was accepted.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('exceeds exact database decimal scale', $exception->getMessage());
        }
        self::assertSame(0, DB::table('market_candles')->count());
    }

    public function test_later_chunk_conflict_rolls_back_earlier_chunk_and_retry_succeeds(): void
    {
        $this->responseRows = [$this->row(500)];
        $this->ingestWithQueryBudget(2);
        $before = DB::table('market_candles')->get()->toJson();
        $this->responseRows = array_map($this->row(...), range(0, 500));
        $this->responseRows[500][4] = '103';
        try {
            app(BinanceCandleIngestion::class)->ingest('BTCUSDT', '5m', 500);
            self::fail('Later chunk conflict was accepted.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('Conflicting immutable Binance close', $exception->getMessage());
        }
        self::assertSame($before, DB::table('market_candles')->get()->toJson());
        $this->responseRows[500] = $this->row(500);
        self::assertSame(500, $this->ingestWithQueryBudget(3));
        self::assertSame(0, $this->ingestWithQueryBudget(2));
    }

    public function test_ignored_insert_without_matching_identity_rolls_back_the_batch(): void
    {
        // Negative control: a database trigger suppresses one row without an identity conflict.
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION pg_temp.suppress_binance_candle() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.exchange = 'binance' AND NEW.open_time = '2026-01-01 00:05:00+00'::timestamptz THEN
                    RETURN NULL;
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER suppress_binance_candle BEFORE INSERT ON market_candles
            FOR EACH ROW EXECUTE FUNCTION pg_temp.suppress_binance_candle();
            SQL);
        $this->responseRows = [$this->row(0), $this->row(1)];
        try {
            app(BinanceCandleIngestion::class)->ingest('BTCUSDT', '5m', 2);
            self::fail('Ignored insert with no matching identity was accepted.');
        } catch (DomainException $exception) {
            self::assertSame('Binance candle insertion was ignored without matching identity.', $exception->getMessage());
        }
        self::assertSame(0, DB::table('market_candles')->count());
    }

    private function row(int $index): array
    {
        $start = 1767225600000 + $index * 300000;

        return [$start, '100.000000000001', '105', '99', '102', '10', $start + 299999, '1010'];
    }

    private function ingestWithQueryBudget(int $expectedQueries): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $result = app(BinanceCandleIngestion::class)->ingest('BTCUSDT', '5m', max(1, min(500, count($this->responseRows))));
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        self::assertCount($expectedQueries, $queries);
        foreach ($queries as $query) {
            // Ingestion may only read candles or insert new candles; no financial writes or history updates.
            self::assertMatchesRegularExpression('/^(select .* from|insert into) "market_candles" /', $query['query']);
        }
        self::assertSame(count($this->responseRows), $result['fetched']);
        self::assertSame('PAPER_EXECUTION_BLOCKED_UNTIL_VERIFIED', $result['execution_status']);

        return $result['new_closed_candles'];
    }
}
