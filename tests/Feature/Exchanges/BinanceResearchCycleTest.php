<?php

declare(strict_types=1);

namespace Tests\Feature\Exchanges;

use App\Exchanges\Binance\BinanceResearchCycle;
use App\MarketData\Domain\Interval;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use Carbon\Carbon;
use DateTimeImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

final class BinanceResearchCycleTest extends TestCase
{
    use RefreshDatabase;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('market_data.symbols', ['BTCUSDT', 'ETHUSDT']);
        config()->set('exchanges.binance.research_cycle_enabled', true);
        $this->now = new DateTimeImmutable('2026-10-25T12:16:30Z');
        Carbon::setTestNow($this->now);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    public function test_unconfigured_binance_symbol_throws_domain_exception(): void
    {
        $cycle = app(BinanceResearchCycle::class);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Unconfigured Binance market symbol.');

        $cycle->run('DOGEUSDT');
    }

    public function test_missing_closed_decision_candle_returns_operational_blocker(): void
    {
        $cycle = app(BinanceResearchCycle::class);

        $result = $cycle->run('BTCUSDT');

        self::assertSame('binance', $result['exchange_id']);
        self::assertSame('BTCUSDT', $result['symbol']);
        self::assertSame('NO_CLOSED_DECISION_CANDLE', $result['status']);
        self::assertSame(0, $result['signals']);
        self::assertSame(0, $result['processed']);
    }

    public function test_stale_decision_candle_returns_operational_blocker(): void
    {
        // Candle closed at 10:00, but test now is 12:16 (> 300 seconds stale)
        $this->seedBinanceCandle(Interval::FifteenMinutes, 'BTCUSDT', new DateTimeImmutable('2026-10-25T09:45:00Z'), true, '67000');

        $cycle = app(BinanceResearchCycle::class);
        $result = $cycle->run('BTCUSDT');

        self::assertSame('binance', $result['exchange_id']);
        self::assertSame('BTCUSDT', $result['symbol']);
        self::assertSame('STALE_OR_FUTURE_DECISION_CANDLE', $result['status']);
        self::assertSame(0, $result['signals']);
    }

    public function test_future_decision_candle_returns_operational_blocker(): void
    {
        // Candle closing in the future relative to test now (12:30 > 12:16)
        $this->seedBinanceCandle(Interval::FifteenMinutes, 'BTCUSDT', new DateTimeImmutable('2026-10-25T12:15:00Z'), true, '67000');

        $cycle = app(BinanceResearchCycle::class);
        $result = $cycle->run('BTCUSDT');

        self::assertSame('binance', $result['exchange_id']);
        self::assertSame('BTCUSDT', $result['symbol']);
        self::assertSame('STALE_OR_FUTURE_DECISION_CANDLE', $result['status']);
        self::assertSame(0, $result['signals']);
    }

    public function test_cycle_evaluates_all_thirteen_binance_strategies_and_persists_observations(): void
    {
        // Test now is 12:16:30Z. Decision time is 12:15:00Z (within 300s window).
        $this->seedBinanceHistory('BTCUSDT', new DateTimeImmutable('2026-10-25T12:00:00Z'));
        $this->seedBinanceHistory('ETHUSDT', new DateTimeImmutable('2026-10-25T12:00:00Z'));
        $this->seedBinanceContext('BTCUSDT', '2026-10-25T12:15:00Z');
        $this->seedBinanceContext('ETHUSDT', '2026-10-25T12:15:00Z');

        $cycle = app(BinanceResearchCycle::class);
        $result = $cycle->run('BTCUSDT');

        self::assertSame('binance', $result['exchange_id']);
        self::assertSame('BTCUSDT', $result['symbol']);
        self::assertSame('SIGNAL_RESEARCH_ONLY', $result['status']);
        self::assertSame(13, $result['signals']);
        self::assertCount(13, $result['strategy_signals']);

        // Assert 13 Binance observations were persisted
        $observations = StrategyObservationRecord::query()
            ->where('exchange', 'binance')
            ->where('symbol', 'BTCUSDT')
            ->orderBy('strategy_id')
            ->get();

        self::assertCount(13, $observations);

        $primaryStrategies = (array) config('paper.primary_strategies', []);
        sort($primaryStrategies);
        self::assertSame($primaryStrategies, $observations->pluck('strategy_id')->all());

        foreach ($observations as $obs) {
            self::assertSame('binance', $obs->exchange);
            self::assertSame('linear', $obs->category);
            self::assertSame('BTCUSDT', $obs->symbol);
            self::assertNotNull($obs->strategy_version);
            self::assertNotNull($obs->configuration_hash);
            self::assertContains($obs->side, ['BUY', 'SELL', 'SKIP']);
            self::assertIsArray($obs->reasons);
            self::assertIsArray($obs->analysis);
            self::assertArrayHasKey('diagnostics', $obs->analysis);
            self::assertArrayHasKey('decision', $obs->analysis['diagnostics']);
            self::assertArrayHasKey('paper_execution', $obs->analysis['diagnostics']);
        }

        // Assert 13 signals recorded in signals table with exchange = 'binance'
        self::assertSame(13, SignalRecord::query()->where('exchange', 'binance')->where('symbol', 'BTCUSDT')->count());
    }

