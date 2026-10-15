<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData;

use App\MarketData\Application\CandleSynchronizer;
use App\MarketData\Application\GapDetector;
use App\MarketData\Contracts\BybitMarketDataClient;
use App\MarketData\Domain\Candle;
use App\MarketData\Domain\CandleQuery;
use App\MarketData\Domain\Interval;
use App\MarketData\Infrastructure\Persistence\EloquentCandleStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CandleSynchronizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_filters_open_candles_detects_gap_and_backfills_it(): void
    {
        $client = new BackfillClient([
            [$this->candle(0), $this->candle(600_000), $this->candle(900_000, false)],
            [$this->candle(300_000)],
        ]);
        $synchronizer = new CandleSynchronizer(
            $client,
            new EloquentCandleStore,
            new GapDetector,
            backfillPasses: 2,
            lockSeconds: 10,
        );

        $report = $synchronizer->sync(new CandleQuery('linear', 'BTCUSDT', Interval::FiveMinutes, 0, 900_000));

        self::assertTrue($report->isComplete());
        self::assertSame(1, $report->backfillRequests);
        $this->assertDatabaseCount('market_candles', 3);
        $this->assertDatabaseMissing('market_candles', ['open_time' => '1970-01-01 00:15:00']);
    }

    public function test_it_reports_history_that_bybit_does_not_return(): void
    {
        $synchronizer = new CandleSynchronizer(
            new BackfillClient([[], []]),
            new EloquentCandleStore,
            new GapDetector,
            backfillPasses: 1,
            lockSeconds: 10,
        );

        $report = $synchronizer->sync(new CandleQuery('linear', 'BTCUSDT', Interval::FiveMinutes, 0, 600_000));

        self::assertFalse($report->isComplete());
        self::assertSame([0, 300_000], $report->missingOpenTimesMs);
        $this->assertDatabaseCount('market_candles', 0);
    }

    private function candle(int $openTime, bool $closed = true): Candle
    {
        return new Candle('bybit', 'linear', 'BTCUSDT', Interval::FiveMinutes, $openTime, '10', '12', '9', '11', '1', '11', $closed);
    }
}

final class BackfillClient implements BybitMarketDataClient
{
    /** @param list<list<Candle>> $responses */
    public function __construct(private array $responses) {}

    public function candles(CandleQuery $query): array
    {
        return array_shift($this->responses) ?? [];
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
        return [];
    }

    public function openInterest(string $category, string $symbol, string $intervalTime, ?string $cursor = null): array
    {
        return [];
    }
}
