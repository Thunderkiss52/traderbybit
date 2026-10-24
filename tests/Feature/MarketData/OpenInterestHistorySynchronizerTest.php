<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData;

use App\MarketData\Application\OpenInterestHistorySynchronizer;
use App\MarketData\Contracts\BybitMarketDataClient;
use App\MarketData\Infrastructure\Persistence\OpenInterestEventRecord;
use App\PaperTrading\Infrastructure\Persistence\MarketFundingEventRecord;
use App\PatternDiscovery\Application\AsOfMarketContextService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OpenInterestHistorySynchronizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_persist_idempotently_and_exclude_future_observations(): void
    {
        $past = (string) ((new DateTimeImmutable('2026-10-15T08:00:00Z'))->getTimestamp() * 1000);
        $future = (string) ((new DateTimeImmutable('2026-10-15T09:00:00Z'))->getTimestamp() * 1000);
        $client = $this->createMock(BybitMarketDataClient::class);
        $client->expects(self::exactly(4))->method('openInterest')->willReturnOnConsecutiveCalls(
            ['list' => [['openInterest' => '1000', 'timestamp' => $past]], 'nextPageCursor' => 'next'],
            ['list' => [['openInterest' => '1100', 'timestamp' => $future]], 'nextPageCursor' => ''],
            ['list' => [['openInterest' => '1000', 'timestamp' => $past]], 'nextPageCursor' => 'next'],
            ['list' => [['openInterest' => '1100', 'timestamp' => $future]], 'nextPageCursor' => ''],
        );
        $service = new OpenInterestHistorySynchronizer($client, ['interval' => '15min', 'maximum_pages' => 3]);
        $through = new DateTimeImmutable('2026-10-15T08:30:00Z');

        self::assertSame(['received' => 2, 'inserted' => 1, 'pages' => 2], $service->sync('BTCUSDT', $through));
        self::assertSame(0, $service->sync('BTCUSDT', $through)['inserted']);
        $event = OpenInterestEventRecord::query()->sole();
        self::assertDecimalSame('1000', $event->open_interest);
        self::assertSame('15min', $event->interval);
    }

    public function test_research_context_uses_only_events_available_at_decision_time(): void
    {
        foreach ([['2026-10-15T07:30:00Z', '1000'], ['2026-10-15T07:45:00Z', '1100'], ['2026-10-15T08:15:00Z', '5000']] as [$at, $value]) {
            OpenInterestEventRecord::query()->create([
                'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'interval' => '15min',
                'observed_at' => $at, 'open_interest' => $value,
            ]);
        }
        MarketFundingEventRecord::query()->create([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT',
            'funding_at' => '2026-10-15T00:00:00Z', 'rate' => '-0.0001', 'mark_price' => '100',
        ]);
        MarketFundingEventRecord::query()->create([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT',
            'funding_at' => '2026-10-15T16:00:00Z', 'rate' => '0.9000', 'mark_price' => '100',
        ]);

        $context = (new AsOfMarketContextService)->at('bybit', 'linear', 'BTCUSDT', new DateTimeImmutable('2026-10-15T08:00:00Z'));

        self::assertDecimalSame('-0.0001', $context['funding_rate']);
        self::assertDecimalSame('0.100000000000', $context['open_interest_change']);
        self::assertSame((new DateTimeImmutable('2026-10-15T07:45:00Z'))->getTimestamp(), $context['open_interest_observed_at']);
    }
}