    public function test_cycle_is_slot_idempotent_on_repeat_invocation(): void
    {
        $this->seedBinanceHistory('BTCUSDT', new DateTimeImmutable('2026-10-25T12:00:00Z'));
        $this->seedBinanceHistory('ETHUSDT', new DateTimeImmutable('2026-10-25T12:00:00Z'));
        $this->seedBinanceContext('BTCUSDT', '2026-10-25T12:15:00Z');
        $this->seedBinanceContext('ETHUSDT', '2026-10-25T12:15:00Z');

        $cycle = app(BinanceResearchCycle::class);

        $first = $cycle->run('BTCUSDT');
        self::assertSame(13, $first['signals']);
        self::assertSame(13, StrategyObservationRecord::query()->where('exchange', 'binance')->count());
        self::assertSame(13, SignalRecord::query()->where('exchange', 'binance')->count());

        // Repeat invocation on the exact same decision slot
        $second = $cycle->run('BTCUSDT');
        self::assertSame(13, $second['signals']);
        self::assertSame(13, StrategyObservationRecord::query()->where('exchange', 'binance')->count());
        self::assertSame(13, SignalRecord::query()->where('exchange', 'binance')->count());
    }

    public function test_paper_execution_without_explicit_approval_fails_closed(): void
    {
        $this->seedBinanceHistory('BTCUSDT', new DateTimeImmutable('2026-10-25T12:00:00Z'));
        $this->seedBinanceContext('BTCUSDT', '2026-10-25T12:15:00Z');

        config()->set('exchanges.binance.paper_execution_approved', false);

        $cycle = app(BinanceResearchCycle::class);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Binance PAPER execution is disabled.');

        $cycle->run('BTCUSDT', execute: true);
    }

    public function test_binance_scheduler_registers_all_expected_research_tasks(): void
    {
        config()->set('exchanges.binance.research_cycle_enabled', true);
        $events = collect(Schedule::events());

        foreach (['BTCUSDT', 'ETHUSDT'] as $symbol) {
            $context = $events->first(fn ($e): bool => $e->getSummaryForDisplay() === "binance:context:{$symbol}");
            $m5 = $events->first(fn ($e): bool => $e->getSummaryForDisplay() === "binance:5m:{$symbol}");
            $funding = $events->first(fn ($e): bool => $e->getSummaryForDisplay() === "binance:funding:{$symbol}");
            $signals = $events->first(fn ($e): bool => $e->getSummaryForDisplay() === "binance:signals:{$symbol}");

            self::assertNotNull($context, "binance:context:{$symbol} scheduled");
            self::assertNotNull($m5, "binance:5m:{$symbol} scheduled");
            foreach (['15m', '30m', '1h', '4h'] as $interval) {
                $htf = $events->first(fn ($e): bool => $e->getSummaryForDisplay() === "binance:htf:{$symbol}:{$interval}");
                self::assertNotNull($htf, "binance:htf:{$symbol}:{$interval} scheduled");
                self::assertSame('1,16,31,46 * * * *', $htf->expression);
            }
            self::assertNotNull($funding, "binance:funding:{$symbol} scheduled");
            self::assertNotNull($signals, "binance:signals:{$symbol} scheduled");

            self::assertSame('13,28,43,58 * * * *', $context->expression);
            self::assertSame('1,6,11,16,21,26,31,36,41,46,51,56 * * * *', $m5->expression);
            self::assertSame('10 * * * *', $funding->expression);
            self::assertSame('2,17,32,47 * * * *', $signals->expression);
        }
    }

    private function seedBinanceHistory(string $symbol, DateTimeImmutable $decisionCandleOpen): void
    {
        $spec = [
            [Interval::FiveMinutes, $decisionCandleOpen->modify('+10 minutes')],
            [Interval::FifteenMinutes, $decisionCandleOpen],
            [Interval::ThirtyMinutes, $decisionCandleOpen->modify('-15 minutes')],
            [Interval::OneHour, $decisionCandleOpen->modify('-45 minutes')],
        ];

        foreach ($spec as [$interval, $lastOpen]) {
            for ($index = 219; $index >= 0; $index--) {
                $open = $lastOpen->modify('-'.($index * intdiv($interval->milliseconds(), 1000)).' seconds');
                $price = (string) (60000 + (220 - $index) * 2);
                $this->seedBinanceCandle($interval, $symbol, $open, true, $price);
            }
        }
    }

    private function seedBinanceCandle(Interval $interval, string $symbol, DateTimeImmutable $open, bool $closed, string $price): void
    {
        DB::table('market_candles')->insert([
            'exchange' => 'binance',
            'category' => 'linear',
            'symbol' => $symbol,
            'interval' => $interval->value,
            'open_time' => $open,
            'open' => $price,
            'high' => (string) ((float) $price + 10),
            'low' => (string) ((float) $price - 10),
            'close' => $price,
            'volume' => '150',
            'turnover' => '9000000',
            'is_closed' => $closed,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedBinanceContext(string $symbol, string $observedAt): void
    {
        MarketContextRecord::query()->create([
            'exchange' => 'binance',
            'category' => 'linear',
            'symbol' => $symbol,
            'observed_at' => new DateTimeImmutable($observedAt),
            'bid' => '67000',
            'ask' => '67001',
            'turnover_24h' => '500000000',
            'tick_size' => '0.1',
            'funding_rate' => '0.0001',
            'open_interest' => '2500000',
            'open_interest_change' => '0.02',
        ]);
    }
}
