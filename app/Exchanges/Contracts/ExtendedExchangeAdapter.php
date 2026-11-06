<?php

declare(strict_types=1);

namespace App\Exchanges\Contracts;

use App\Exchanges\Domain\CapabilityStatus;
use App\Exchanges\Domain\MarketCapability;
use App\Exchanges\Domain\MarketType;

interface ExtendedExchangeAdapter extends ExchangeAdapter
{
    /**
     * List of market types supported by this exchange adapter.
     *
     * @return list<MarketType>
     */
    public function supportedMarketTypes(): array;

    /**
     * Map of market capability to status for a specific market type.
     *
     * @return array<string, CapabilityStatus> Keyed by MarketCapability value.
     */
    public function getMarketCapabilities(MarketType $marketType): array;

    /**
     * Discover available instruments for a given market type.
     *
     * @return list<array<string, mixed>> Normalized instrument specifications.
     */
    public function fetchInstruments(MarketType $marketType): array;

    /**
     * Fetch Level 2 order book snapshot.
     *
     * @return array{
     *     exchange_id: string,
     *     symbol: string,
     *     timestamp_utc: string,
     *     bids: list<array{0: string, 1: string}>,
     *     asks: list<array{0: string, 1: string}>,
     *     raw_source_hash: string
     * }
     */
    public function fetchOrderBook(string $symbol, int $depth = 20): array;

    /**
     * Fetch latest market price ticker.
     *
     * @return array{
     *     exchange_id: string,
     *     symbol: string,
     *     last_price: string,
     *     bid_price: ?string,
     *     ask_price: ?string,
     *     volume_24h: string,
     *     turnover_24h: ?string,
     *     timestamp_utc: string,
     *     raw_source_hash: string
     * }
     */
    public function fetchTicker(string $symbol): array;

    /**
     * Perform public endpoint health check and report latency.
     *
     * @return array{
     *     status: string,
     *     latency_ms: int,
     *     endpoint: string,
     *     timestamp_utc: string,
     *     error: ?string
     * }
     */
    public function healthCheck(): array;
}
