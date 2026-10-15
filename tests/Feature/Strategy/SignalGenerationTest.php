<?php

declare(strict_types=1);

namespace Tests\Feature\Strategy;

use App\MarketData\Domain\Interval;
use App\Strategy\Application\SignalGenerator;
use App\Strategy\Application\StrategyEngine;
use App\Strategy\Application\StrategyInputFactory;
use App\Strategy\Infrastructure\Persistence\EloquentSignalRepository;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
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
        self::assertSame('0.5', $returned->modelRiskPercent);
        self::assertSame('0.50', SignalRecord::query()->firstOrFail()->contract['model_risk_percent']);
    }

    public function test_time_alignment_excludes_future_context_and_open_candles(): void
    {
        $this->seedHistory();
        $this->seedContext('2026-10-13T10:16:00Z');
        $this->insertCandle(Interval::FifteenMinutes, new DateTimeImmutable('2026-10-13T10:15:00Z'), false, '999');

        $input = $this->app->make(StrategyInputFactory::class)->make('bybit', 'linear', 'BTCUSDT', $this->at);

        self::assertSame('2026-10-13T10:00:00+00:00', $input->trend->latest()?->closeTime->format(DATE_ATOM));
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

    public function test_command_and_queue_job_generate_the_same_single_result(): void
    {
        $this->seedHistory();
        $this->seedContext('2026-10-13T10:15:00Z');

        $this->artisan('signals:generate', ['--symbol' => 'BTCUSDT', '--at' => '2026-10-13T10:15:00Z'])
            ->expectsOutputToContain('"research_mode": true')
            ->assertSuccessful();
        (new GenerateSignalJob('BTCUSDT', '2026-10-13T10:15:00Z'))->handle($this->app->make(SignalGenerator::class));

        $this->assertDatabaseCount('signals', 1);
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
