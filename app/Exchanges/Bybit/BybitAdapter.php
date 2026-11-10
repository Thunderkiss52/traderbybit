<?php

declare(strict_types=1);

namespace App\Exchanges\Bybit;

use App\Exchanges\Contracts\ExtendedExchangeAdapter;
use App\Exchanges\Domain\CapabilityStatus;
use App\Exchanges\Domain\MarketCapability;
use App\Exchanges\Domain\MarketType;
use App\MarketData\Contracts\BybitMarketDataClient;
use App\MarketData\Domain\CandleQuery;
use App\MarketData\Domain\Interval;
use InvalidArgumentException;
use Throwable;

/** Read-only adapter delegating to the production Bybit public client. Strictly non-trading. */
final readonly class BybitAdapter implements ExtendedExchangeAdapter
{
    public function __construct(private BybitMarketDataClient $client) {}

    public function id(): string
    {
        return 'bybit';
    }

    public function name(): string
    {
        return 'Bybit Linear Perpetual';
    }

    public function fetchCandles(string $symbol, string $interval, int $limit, ?int $startMs = null, ?int $endMs = null): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Bybit candle adapter requires limit between 1 and 500.');
        }

        if ($endMs === null) {
            $endMs = (int) (microtime(true) * 1000);
        }
        if ($startMs === null) {
            $stepSeconds = match ($interval) {
                '1m', '1' => 60,
                '3m', '3' => 180,
                '5m', '5' => 300,
                '15m', '15' => 900,
                '30m', '30' => 1800,
                '1h', '60' => 3600,
                '4h', '240' => 14400,
                '1d', 'D' => 86400,
                default => 300,
            };
            $startMs = $endMs - ($limit * $stepSeconds * 1000);
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
        try {
            $rates = $this->client->fundingRates('linear', $symbol, $startMs, $endMs);

            return array_map(static fn (array $r): array => [
                'exchange_id' => 'bybit',
                'symbol' => $symbol,
                'funding_rate' => (string) ($r['fundingRate'] ?? '0'),
                'funding_time_utc' => gmdate('Y-m-d\TH:i:s.000\Z', intdiv((int) ($r['fundingRateTimestamp'] ?? 0), 1000)),
                'mark_price' => isset($r['markPrice']) ? (string) $r['markPrice'] : null,
            ], $rates);
        } catch (Throwable) {
            return [];
        }
    }

    public function fetchOpenInterest(string $symbol, string $interval, ?int $startMs = null, ?int $endMs = null): array
    {
        try {
            $oi = $this->client->openInterest('linear', $symbol, $interval);
            $rows = $oi['list'] ?? [];

            return array_map(static fn (array $r): array => [
                'exchange_id' => 'bybit',
                'symbol' => $symbol,
                'open_interest' => (string) ($r['openInterest'] ?? '0'),
                'timestamp_utc' => gmdate('Y-m-d\TH:i:s.000\Z', intdiv((int) ($r['timestamp'] ?? 0), 1000)),
                'open_interest_value_usdt' => null,
            ], $rows);
        } catch (Throwable) {
            return [];
        }
    }

    public function fetchSymbols(): array
    {
        return ['BTCUSDT', 'ETHUSDT'];
    }

    public function supportedMarketTypes(): array
    {
        return [MarketType::Perpetual, MarketType::Spot];
    }

    public function getMarketCapabilities(MarketType $marketType): array
    {
        if ($marketType === MarketType::Perpetual) {
            return [
                MarketCapability::Candles->value => CapabilityStatus::Supported,
                MarketCapability::OrderBookL2->value => CapabilityStatus::Supported,
                MarketCapability::Trades->value => CapabilityStatus::Supported,
                MarketCapability::Ticker->value => CapabilityStatus::Supported,
                MarketCapability::FundingHistory->value => CapabilityStatus::Supported,
                MarketCapability::OpenInterest->value => CapabilityStatus::Supported,
                MarketCapability::MarkPrice->value => CapabilityStatus::Supported,
                MarketCapability::Liquidations->value => CapabilityStatus::Supported,
                MarketCapability::OptionsChain->value => CapabilityStatus::Unsupported,
            ];
        }

        if ($marketType === MarketType::Spot) {
            return [
                MarketCapability::Candles->value => CapabilityStatus::Supported,
                MarketCapability::OrderBookL2->value => CapabilityStatus::Supported,
                MarketCapability::Trades->value => CapabilityStatus::Supported,
                MarketCapability::Ticker->value => CapabilityStatus::Supported,
                MarketCapability::FundingHistory->value => CapabilityStatus::Unsupported,
                MarketCapability::OpenInterest->value => CapabilityStatus::Unsupported,
                MarketCapability::MarkPrice->value => CapabilityStatus::Unsupported,
                MarketCapability::Liquidations->value => CapabilityStatus::Unsupported,
                MarketCapability::OptionsChain->value => CapabilityStatus::Unsupported,
            ];
        }

        return array_fill_keys(array_map(static fn ($c) => $c->value, MarketCapability::cases()), CapabilityStatus::Unsupported);
    }

    public function fetchInstruments(MarketType $marketType): array
    {
        $symbols = ['BTCUSDT', 'ETHUSDT'];

        return array_map(static function (string $sym) use ($marketType): array {
            $base = str_replace('USDT', '', $sym);

            return [
                'symbol' => $sym,
                'base_currency' => $base,
                'quote_currency' => 'USDT',
                'market_type' => $marketType->value,
                'status' => 'TRADING',
                'price_precision' => 2,
                'qty_precision' => $sym === 'BTCUSDT' ? 3 : 2,
                'min_qty' => $sym === 'BTCUSDT' ? '0.001' : '0.01',
                'min_notional' => '5.0',
            ];
        }, $symbols);
    }

    public function fetchOrderBook(string $symbol, int $depth = 20): array
    {
        $raw = $this->client->orderBook('linear', $symbol, min(50, max(1, $depth)));

        return [
            'exchange_id' => 'bybit',
            'symbol' => $symbol,
            'timestamp_utc' => gmdate('Y-m-d\TH:i:s.000\Z'),
            'bids' => array_map(static fn ($b): array => [(string) $b[0], (string) $b[1]], $raw['b'] ?? []),
            'asks' => array_map(static fn ($a): array => [(string) $a[0], (string) $a[1]], $raw['a'] ?? []),
            'raw_source_hash' => hash('sha256', json_encode($raw, JSON_THROW_ON_ERROR)),
        ];
    }

    public function fetchTicker(string $symbol): array
    {
        $t = $this->client->ticker('linear', $symbol);

        return [
            'exchange_id' => 'bybit',
            'symbol' => $symbol,
            'last_price' => (string) $t['lastPrice'],
            'bid_price' => isset($t['bid1Price']) ? (string) $t['bid1Price'] : null,
            'ask_price' => isset($t['ask1Price']) ? (string) $t['ask1Price'] : null,
            'volume_24h' => (string) $t['volume24h'],
            'turnover_24h' => isset($t['turnover24h']) ? (string) $t['turnover24h'] : null,
            'timestamp_utc' => gmdate('Y-m-d\TH:i:s.000\Z'),
            'raw_source_hash' => hash('sha256', json_encode($t, JSON_THROW_ON_ERROR)),
        ];
    }

    public function healthCheck(): array
    {
        $start = microtime(true);
        try {
            $this->client->ticker('linear', 'BTCUSDT');
            $latency = (int) round((microtime(true) - $start) * 1000);

            return [
                'status' => 'HEALTHY',
                'latency_ms' => $latency,
                'endpoint' => 'https://api.bybit.com',
                'timestamp_utc' => gmdate('Y-m-d\TH:i:s\Z'),
                'error' => null,
            ];
        } catch (Throwable $e) {
            $latency = (int) round((microtime(true) - $start) * 1000);

            return [
                'status' => 'DEGRADED',
                'latency_ms' => $latency,
                'endpoint' => 'https://api.bybit.com',
                'timestamp_utc' => gmdate('Y-m-d\TH:i:s\Z'),
                'error' => $e->getMessage(),
            ];
        }
    }
}
