<?php

declare(strict_types=1);

namespace App\Strategy\Application;

use App\Exchanges\Contracts\ExtendedExchangeAdapter;
use App\Exchanges\ExchangeRegistry;
use App\Strategy\Contracts\MarketContextRepository;
use App\Strategy\Domain\MarketContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Universal exchange-neutral market context collector.
 * Captures live bid/ask, orderbook depth, turnover, funding rate, and open interest
 * across any registered exchange adapter adhering to ExtendedExchangeAdapter.
 */
final class UniversalMarketContextCollector
{
    public function __construct(
        private readonly ExchangeRegistry $registry,
        private readonly MarketContextRepository $contexts,
    ) {}

    /**
     * Collect and persist a market context snapshot for an exchange and symbol.
     */
    public function collect(string $exchange, string $symbol, string $category = 'linear'): ?MarketContext
    {
        $exchange = strtolower(trim($exchange));
        $symbol = strtoupper(trim($symbol));

        try {
            $adapter = $this->registry->resolve($exchange);
        } catch (Throwable $e) {
            Log::warning("UniversalMarketContextCollector: Adapter not resolved for '{$exchange}': ".$e->getMessage());

            return null;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        try {
            // 1. Fetch Ticker
            $ticker = $adapter->fetchTicker($symbol);

            // 2. Fetch Orderbook Depth
            $orderbook = $adapter->fetchOrderBook($symbol, 5);

            $bid = (string) ($orderbook['bids'][0][0] ?? ($ticker['bid'] ?? ($ticker['last_price'] ?? '0')));
            $ask = (string) ($orderbook['asks'][0][0] ?? ($ticker['ask'] ?? ($ticker['last_price'] ?? '0')));

            if (BigDecimal::of($bid)->isZero() && isset($ticker['last_price'])) {
                $bid = (string) $ticker['last_price'];
                $ask = (string) $ticker['last_price'];
            }

            $turnover24h = (string) ($ticker['turnover24h'] ?? ($ticker['volume_24h'] ?? ($ticker['volume'] ?? '100000000')));
            $fundingRate = isset($ticker['funding_rate']) ? (string) $ticker['funding_rate'] : null;
            $openInterest = isset($ticker['open_interest']) ? (string) $ticker['open_interest'] : null;

            // Tick and Lot sizes (defaults safely based on crypto standard if not in ticker)
            $tickSize = (string) ($ticker['tick_size'] ?? '0.01');
            $qtyStep = (string) ($ticker['qty_step'] ?? '0.001');
            $minOrderQty = (string) ($ticker['min_qty'] ?? '0.001');
            $maxOrderQty = (string) ($ticker['max_qty'] ?? '1000.0');
            $minNotional = (string) ($ticker['min_notional'] ?? '5.0');

            // 3. Compute open interest change relative to previous point-in-time snapshot
            $previous = $this->contexts->latestAt($exchange, $category, $symbol, $now);
            $oiChange = null;
            if ($openInterest !== null && $previous?->openInterest !== null && ! BigDecimal::of($previous->openInterest)->isZero()) {
                $oiChange = (string) BigDecimal::of($openInterest)
                    ->minus($previous->openInterest)
                    ->dividedBy($previous->openInterest, 12, RoundingMode::HalfEven);
            }

            $context = new MarketContext(
                observedAt: $now,
                bid: $bid,
                ask: $ask,
                turnover24h: $turnover24h,
                tickSize: $tickSize,
                fundingRate: $fundingRate,
                openInterest: $openInterest,
                openInterestChange: $oiChange,
                qtyStep: $qtyStep,
                minOrderQty: $minOrderQty,
                maxOrderQty: $maxOrderQty,
                minNotionalValue: $minNotional,
            );

            $this->contexts->store($exchange, $category, $symbol, $context);

            return $context;
        } catch (Throwable $e) {
            Log::warning("UniversalMarketContextCollector failed for {$exchange}:{$symbol}: ".$e->getMessage());

            return null;
        }
    }
}
