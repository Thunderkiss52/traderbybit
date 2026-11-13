<?php

declare(strict_types=1);

namespace App\Strategy\Application;

use App\Exchanges\Contracts\ExtendedExchangeAdapter;
use App\Exchanges\Domain\MarketType;
use App\Exchanges\ExchangeRegistry;
use App\Strategy\Contracts\MarketContextRepository;
use App\Strategy\Domain\MarketContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;
use RuntimeException;
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
            // Reject mislabeled adapter output before it enters risk decisions.
            // A different exchange, symbol or source snapshot is not an
            // acceptable substitute for the requested trading context.
            foreach ([$ticker, $orderbook] as $source) {
                if (! is_array($source)
                    || (isset($source['exchange_id']) && $source['exchange_id'] !== $exchange)
                    || (isset($source['symbol']) && $source['symbol'] !== $symbol)) {
                    throw new RuntimeException("Exchange market-context source identity mismatch for {$exchange}:{$symbol}.");
                }
            }

            // Never treat the last traded price as a bid/ask spread or invent
            // 100M of daily liquidity. Missing execution context must fail closed.
            $bid = $orderbook['bids'][0][0] ?? ($ticker['bid'] ?? ($ticker['bid_price'] ?? null));
            $ask = $orderbook['asks'][0][0] ?? ($ticker['ask'] ?? ($ticker['ask_price'] ?? null));
            if (! is_numeric($bid) || ! is_numeric($ask)
                || BigDecimal::of((string) $bid)->isLessThanOrEqualTo(0)
                || BigDecimal::of((string) $ask)->isLessThan(BigDecimal::of((string) $bid))) {
                throw new RuntimeException("No validated bid/ask for {$exchange}:{$symbol}.");
            }

            $turnover24h = $ticker['turnover_24h'] ?? ($ticker['turnover24h'] ?? ($ticker['quote_volume_24h'] ?? null));
            if (! is_numeric($turnover24h) || BigDecimal::of((string) $turnover24h)->isLessThanOrEqualTo(0)) {
                throw new RuntimeException("No verified quote-currency turnover for {$exchange}:{$symbol}.");
            }
            $bid = (string) $bid;
            $ask = (string) $ask;
            $turnover24h = (string) $turnover24h;
            $fundingRate = isset($ticker['funding_rate']) ? (string) $ticker['funding_rate'] : null;
            $openInterest = isset($ticker['open_interest']) ? (string) $ticker['open_interest'] : null;

            // Execution constraints must come from actual exchange responses.
            // Never invent a tick size, lot step, maximum size or notional floor.
            $spec = null;
            if ($adapter instanceof ExtendedExchangeAdapter) {
                $market = $category === 'spot' ? MarketType::Spot : MarketType::Perpetual;
                foreach ($adapter->fetchInstruments($market) as $instrument) {
                    if (($instrument['symbol'] ?? null) === $symbol
                        || ($instrument['canonical_symbol'] ?? null) === $symbol) {
                        $spec = $instrument;
                        break;
                    }
                }
            }
            $tickSize = $ticker['tick_size'] ?? ($spec['tick_size'] ?? null);
            $qtyStep = $ticker['qty_step'] ?? ($spec['lot_size'] ?? ($spec['qty_step'] ?? null));
            $minOrderQty = $ticker['min_qty'] ?? ($spec['min_order_qty'] ?? null);
            $maxOrderQty = $ticker['max_qty'] ?? ($spec['max_order_qty'] ?? null);
            $minNotional = $ticker['min_notional'] ?? ($spec['min_notional'] ?? null);
            foreach (compact('tickSize', 'qtyStep', 'minOrderQty', 'maxOrderQty', 'minNotional') as $field => $value) {
                if (! is_scalar($value) || ! is_numeric((string) $value)
                    || BigDecimal::of((string) $value)->isLessThanOrEqualTo(0)) {
                    throw new RuntimeException("Exchange execution constraint {$field} is unavailable for {$exchange}:{$symbol}.");
                }
            }
            if (BigDecimal::of((string) $minOrderQty)->isGreaterThan(BigDecimal::of((string) $maxOrderQty))) {
                throw new RuntimeException("Exchange minimum quantity exceeds maximum for {$exchange}:{$symbol}.");
            }
            $tickSize = (string) $tickSize;
            $qtyStep = (string) $qtyStep;
            $minOrderQty = (string) $minOrderQty;
            $maxOrderQty = (string) $maxOrderQty;
            $minNotional = (string) $minNotional;

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
