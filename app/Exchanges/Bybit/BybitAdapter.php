<?php

declare(strict_types=1);

namespace App\Exchanges\Bybit;

use App\Exchanges\Contracts\ExchangeAdapter;
use App\MarketData\Contracts\BybitMarketDataClient;
use App\MarketData\Domain\CandleQuery;
use App\MarketData\Domain\Interval;
use InvalidArgumentException;

/** Read-only adapter delegating to the existing production Bybit public client. */
final readonly class BybitAdapter implements ExchangeAdapter
{
    public function __construct(private BybitMarketDataClient $client) {}

    public function id(): string
    {
        return 'bybit';
    }

    public function fetchCandles(string $symbol, string $interval, int $limit, ?int $startMs = null, ?int $endMs = null): array
    {
        if ($limit < 1 || $limit > 500 || $startMs === null || $endMs === null) {
            throw new InvalidArgumentException('Bybit candle adapter requires a bounded historical window and limit <=500.');
        }
        $candles = $this->client->candles(new CandleQuery('linear', $symbol, Interval::parse(['1m' => '1', '3m' => '3', '5m' => '5', '15m' => '15', '30m' => '30', '1h' => '60', '4h' => '240', '1d' => 'D'][$interval] ?? $interval), $startMs, $endMs));

        return array_map(static fn ($c): array => [
            'exchange_id' => 'bybit', 'symbol' => $c->symbol, 'interval' => $interval,
            'open_time_utc' => gmdate('Y-m-d\TH:i:s', intdiv($c->openTimeMs(), 1000)).sprintf('.%03dZ', $c->openTimeMs() % 1000),
            'open' => $c->open, 'high' => $c->high, 'low' => $c->low, 'close' => $c->close,
            'volume' => $c->volume, 'turnover' => $c->turnover, 'is_closed' => $c->isClosed,
            'raw_source_hash' => hash('sha256', json_encode($c, JSON_THROW_ON_ERROR)),
        ], array_slice($candles, -$limit));
    }

    public function fetchFundingHistory(string $symbol, ?int $startMs = null, ?int $endMs = null): array
    {
        throw new \LogicException('Bybit adapter funding normalization requires an approved parity fixture; use the existing Bybit client.');
    }

    public function fetchOpenInterest(string $symbol, string $interval, ?int $startMs = null, ?int $endMs = null): array
    {
        throw new \LogicException('Bybit adapter OI normalization requires an approved parity fixture; use the existing Bybit client.');
    }

    public function fetchSymbols(): array
    {
        // Requires paginated instrument listing; no fabricated completeness.
        throw new \LogicException('Complete Bybit symbol listing is not implemented in the legacy client.');
    }
}
