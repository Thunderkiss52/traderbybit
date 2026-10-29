<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData;

use App\MarketData\Application\FundingHistorySynchronizer;
use App\MarketData\Contracts\BybitMarketDataClient;
use App\MarketData\Contracts\FundingMarkPriceProvider;
use App\MarketData\Domain\CandleQuery;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Infrastructure\Persistence\MarketFundingEventRecord;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FundingHistorySynchronizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_trade_candle_is_not_a_verified_settlement_mark(): void
    {
        CandleRecord::query()->create([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'interval' => '5',
            'open_time' => '2026-10-15T07:55:00Z', 'open' => '100', 'high' => '102', 'low' => '99',
            'close' => '101', 'volume' => '10', 'turnover' => '1000', 'is_closed' => true,
        ]);
        $client = $this->createStub(BybitMarketDataClient::class);
        $client->method('fundingRates')->willReturn([[
            'symbol' => 'BTCUSDT', 'fundingRate' => '0.0001',
            'fundingRateTimestamp' => '1792051200000',
        ]]);
        $result = (new FundingHistorySynchronizer($client, ['initial_lookback_days' => 30]))
            ->sync('BTCUSDT', new DateTimeImmutable('2026-10-15T08:01:00Z'));
        self::assertSame(0, $result['inserted']);
        self::assertSame(1, $result['missing_mark_price']);
    }

    public function test_boundary_mark_price_is_persisted_idempotently_without_trade_price_substitution(): void
    {
        CandleRecord::query()->create([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'interval' => '5',
            'open_time' => '2026-10-15T07:55:00Z', 'open' => '100', 'high' => '102', 'low' => '99',
            'close' => '101', 'volume' => '10', 'turnover' => '1000', 'is_closed' => true,
        ]);
        $timestamp = (string) ((new DateTimeImmutable('2026-10-15T08:00:00Z'))->getTimestamp() * 1000);
        $client = new class($timestamp) implements BybitMarketDataClient, FundingMarkPriceProvider
        {
            public function __construct(private readonly string $timestamp) {}

            public function fundingMarkPrice(string $symbol, int $atMilliseconds): ?string
            {
                return '103';
            }

            public function candles(CandleQuery $query): array
            {
                return [];
            }

            public function ticker(string $category, string $symbol): array
            {
                return [];
            }

            public function orderBook(string $category, string $symbol, int $limit = 50): array
            {
                return [];
            }

            public function fundingRates(string $category, string $symbol, ?int $fromMs = null, ?int $toMs = null): array
            {
                return [['symbol' => $symbol, 'fundingRate' => '0.0001', 'fundingRateTimestamp' => $this->timestamp]];
            }

            public function openInterest(string $category, string $symbol, string $intervalTime, ?string $cursor = null): array
            {
                return [];
            }
        };
        $service = new FundingHistorySynchronizer($client, ['initial_lookback_days' => 30]);
        $through = new DateTimeImmutable('2026-10-15T08:01:00Z');

        $first = $service->sync('BTCUSDT', $through);
        self::assertSame(1, $first['inserted'], json_encode($first, JSON_THROW_ON_ERROR));
        self::assertSame(0, $service->sync('BTCUSDT', $through)['inserted']);
        $event = MarketFundingEventRecord::query()->sole();
        self::assertDecimalSame('103', $event->mark_price);
        self::assertSame('BYBIT_MARK_KLINE_OPEN', $event->mark_price_source);
        self::assertDecimalSame('0.0001', $event->rate);
    }

    public function test_missing_closed_mark_candle_does_not_fabricate_funding_price(): void
    {
        $client = $this->createStub(BybitMarketDataClient::class);
        $client->method('fundingRates')->willReturn([[
            'symbol' => 'BTCUSDT', 'fundingRate' => '0.0001',
            'fundingRateTimestamp' => (string) ((new DateTimeImmutable('2026-10-15T08:00:00Z'))->getTimestamp() * 1000),
        ]]);
        $result = (new FundingHistorySynchronizer($client, ['initial_lookback_days' => 30]))
            ->sync('BTCUSDT', new DateTimeImmutable('2026-10-15T08:01:00Z'));

        self::assertSame(0, $result['inserted']);
        self::assertSame(1, $result['missing_mark_price']);
        self::assertSame(0, MarketFundingEventRecord::query()->count());
    }
}
