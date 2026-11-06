<?php

declare(strict_types=1);

namespace Tests\Feature\Strategy;

use App\Exchanges\Binance\BinancePublicAdapter;
use App\MarketData\Domain\Interval;
use App\Strategy\Application\StrategyInputFactory;
use App\Strategy\Domain\StrategyInput;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use Carbon\Carbon;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class Rem01BinanceFreshnessAndAlignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('market_data.symbols', ['BTCUSDT', 'ETHUSDT']);
        config()->set('exchanges.binance.research_cycle_enabled', true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    /**
     * Test :00 boundary: 1h (60m) and 30m candles both close at :00:00.
     * Verify BTCUSDT and ETHUSDT independently have zero false stale classifications when fresh.
     */
    public function test_zero_minute_boundary_is_fresh_for_btcusdt_and_ethusdt(): void
    {
        $decisionTime = new DateTimeImmutable('2026-11-06T12:00:00Z');
        Carbon::setTestNow($decisionTime);

        foreach (['BTCUSDT', 'ETHUSDT'] as $symbol) {
            $this->seedFreshBinanceCandles($symbol, $decisionTime);
            $this->seedMarketContext($symbol, $decisionTime);

            $factory = app(StrategyInputFactory::class);
            $input = $factory->make('binance', 'linear', $symbol, $decisionTime, StrategyInputFactory::peerSymbol($symbol));

            self::assertInstanceOf(StrategyInput::class, $input);
            self::assertNotContains('stale_setup_data', $input->validationErrors, "{$symbol} should not have stale setup at :00");
            self::assertNotContains('stale_trend_data', $input->validationErrors, "{$symbol} should not have stale trend at :00");

            // Verify actionable diagnostics
            self::assertArrayHasKey('setup', $input->freshnessDiagnostics);
            self::assertArrayHasKey('trend', $input->freshnessDiagnostics);

            $setupDiag = $input->freshnessDiagnostics['setup'];
            self::assertSame('30', $setupDiag['interval']);
            self::assertSame('2026-11-06T12:00:00+00:00', $setupDiag['expected_close_time']);
            self::assertSame('2026-11-06T12:00:00+00:00', $setupDiag['actual_close_time']);
            self::assertSame(0, $setupDiag['lag_seconds']);
            self::assertSame('FRESH', $setupDiag['status']);

            $trendDiag = $input->freshnessDiagnostics['trend'];
            self::assertSame('60', $trendDiag['interval']);
            self::assertSame('2026-11-06T12:00:00+00:00', $trendDiag['expected_close_time']);
            self::assertSame('2026-11-06T12:00:00+00:00', $trendDiag['actual_close_time']);
            self::assertSame(0, $trendDiag['lag_seconds']);
            self::assertSame('FRESH', $trendDiag['status']);
        }
    }

    /**
     * Test :30 boundary: 30m candle closes at :30:00, while 1h candle has 30m lag (1800s <= 3599s max).
     * Verify BTCUSDT and ETHUSDT independently have zero false stale classifications when fresh.
     */
    public function test_thirty_minute_boundary_is_fresh_for_btcusdt_and_ethusdt(): void
    {
        $decisionTime = new DateTimeImmutable('2026-11-06T12:30:00Z');
        Carbon::setTestNow($decisionTime);

        foreach (['BTCUSDT', 'ETHUSDT'] as $symbol) {
            $this->seedFreshBinanceCandles($symbol, $decisionTime);
            $this->seedMarketContext($symbol, $decisionTime);

            $factory = app(StrategyInputFactory::class);
            $input = $factory->make('binance', 'linear', $symbol, $decisionTime, StrategyInputFactory::peerSymbol($symbol));

            self::assertInstanceOf(StrategyInput::class, $input);
            self::assertNotContains('stale_setup_data', $input->validationErrors, "{$symbol} should not have stale setup at :30");
            self::assertNotContains('stale_trend_data', $input->validationErrors, "{$symbol} should not have stale trend at :30");

            $setupDiag = $input->freshnessDiagnostics['setup'];
            self::assertSame('2026-11-06T12:30:00+00:00', $setupDiag['expected_close_time']);
            self::assertSame('2026-11-06T12:30:00+00:00', $setupDiag['actual_close_time']);
            self::assertSame(0, $setupDiag['lag_seconds']);
            self::assertSame('FRESH', $setupDiag['status']);

            $trendDiag = $input->freshnessDiagnostics['trend'];
            self::assertSame('2026-11-06T12:00:00+00:00', $trendDiag['expected_close_time']);
            self::assertSame('2026-11-06T12:00:00+00:00', $trendDiag['actual_close_time']);
            self::assertSame(1800, $trendDiag['lag_seconds']);
            self::assertSame('FRESH', $trendDiag['status']);
        }
    }

    /**
     * Genuine stale-data rejection: when 30m candle is missing for the current boundary,
     * it MUST be rejected with stale_setup_data and actionable diagnostics.
     */
    public function test_genuine_stale_setup_is_rejected_with_actionable_diagnostics(): void
    {
        $decisionTime = new DateTimeImmutable('2026-11-06T12:30:00Z');
        Carbon::setTestNow($decisionTime);

        // Seed candles where the 12:00-12:30 30m candle is omitted (last 30m candle closed at 12:00)
        $this->seedFreshBinanceCandles('BTCUSDT', $decisionTime, omitLatestSetup: true);
        $this->seedFreshBinanceCandles('ETHUSDT', $decisionTime);
        $this->seedMarketContext('BTCUSDT', $decisionTime);
        $this->seedMarketContext('ETHUSDT', $decisionTime);

        $factory = app(StrategyInputFactory::class);
        $input = $factory->make('binance', 'linear', 'BTCUSDT', $decisionTime, 'ETHUSDT');

        self::assertContains('stale_setup_data', $input->validationErrors);

        $setupDiag = $input->freshnessDiagnostics['setup'];
        self::assertSame('30', $setupDiag['interval']);
        self::assertSame('2026-11-06T12:30:00+00:00', $setupDiag['expected_close_time']);
        self::assertSame('2026-11-06T12:00:00+00:00', $setupDiag['actual_close_time']);
        self::assertSame(1800, $setupDiag['lag_seconds']);
        self::assertSame(1799, $setupDiag['max_lag_seconds']);
        self::assertSame('STALE', $setupDiag['status']);
    }

    private function seedFreshBinanceCandles(string $symbol, DateTimeImmutable $decisionTime, bool $omitLatestSetup = false): void
    {
        $open15m = $decisionTime->modify('-15 minutes');
        $open5m = $decisionTime->modify('-5 minutes');
        $open30m = $decisionTime->modify('-30 minutes');
        $open60m = $decisionTime->getTimestamp() % 3600 === 0
            ? $decisionTime->modify('-60 minutes')
            : (new DateTimeImmutable('@'.(((int) floor($decisionTime->getTimestamp() / 3600)) * 3600 - 3600)))->setTimezone(new \DateTimeZone('UTC'));

        // Seed 15m (entry)
        for ($i = 219; $i >= 0; $i--) {
            $open = $open15m->modify('-'.($i * 900).' seconds');
            $this->insertCandle('binance', $symbol, '15', $open, '68000');
        }

        // Seed 5m (confirmation)
        for ($i = 219; $i >= 0; $i--) {
            $open = $open5m->modify('-'.($i * 300).' seconds');
            $this->insertCandle('binance', $symbol, '5', $open, '68000');
        }

        // Seed 30m (setup)
        $limit30 = $omitLatestSetup ? 1 : 0;
        for ($i = 219; $i >= $limit30; $i--) {
            $open = $open30m->modify('-'.($i * 1800).' seconds');
            $this->insertCandle('binance', $symbol, '30', $open, '68000');
        }

        // Seed 60m (trend)
        for ($i = 219; $i >= 0; $i--) {
            $open = $open60m->modify('-'.($i * 3600).' seconds');
            $this->insertCandle('binance', $symbol, '60', $open, '68000');
        }
    }

    private function insertCandle(string $exchange, string $symbol, string $interval, DateTimeImmutable $open, string $price): void
    {
        DB::table('market_candles')->insertOrIgnore([
            'exchange' => $exchange,
            'category' => 'linear',
            'symbol' => $symbol,
            'interval' => $interval,
            'open_time' => $open,
            'open' => $price,
            'high' => (string) ((float) $price + 10),
            'low' => (string) ((float) $price - 10),
            'close' => $price,
            'volume' => '100',
            'turnover' => '6800000',
            'is_closed' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedMarketContext(string $symbol, DateTimeImmutable $observedAt): void
    {
        MarketContextRecord::query()->create([
            'exchange' => 'binance',
            'category' => 'linear',
            'symbol' => $symbol,
            'observed_at' => $observedAt,
            'bid' => '68000',
            'ask' => '68001',
            'turnover_24h' => '500000000',
            'tick_size' => '0.1',
            'funding_rate' => '0.0001',
            'open_interest' => '2500000',
            'open_interest_change' => '0.02',
        ]);
    }
}
