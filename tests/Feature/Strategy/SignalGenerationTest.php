<?php

declare(strict_types=1);

namespace Tests\Feature\Strategy;

use App\MarketData\Domain\Interval;
use App\Strategy\Application\SignalGenerator;
use App\Strategy\Application\StrategyEngine;
use App\Strategy\Application\StrategyInputFactory;
use App\Strategy\Application\StrategyObservationService;
use App\Strategy\Infrastructure\Persistence\EloquentSignalRepository;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use App\Strategy\Infrastructure\Persistence\StrategyObservationRecord;
use App\Strategy\Jobs\GenerateSignalJob;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Strategy\StrategyFixture;
use Tests\TestCase;

final class SignalGenerationTest extends TestCase
{
    use RefreshDatabase;

    private DateTimeImmutable $at;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at = new DateTimeImmutable('2026-10-13T10:15:00Z');
    }

    public function test_repository_is_idempotent_and_never_rewrites_an_old_strategy_result(): void
    {
        $repository = new EloquentSignalRepository;
        $original = (new StrategyEngine(StrategyFixture::config()))->evaluate(StrategyFixture::input());
        $repository->createIfAbsent($original);

        $changed = StrategyFixture::config();
        $changed['model_risk_percent']['A'] = '9.99';
        $returned = $repository->createIfAbsent((new StrategyEngine($changed))->evaluate(StrategyFixture::input()));

        $this->assertDatabaseCount('signals', 1);
        self::assertDecimalSame('0.5', $returned->modelRiskPercent);
        self::assertDecimalSame('0.50', SignalRecord::query()->firstOrFail()->contract['model_risk_percent']);
    }

    public function test_time_alignment_excludes_future_context_and_open_candles(): void
    {
        $this->seedHistory();
        $this->seedContext('2026-10-13T10:16:00Z');
        $this->insertCandle(Interval::FifteenMinutes, new DateTimeImmutable('2026-10-13T10:15:00Z'), false, '999');

        $input = $this->app->make(StrategyInputFactory::class)->make('bybit', 'linear', 'BTCUSDT', $this->at);

        self::assertSame('2026-10-13T10:00:00+00:00', $input->trend->latest()?->closeTime->format(DATE_ATOM));
        self::assertSame('2026-10-13T10:00:00+00:00', $input->setup->latest()?->closeTime->format(DATE_ATOM));
        self::assertSame('2026-10-13T10:15:00+00:00', $input->entry->latest()?->closeTime->format(DATE_ATOM));
        self::assertSame('2026-10-13T10:15:00+00:00', $input->confirmation->latest()?->closeTime->format(DATE_ATOM));
        self::assertNotSame('999.000000000000', $input->entry->latest()?->close);
        self::assertNull($input->marketContext);
    }

    public function test_gap_is_detected_and_saved_as_skip(): void
    {
        $this->seedHistory(gapInEntry: true);
        $this->seedContext('2026-10-13T10:15:00Z');

        $decision = $this->app->make(SignalGenerator::class)->generate('BTCUSDT', $this->at);

        self::assertSame('SKIP', $decision->side->value);
        self::assertContains('non_contiguous_entry_history', $decision->reasons);
        $this->assertDatabaseCount('signals', 1);
    }

    public function test_command_and_queue_job_persist_one_result_per_strategy(): void
    {
        $this->seedHistory();
        $this->seedContext('2026-10-13T10:15:00Z');

        $this->artisan('signals:generate', ['--symbol' => 'BTCUSDT', '--at' => '2026-10-13T10:15:00Z'])
            ->expectsOutputToContain('"research_mode": true')
            ->assertSuccessful();
        (new GenerateSignalJob('BTCUSDT', '2026-10-13T10:15:00Z'))->handle(
            $this->app->make(SignalGenerator::class),
            $this->app->make(StrategyObservationService::class),
        );

        $this->assertDatabaseCount('signals', 13);
        self::assertSame(13, SignalRecord::query()->distinct()->count('strategy_id'));
        $this->assertDatabaseCount('strategy_observations', 13);
    }

    public function test_all_registered_strategies_are_observed_once_per_closed_candle(): void
    {
        $this->seedHistory();
        $this->seedContext('2026-10-13T10:15:00Z');
        $service = $this->app->make(StrategyObservationService::class);

        $observations = $service->observe('BTCUSDT', $this->at);
        $service->observe('BTCUSDT', $this->at);

        self::assertCount(13, $observations);
        self::assertSame([
            'baseline',
            'bollinger_mean_reversion',
            'btc_eth_lead_lag',
            'donchian_atr_breakout',
            'funding_open_interest_squeeze',
            'liquidity_sweep_reversal',
            'momentum',
            'multi_timeframe_trend_following',
            'regime_switching',
            'support_resistance_bounce',
            'support_resistance_breakout',
            'trend_pullback',
            'volatility_compression_breakout',
        ], StrategyObservationRecord::query()->orderBy('strategy_id')->pluck('strategy_id')->all());
        StrategyObservationRecord::query()->each(function (StrategyObservationRecord $observation): void {
            self::assertArrayHasKey('level_interaction', $observation->analysis);
            self::assertArrayHasKey('momentum_side', $observation->analysis['level_interaction']);
            self::assertArrayHasKey('indicator_snapshot', $observation->analysis);
            self::assertArrayHasKey('diagnostics', $observation->analysis);
            self::assertContains($observation->analysis['diagnostics']['paper_execution'], ['NOT_ATTEMPTED', 'PENDING_RISK']);
        });
        $this->assertDatabaseCount('signals', 13);
        $this->assertDatabaseCount('strategy_observations', 13);
        self::assertGreaterThan(0, DB::table('candlestick_pattern_occurrences')->count());
        $this->getJson('/api/v1/research/candlestick-patterns?symbol=BTCUSDT&per_page=10')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('data.0.symbol', 'BTCUSDT');
    }

    public function test_recent_observation_backfill_populates_all_strategies_without_duplicates(): void
    {
        $this->seedHistory();
        $this->seedContext('2026-10-13T10:15:00Z');
        $service = $this->app->make(StrategyObservationService::class);

        self::assertSame(2, $service->observeRecent('BTCUSDT', 1, 2));
        self::assertSame(2, $service->observeRecent('BTCUSDT', 1, 2));
        $this->assertDatabaseCount('strategy_observations', 52);
    }

    public function test_no_trading_endpoint_is_present_in_application_source(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        $source = '';
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $source .= file_get_contents($file->getPathname());
            }
        }
        self::assertStringNotContainsString('/v5/order/create', $source);
        self::assertStringNotContainsString('/v5/order/cancel', $source);
    }

    private function seedHistory(bool $gapInEntry = false): void
    {
        $spec = [
            [Interval::FiveMinutes, new DateTimeImmutable('2026-10-13T10:10:00Z')],
            [Interval::FifteenMinutes, new DateTimeImmutable('2026-10-13T10:00:00Z')],
            [Interval::ThirtyMinutes, new DateTimeImmutable('2026-10-13T09:30:00Z')],
            [Interval::OneHour, new DateTimeImmutable('2026-10-13T09:00:00Z')],
        ];
        foreach ($spec as [$interval, $lastOpen]) {
            for ($index = 219; $index >= 0; $index--) {
                if ($gapInEntry && $interval === Interval::FifteenMinutes && $index === 20) {
                    continue;
                }
                $open = $lastOpen->modify('-'.($index * intdiv($interval->milliseconds(), 1000)).' seconds');
                $price = (string) (100 + (220 - $index) * 0.1);
                $this->insertCandle($interval, $open, true, $price);
            }
        }
    }

    private function insertCandle(Interval $interval, DateTimeImmutable $open, bool $closed, string $price): void
    {
        DB::table('market_candles')->insert([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'interval' => $interval->value, 'open_time' => $open,
            'open' => $price, 'high' => (string) ((float) $price + 1), 'low' => (string) ((float) $price - 1), 'close' => $price,
            'volume' => '100', 'turnover' => '200000', 'is_closed' => $closed, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedContext(string $observedAt): void
    {
        MarketContextRecord::query()->create([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'observed_at' => new DateTimeImmutable($observedAt),
            'bid' => '121.9', 'ask' => '122', 'turnover_24h' => '100000000', 'tick_size' => '0.1',
            'funding_rate' => '0.0001', 'open_interest' => '1000000', 'open_interest_change' => '0.01',
        ]);
    }
}
