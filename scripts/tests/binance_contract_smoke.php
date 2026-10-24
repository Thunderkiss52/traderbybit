<?php

declare(strict_types=1);

require_once __DIR__.'/../../app/Exchanges/Contracts/ExchangeAdapter.php';
require_once __DIR__.'/../../app/Exchanges/Binance/BinancePublicAdapter.php';
require_once __DIR__.'/../../app/Exchanges/Binance/BinanceCandleWindow.php';
require_once __DIR__.'/../../app/Exchanges/Binance/BinanceSourceIdentity.php';

use App\Exchanges\Binance\BinanceCandleWindow;
use App\Exchanges\Binance\BinancePublicAdapter;
use App\Exchanges\Binance\BinanceSourceIdentity;

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) {
        throw new RuntimeException('FAILED: '.$message);
    }
    $checks++;
};

$open = (int) (intdiv(time() - 900, 300) * 300) * 1000;
$row = [$open, '100.00000000', '105.00000000', '99.00000000', '102.12345678', '1000.00000000', $open + 299999, '101234.56780000'];
$result = BinancePublicAdapter::normalizeCandles([$row], 'BTCUSDT', '5m');
$check(count($result) === 1, 'one normalized candle');
$check($result[0]['exchange_id'] === 'binance', 'Binance provenance');
$check($result[0]['symbol'] === 'BTCUSDT', 'symbol identity');
$check($result[0]['interval'] === '5m', 'interval identity');
$check($result[0]['close'] === '102.12345678', 'exact decimal preserved');
$check($result[0]['is_closed'] === true, 'historically closed candle');
$check(strlen($result[0]['raw_source_hash']) === 64, 'source hash');
$check(str_contains($result[0]['open_time_utc'], 'T'), 'UTC timestamp');
$invalid = $row;
$invalid[4] = 'NaN';
try {
    BinancePublicAdapter::normalizeCandles([$invalid], 'BTCUSDT', '5m');
    throw new RuntimeException('Malformed numeric input incorrectly accepted.');
} catch (RuntimeException $e) {
    $check(str_contains($e->getMessage(), 'Malformed decimal'), 'invalid price rejected');
}
$invalid = $row;
$invalid[6] = $open;
try {
    BinancePublicAdapter::normalizeCandles([$invalid], 'BTCUSDT', '5m');
    throw new RuntimeException('Invalid candle clock incorrectly accepted.');
} catch (RuntimeException $e) {
    $check(str_contains($e->getMessage(), 'Invalid Binance candle interval alignment'), 'invalid time rejected');
}

// Negative controls: malformed public data must be rejected BEFORE persistence
// or any downstream indicator, risk, or PAPER execution calculations.
foreach ([
    'inverted high/low' => [2 => '98', 3 => '106'],
    'open above high' => [1 => '106'],
    'close below low' => [4 => '98'],
    'nonpositive price' => [1 => '0'],
    'negative volume' => [5 => '-1'],
    'negative turnover' => [7 => '-1'],
] as $label => $changes) {
    $bad = array_replace($row, $changes);
    try {
        BinancePublicAdapter::normalizeCandles([$bad], 'BTCUSDT', '5m');
        throw new RuntimeException("{$label}: invalid Binance market data accepted.");
    } catch (RuntimeException $e) {
        $check(str_contains($e->getMessage(), 'Invalid Binance candle'), "{$label} rejected");
    }
}
try {
    BinancePublicAdapter::normalizeCandles([$row, $row], 'BTCUSDT', '5m');
    throw new RuntimeException('Duplicate Binance candle accepted.');
} catch (RuntimeException $e) {
    $check(str_contains($e->getMessage(), 'duplicated or out of order'), 'duplicate candle rejected');
}
// Fixed-duration Binance candles must be exactly UTC-aligned and include
// the full timeframe, not merely monotonic opening timestamps.
foreach ([
    'misaligned opening' => [0 => $open + 60000, 6 => $open + 359999],
    'shortened 5m candle' => [6 => $open + 60000 - 1],
    'wrong 15m close' => [6 => $open + 900000 - 1],
] as $label => $changes) {
    try {
        BinancePublicAdapter::normalizeCandles([array_replace($row, $changes)], 'BTCUSDT', '5m');
        throw new RuntimeException("{$label}: invalid Binance candle was accepted.");
    } catch (RuntimeException $e) {
        $check(str_contains($e->getMessage(), 'candle interval alignment'), "{$label} rejected");
    }
}
// The comparison must preserve precision beyond PHP float/double mantissa.
$precise = $row;
$precise[1] = '99999999999999999999.000000000000000001';
$precise[2] = '99999999999999999999.000000000000000002';
$precise[3] = '99999999999999999999.000000000000000000';
$precise[4] = '99999999999999999999.000000000000000001';
$check(count(BinancePublicAdapter::normalizeCandles([$precise], 'BTCUSDT', '5m')) === 1, 'high precision OHLC accepted');
$precise[2] = '99999999999999999999.000000000000000000';
try {
    BinancePublicAdapter::normalizeCandles([$precise], 'BTCUSDT', '5m');
    throw new RuntimeException('High precision invalid high accepted.');
} catch (RuntimeException $e) {
    $check(str_contains($e->getMessage(), 'Invalid Binance candle OHLC'), 'high precision ordering protected');
}
if (! function_exists('env')) {
    function env(string $name, mixed $default = null): mixed
    {
        return $default;
    }
}
$families = require __DIR__.'/../../config/paper.php';
$check(count($families['primary_strategies']) === 13, '13 strategy families');
$check(count(array_unique($families['primary_strategies'])) === 13, 'unique strategy identities');
// Binance public quotes must be exchange-scoped, uncrossed, strictly positive
// and sourced from an eligible instrument; this is independent of Laravel.
$metadata = [
    'exchange_id' => 'binance', 'symbol' => 'BTCUSDT', 'is_active' => true,
    'tick_size' => '0.01000000', 'lot_size' => '0.00100000',
    'min_order_qty' => '0.00100000', 'max_order_qty' => '1000',
    'min_notional' => '5.00000000',
];
$book = ['symbol' => 'BTCUSDT', 'bidPrice' => '30000.00000001', 'askPrice' => '30000.00000002',
    'bidQty' => '1', 'askQty' => '2'];
