<?php

declare(strict_types=1);

// Dependency-free safety regression for extracted source archives that do not
// contain Composer vendor/. Never makes network requests or opens a database.

namespace App\Exchanges\Contracts {
    interface ExtendedExchangeAdapter {}
}

namespace App\Exchanges\Infrastructure {
    abstract class AbstractExchangeAdapter
    {
        protected string $baseUrl;

        protected int $timeoutSeconds = 8;

        protected int $maxRetries = 3;

        protected bool $pinValidatedAddress = false;

        protected function get(string $path, array $query = [], array $headers = []): array
        {
            throw new \RuntimeException('Offline safety test intercepted network request');
        }
    }
}

namespace App\HistoricalData {
    final class UniversalAcquisitionService
    {
        public static function normalizeInterval(string $interval): string
        {
            return match ($interval) {
                '5m' => '5', '15m' => '15', '1h' => '60', default => $interval,
            };
        }
    }
}

namespace {
    use App\Exchanges\Domain\CapabilityStatus;
    use App\Exchanges\Domain\ExchangeManifest;
    use App\Exchanges\Domain\MarketCapability;
    use App\Exchanges\Domain\MarketType;
    use App\Exchanges\Infrastructure\DeclarativeExchangeAdapter;
    use App\Security\ExchangeUrlSecurityValidator;

    $root = dirname(__DIR__, 2);
    require_once $root.'/app/Exchanges/Domain/CapabilityStatus.php';
    require_once $root.'/app/Exchanges/Domain/MarketCapability.php';
    require_once $root.'/app/Exchanges/Domain/MarketType.php';
    require_once $root.'/app/Exchanges/Domain/ExchangeManifest.php';
    require_once $root.'/app/Security/ExchangeUrlSecurityValidator.php';
    require_once $root.'/app/Exchanges/Infrastructure/DeclarativeExchangeAdapter.php';

    final class OfflineExchangeAdapter extends DeclarativeExchangeAdapter
    {
        public array $fake = [];

        public bool $fail = false;

        public array $requested = [];

        protected function get(string $path, array $query = [], array $headers = []): array
        {
            $this->requested[] = $path;
            if ($this->fail) {
                throw new RuntimeException('Simulated offline exchange');
            }

            return $this->fake[$path] ?? [];
        }
    }

    $passes = 0;
    function check(bool $condition, string $description): void
    {
        global $passes;
        if (! $condition) {
            throw new RuntimeException('FAILED: '.$description);
        }
        $passes++;
    }

    function throws(callable $callback, string $description): void
    {
        try {
            $callback();
        } catch (RuntimeException|InvalidArgumentException) {
            check(true, $description);

            return;
        }
        check(false, $description);
    }

    $adapter = new OfflineExchangeAdapter('testex', 'TestEx', 'https://8.8.8.8', [
        'market_type' => 'spot',
        'capabilities' => [
            'market_types' => ['spot'],
            'has_candles' => true,
            'endpoints' => ['ticker' => '/custom/ticker', 'instruments' => '/custom/instruments', 'candles' => '/custom/candles'],
        ],
    ]);

    $adapter->fail = true;
    throws(fn () => $adapter->fetchTicker('BTC-USD'), 'failed ticker does not generate artificial bid, ask or funding');
    throws(fn () => $adapter->fetchInstruments(MarketType::Spot), 'failed instrument discovery does not invent BTCUSDT');
    $adapter->fail = false;

    $adapter->fake['/custom/ticker'] = ['bid' => '100.01', 'ask' => '100.02'];
    $ticker = $adapter->fetchTicker('BTC-USD');
    check($ticker['bid'] === '100.01' && $ticker['ask'] === '100.02', 'actual bid/ask propagated');
    check($ticker['funding_rate'] === null, 'funding is unknown instead of manufactured');
    check(in_array('/custom/ticker', $adapter->requested, true), 'nested manifest endpoints honored');

    $adapter->fake['/custom/ticker'] = ['last' => '100'];
    throws(fn () => $adapter->fetchTicker('BTC-USD'), 'last trade alone is not executable bid/ask');

    $adapter->fake['/custom/instruments'] = ['data' => [['symbol' => 'BTC-USD', 'base_currency' => 'BTC', 'quote_currency' => 'USD']]];
    $instruments = $adapter->fetchInstruments(MarketType::Spot);
    check(count($instruments) === 1 && $instruments[0]['quote_asset'] === 'USD', 'native USD quote currency preserved');
    check($instruments[0]['max_order_qty'] === null && $instruments[0]['min_notional'] === null, 'missing instrument constraints not invented');
    $adapter->fake['/custom/instruments'] = ['data' => [['symbol' => 'BTC-X', 'base_currency' => 'BTC']]];
    check($adapter->fetchInstruments(MarketType::Spot)[0]['quote_asset'] === '', 'unknown quote currency is not assumed USDT');

    $adapter->fake['/custom/candles'] = ['data' => [
        ['open' => '1', 'high' => '2', 'low' => '1', 'close' => '2', 'volume' => '1'],
        ['open' => '1', 'high' => '2', 'low' => '1', 'close' => '2', 'volume' => '1', 'time' => 1700000000],
    ]];
    $candles = $adapter->fetchCandles('BTC-USD', '5m', 2);
    check(count($candles) === 1, 'missing candle timestamp skipped, not replaced with current time');

    $caps = $adapter->getMarketCapabilities(MarketType::Spot);
    check($caps[MarketCapability::Candles->value] === CapabilityStatus::Supported, 'declared candle endpoint supported');
    check($caps[MarketCapability::OrderBookL2->value] === CapabilityStatus::Unsupported, 'missing orderbook endpoint unsupported');
    check($caps[MarketCapability::FundingHistory->value] === CapabilityStatus::Unsupported, 'spot funding unsupported');
    check($adapter->supportedMarketTypes() === [MarketType::Spot], 'spot-only manifest not converted to perpetual');

    $noCosts = ExchangeManifest::fromArray(['id' => 'testex', 'name' => 'TestEx', 'market_type' => 'spot']);
    check($noCosts->costModel === [], 'exchange manifest without costs does not fabricate maker/taker/slippage');

    $validator = new ExchangeUrlSecurityValidator;
    foreach (['127.0.0.1', '10.2.3.4', '169.254.169.254', '100.64.0.10', '192.0.2.2', '198.18.0.1', '203.0.113.22', '::1', 'fd00::1', '2001:db8::1'] as $address) {
        check($validator->isPrivateOrReservedIp($address)['prohibited'], 'reserved address rejected: '.$address);
    }
    check(! $validator->isPrivateOrReservedIp('8.8.8.8')['prohibited'], 'public IPv4 not mistakenly blocked');
    check($validator->validateUrl('http://127.0.0.1')['safe'] === false, 'loopback URL rejected');
    check($validator->validateUrl('https://8.8.8.8')['safe'] === true, 'public URL accepted offline');

    echo "PASS: {$passes} native exchange safety assertions (no external dependencies or network calls).\n";
}
