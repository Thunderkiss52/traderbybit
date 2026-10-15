<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy;

use App\MarketData\Contracts\BybitMarketDataClient;
use App\MarketData\Contracts\InstrumentMetadataProvider;
use App\MarketData\Domain\CandleQuery;
use App\Strategy\Application\MarketContextCollector;
use App\Strategy\Contracts\MarketContextRepository;
use App\Strategy\Domain\MarketContext;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class MarketContextCollectorTest extends TestCase
{
    public function test_it_builds_a_timestamped_context_and_deterministic_oi_change(): void
    {
        $repository = new MemoryContextRepository(new MarketContext(new DateTimeImmutable('2026-10-13T10:14:00Z'), '99', '100', '1', '0.1', openInterest: '1000'));
        $collector = new MarketContextCollector(new ContextMarketClient, new ContextMetadataProvider, $repository);

        $context = $collector->collect('BTCUSDT');

        self::assertSame('2026-10-13T10:15:00.000+00:00', $context->observedAt->format('Y-m-d\TH:i:s.vP'));
        self::assertSame('100.0', $context->bid);
        self::assertSame('100.1', $context->ask);
        self::assertSame('0.1', $context->tickSize);
        self::assertSame('0.001', $context->qtyStep);
        self::assertSame('0.100000000000', $context->openInterestChange);
        self::assertSame($context, $repository->stored);
    }
}

final class ContextMarketClient implements BybitMarketDataClient
{
    public function candles(CandleQuery $query): array
    {
        return [];
    }

    public function ticker(string $category, string $symbol): array
    {
        return ['turnover24h' => '100000000', 'fundingRate' => '0.0001', 'openInterest' => '1100'];
    }

    public function orderBook(string $category, string $symbol, int $limit = 50): array
    {
        return ['b' => [['100.0', '1']], 'a' => [['100.1', '1']], 'ts' => 1791886500000];
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

final class ContextMetadataProvider implements InstrumentMetadataProvider
{
    public function instrumentInfo(string $category, string $symbol): array
    {
        return [
            'symbol' => $symbol,
            'priceFilter' => ['tickSize' => '0.1'],
            'lotSizeFilter' => ['qtyStep' => '0.001', 'minOrderQty' => '0.001', 'maxOrderQty' => '100', 'minNotionalValue' => '5'],
        ];
    }
}

final class MemoryContextRepository implements MarketContextRepository
{
    public ?MarketContext $stored = null;

    public function __construct(private readonly ?MarketContext $previous) {}

    public function latestAt(string $exchange, string $category, string $symbol, DateTimeImmutable $at): ?MarketContext
    {
        return $this->previous;
    }

    public function store(string $exchange, string $category, string $symbol, MarketContext $context): void
    {
        $this->stored = $context;
    }
}
