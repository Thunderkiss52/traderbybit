<?php

declare(strict_types=1);

namespace App\Exchanges\Binance;

use App\Strategy\Contracts\MarketContextRepository;
use App\Strategy\Domain\MarketContext;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

/** Record public Binance quotes BEFORE signal decision timestamps. */
final readonly class BinanceContextCapture
{
    public function __construct(private BinancePublicAdapter $adapter, private MarketContextRepository $contexts) {}

    public function capture(string $symbol): array
    {
        if (! in_array($symbol, (array) config('market_data.symbols', []), true)) {
            throw new DomainException('Unconfigured Binance market symbol.');
        }
        $ctx = $this->adapter->fetchContext($symbol);
        $openInterest = null;
        $openInterestChange = null;
        $oiStatus = 'DATA_UNAVAILABLE';
        $oiSourceTimestamp = null;
        $oiSourceHash = null;
        try {
            $rows = $this->adapter->fetchOpenInterest($symbol, '5m');
            // Time is captured AFTER the public responses are received. This is
            // the first moment the source observations were known locally.
            $at = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $oi = BinanceOpenInterestWindow::derive($rows, $symbol, $at,
                (int) config('exchanges.binance.open_interest_max_lag_seconds', 900));
            $openInterest = $oi['open_interest'];
            $openInterestChange = $oi['open_interest_change'];
            $oiStatus = $oi['status'];
            $oiSourceTimestamp = $oi['source_timestamp_utc'];
            $oiSourceHash = $oi['raw_source_hash'];
        } catch (ConnectionException|RuntimeException|DomainException) {
            $oiStatus = 'SOURCE_FETCH_OR_VALIDATION_FAILED';
            // An unavailable public OI API does not justify fabricating a
            // number or blocking independent quote-based research strategies.
            $at = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }
        $this->contexts->store('binance', 'linear', $symbol, new MarketContext(
            $at, $ctx['bid'], $ctx['ask'], $ctx['turnover_24h'], $ctx['tick_size'],
            $ctx['funding_rate'], $openInterest, $openInterestChange,
            $ctx['qty_step'], $ctx['min_order_qty'], $ctx['max_order_qty'], $ctx['min_notional_value'],
        ));

        return ['exchange_id' => 'binance', 'symbol' => $symbol, 'observed_at_utc' => $at->format(DATE_ATOM),
            'open_interest_status' => $oiStatus, 'oi_source_timestamp_utc' => $oiSourceTimestamp,
            'oi_source_hash' => $oiSourceHash];
    }
}
