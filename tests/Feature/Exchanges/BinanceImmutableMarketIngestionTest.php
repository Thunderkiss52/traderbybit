<?php

declare(strict_types=1);

namespace Tests\Feature\Exchanges;

use App\Exchanges\Binance\BinanceCandleIngestion;
use App\Exchanges\Binance\BinanceFundingIngestion;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CleansCandleConflictEvidence;
use Tests\TestCase;

final class BinanceImmutableMarketIngestionTest extends TestCase
{
    use CleansCandleConflictEvidence;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerConflictCleanup();
        Http::preventStrayRequests();
    }

    public function test_candle_replay_is_idempotent_and_conflicting_batch_rolls_back(): void
    {
        config()->set('market_data.symbols', ['BTCUSDT', 'ETHUSDT']);
        $start = intdiv(now('UTC')->subMinutes(30)->getTimestamp(), 300) * 300000;
        $first = [$start, '100', '105', '99', '102', '10', $start + 299999, '1010'];
        $responseRows = [$first];
        Http::fake(['https://fapi.binance.com/fapi/v1/klines*' => static function () use (&$responseRows) {
            return Http::response($responseRows);
        }]);
        $ingestion = app(BinanceCandleIngestion::class);
        self::assertSame(1, $ingestion->ingest('BTCUSDT', '5m', 1)['new_closed_candles']);
        self::assertSame(0, $ingestion->ingest('BTCUSDT', '5m', 1)['new_closed_candles']);

        $second = [$start - 300000, '100', '106', '99', '103', '10', $start - 1, '1010'];
        $changed = $first;
        $changed[4] = '103';
        $responseRows = [$second, $changed];
        try {
            $ingestion->ingest('BTCUSDT', '5m', 2);
            self::fail('Conflicting immutable candle was accepted.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('Conflicting immutable Binance close', $exception->getMessage());
        }
        self::assertSame(1, DB::table('market_candles')->where('exchange', 'binance')->count());
        self::assertDecimalSame('102', (string) DB::table('market_candles')->where('exchange', 'binance')->value('close'));
    }

    public function test_funding_conflict_rolls_back_new_settlements_without_altering_existing(): void
    {
        config()->set('market_data.symbols', ['BTCUSDT', 'ETHUSDT']);
        $time = now('UTC')->subHours(4)->startOfHour()->getTimestamp() * 1000;
        $event = ['symbol' => 'BTCUSDT', 'fundingTime' => $time,
            'fundingRate' => '0.0001', 'markPrice' => '100'];
        $responseRows = [$event];
        Http::fake(['https://fapi.binance.com/fapi/v1/fundingRate*' => static function () use (&$responseRows) {
            return Http::response($responseRows);
        }]);
        $ingestion = app(BinanceFundingIngestion::class);
        self::assertSame(1, $ingestion->ingest('BTCUSDT')['inserted']);
        self::assertSame(0, $ingestion->ingest('BTCUSDT')['inserted']);

        $new = ['symbol' => 'BTCUSDT', 'fundingTime' => $time + 3600000,
            'fundingRate' => '0.0002', 'markPrice' => '102'];
        $changed = array_replace($event, ['fundingRate' => '0.0003']);
        $responseRows = [$new, $changed];
        try {
            $ingestion->ingest('BTCUSDT');
            self::fail('Conflicting immutable funding event was accepted.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('Conflicting immutable Binance funding rate', $exception->getMessage());
        }
        self::assertSame(1, DB::table('market_funding_events')->where('exchange', 'binance')->count());
        self::assertDecimalSame('0.0001', (string) DB::table('market_funding_events')->where('exchange', 'binance')->value('rate'));
    }
}
