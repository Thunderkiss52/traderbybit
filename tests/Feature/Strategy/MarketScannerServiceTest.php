<?php

declare(strict_types=1);

namespace Tests\Feature\Strategy;

use App\MarketData\Domain\Interval;
use App\Research\Infrastructure\Persistence\LearningFeatureSnapshotRecord;
use App\Strategy\Application\MarketScannerService;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MarketScannerServiceTest extends TestCase
{
    use RefreshDatabase;

    private DateTimeImmutable $at;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at = new DateTimeImmutable('2026-10-13T10:15:00Z');
    }

    public function test_scanner_evaluates_all_registered_strategies_across_both_symbols_and_is_idempotent(): void
    {
        $this->seedHistory('BTCUSDT');
        $this->seedHistory('ETHUSDT');
        $this->seedContext('BTCUSDT');
        $this->seedContext('ETHUSDT');

        $scanner = $this->app->make(MarketScannerService::class);

        // Run cycle 1
        $result1 = $scanner->scan($this->at, ['BTCUSDT', 'ETHUSDT']);

        self::assertSame(2, count($result1['symbols']));
        self::assertSame(13, $result1['strategies_per_symbol']);
        self::assertSame(26, $result1['total_evaluations']);

        // 26 signals (13 per symbol) and 26 strategy observations
        $this->assertDatabaseCount('signals', 26);
        $this->assertDatabaseCount('strategy_observations', 26);

        // Feature store snapshots assembled for both symbols
        self::assertGreaterThanOrEqual(2, LearningFeatureSnapshotRecord::query()->count());

        // Run cycle 2 at same timestamp: must be idempotent and non-duplicating
        $result2 = $scanner->scan($this->at, ['BTCUSDT', 'ETHUSDT']);

        $this->assertDatabaseCount('signals', 26);
        $this->assertDatabaseCount('strategy_observations', 26);
    }

    private function seedHistory(string $symbol): void
    {
        $spec = [
            [Interval::FiveMinutes, new DateTimeImmutable('2026-10-13T10:10:00Z')],
            [Interval::FifteenMinutes, new DateTimeImmutable('2026-10-13T10:00:00Z')],
            [Interval::ThirtyMinutes, new DateTimeImmutable('2026-10-13T09:30:00Z')],
            [Interval::OneHour, new DateTimeImmutable('2026-10-13T09:00:00Z')],
        ];

        $batch = [];
        foreach ($spec as [$interval, $lastOpen]) {
            for ($index = 219; $index >= 0; $index--) {
                $open = $lastOpen->modify('-'.($index * intdiv($interval->milliseconds(), 1000)).' seconds');
                $price = (string) (100 + (220 - $index) * 0.1);
                $batch[] = [
                    'exchange' => 'bybit',
                    'category' => 'linear',
                    'symbol' => $symbol,
                    'interval' => $interval->value,
                    'open_time' => $open,
                    'open' => $price,
                    'high' => (string) ((float) $price + 1),
                    'low' => (string) ((float) $price - 1),
                    'close' => $price,
                    'volume' => '100',
                    'turnover' => '200000',
                    'is_closed' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (count($batch) >= 100) {
                    DB::table('market_candles')->insert($batch);
                    $batch = [];
                }
            }
        }
        if (! empty($batch)) {
            DB::table('market_candles')->insert($batch);
        }
    }

    private function seedContext(string $symbol): void
    {
        MarketContextRecord::query()->create([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => $symbol,
            'observed_at' => $this->at,
            'bid' => '100.9',
            'ask' => '101.0',
            'turnover_24h' => '100000000',
            'tick_size' => '0.1',
            'funding_rate' => '0.0001',
            'open_interest' => '1000000',
            'qty_step' => '0.001',
            'min_order_qty' => '0.001',
            'max_order_qty' => '1000',
            'min_notional_value' => '5',
        ]);
    }
}