$ticker = ['symbol' => 'BTCUSDT', 'quoteVolume' => '123456789.01'];
$premium = ['symbol' => 'BTCUSDT', 'lastFundingRate' => '-0.0001'];
$quote = BinancePublicAdapter::normalizeContext('BTCUSDT', $book, $ticker, $premium, $metadata);
$check($quote['bid'] === '30000.00000001', 'exact bid preserved');
$check($quote['funding_rate'] === '-0.0001', 'signed funding preserved');
$check($quote['min_notional_value'] === '5.00000000', 'min notional preserved');

$badQuotes = [
    'crossed book' => [array_replace($book, ['askPrice' => '29999']), $ticker, $premium, $metadata],
    'negative bid' => [array_replace($book, ['bidPrice' => '-1']), $ticker, $premium, $metadata],
    'empty ask side' => [array_replace($book, ['askQty' => '0']), $ticker, $premium, $metadata],
    'negative turnover' => [$book, array_replace($ticker, ['quoteVolume' => '-100']), $premium, $metadata],
    'wrong quote symbol' => [array_replace($book, ['symbol' => 'ETHUSDT']), $ticker, $premium, $metadata],
    'wrong metadata exchange' => [$book, $ticker, $premium, array_replace($metadata, ['exchange_id' => 'bybit'])],
    'inactive contract' => [$book, $ticker, $premium, array_replace($metadata, ['is_active' => false])],
    'zero tick size' => [$book, $ticker, $premium, array_replace($metadata, ['tick_size' => '0'])],
    'zero lot size' => [$book, $ticker, $premium, array_replace($metadata, ['lot_size' => '0'])],
    'negative min notional' => [$book, $ticker, $premium, array_replace($metadata, ['min_notional' => '-1'])],
    'min above max quantity' => [$book, $ticker, $premium, array_replace($metadata, ['min_order_qty' => '1001'])],
];
foreach ($badQuotes as $label => [$badBook, $badTicker, $badPremium, $badMetadata]) {
    try {
        BinancePublicAdapter::normalizeContext('BTCUSDT', $badBook, $badTicker, $badPremium, $badMetadata);
        throw new RuntimeException("{$label}: invalid Binance context accepted.");
    } catch (RuntimeException $e) {
        $check(! str_contains($e->getMessage(), 'invalid Binance context accepted'), "{$label} rejected");
    }
}

// Portable, dependency-free negative checks: count is not sufficient for
// PAPER history readiness. A contiguous, genuinely closed window is required.
$at = 1_800_000_000;
$window = array_map(static fn (int $i): int => $at - 300 - $i * 300, range(0, 219));
$check(BinanceCandleWindow::status($window, 300, $at) === 'READY', 'complete history gate');
$gap = $window;
$gap[10] -= 300;
$check(BinanceCandleWindow::status($gap, 300, $at) === 'CANDLE_HISTORY_GAPPED', 'history gap rejected');
$offGrid = array_map(static fn (int $t): int => $t + 60, $window);
$check(BinanceCandleWindow::status($offGrid, 300, $at) === 'CANDLE_HISTORY_MISALIGNED', 'off-grid history rejected');
$check(BinanceCandleWindow::status(array_slice($window, 0, 219), 300, $at) === 'CANDLE_HISTORY_INSUFFICIENT', 'short history rejected');
$future = array_map(static fn (int $v): int => $v + 300, $window);
$check(BinanceCandleWindow::status($future, 300, $at) === 'CANDLE_HISTORY_STALE_OR_FUTURE', 'unclosed candle rejected');
$stale = array_map(static fn (int $v): int => $v - 1200, $window);
$check(BinanceCandleWindow::status($stale, 300, $at) === 'CANDLE_HISTORY_STALE_OR_FUTURE', 'stale history rejected');
$persisted = (object) ['open' => '100.000000000000', 'high' => '105.000000000000',
    'low' => '99.000000000000', 'close' => '102.000000000000', 'volume' => '10.000000000000',
    'turnover' => '1010.000000000000', 'is_closed' => true];
BinanceSourceIdentity::assertSameCandle($persisted, [
    'open' => '100', 'high' => '105', 'low' => '99', 'close' => '102', 'volume' => '10', 'turnover' => '1010',
]);
$check(true, 'idempotent economic decimal replay permitted');
try {
    BinanceSourceIdentity::assertSameCandle($persisted, [
        'open' => '100', 'high' => '105', 'low' => '99', 'close' => '102.000000000001',
        'volume' => '10', 'turnover' => '1010',
    ]);
    throw new RuntimeException('Conflicting Binance candle accepted.');
} catch (DomainException $e) {
    $check(str_contains($e->getMessage(), 'Conflicting immutable'), 'conflicting immutable replay rejected');
}
try {
    BinanceSourceIdentity::assertPersistable(['mark' => '100.00000000000001']);
    throw new RuntimeException('Unrepresentable financial precision accepted.');
} catch (DomainException $e) {
    $check(str_contains($e->getMessage(), 'exact database decimal scale'), 'precision truncation rejected');
}
echo "PASS: {$checks} standalone Binance market-data and 13-primary assertions\n";
