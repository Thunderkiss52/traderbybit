<?php

declare(strict_types=1);

namespace App\Strategy\Application;

use App\MarketData\Contracts\BybitMarketDataClient;
use App\MarketData\Contracts\InstrumentMetadataProvider;
use App\Strategy\Contracts\MarketContextRepository;
use App\Strategy\Domain\MarketContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final readonly class MarketContextCollector
{
    public function __construct(
        private BybitMarketDataClient $marketData,
        private InstrumentMetadataProvider $metadata,
        private MarketContextRepository $contexts,
    ) {}

    public function collect(string $symbol, string $exchange = 'bybit', string $category = 'linear'): MarketContext
    {
        $ticker = $this->marketData->ticker($category, $symbol);
        $book = $this->marketData->orderBook($category, $symbol, 1);
        $instrument = $this->metadata->instrumentInfo($category, $symbol);
        $bid = $book['b'][0][0] ?? null;
        $ask = $book['a'][0][0] ?? null;
        $tickSize = $instrument['priceFilter']['tickSize'] ?? null;
        $lot = $instrument['lotSizeFilter'] ?? null;
        if (! is_string($bid) || ! is_string($ask) || ! is_string($tickSize) || ! is_array($lot)
            || ! is_string($lot['qtyStep'] ?? null) || ! is_string($lot['minOrderQty'] ?? null)
            || ! is_string($lot['maxOrderQty'] ?? null) || ! is_string($lot['minNotionalValue'] ?? null)) {
            throw new RuntimeException('Bybit context response is incomplete.');
        }
        $milliseconds = (int) $book['ts'];
        $observedAt = DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', intdiv($milliseconds, 1000), ($milliseconds % 1000) * 1000), new DateTimeZone('UTC'));
        if ($observedAt === false) {
            throw new RuntimeException('Invalid Bybit order-book timestamp.');
        }
        $previous = $this->contexts->latestAt($exchange, $category, $symbol, $observedAt);
        $openInterest = (string) $ticker['openInterest'];
        $change = null;
        if ($previous?->openInterest !== null && ! BigDecimal::of($previous->openInterest)->isZero()) {
            $change = (string) BigDecimal::of($openInterest)->minus($previous->openInterest)->dividedBy($previous->openInterest, 12, RoundingMode::HalfEven);
        }
        $context = new MarketContext(
            $observedAt, $bid, $ask, (string) $ticker['turnover24h'], $tickSize, (string) $ticker['fundingRate'], $openInterest, $change,
            $lot['qtyStep'], $lot['minOrderQty'], $lot['maxOrderQty'], $lot['minNotionalValue'],
        );
        $this->contexts->store($exchange, $category, $symbol, $context);

        return $context;
    }
}
