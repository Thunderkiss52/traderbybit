<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Infrastructure\Persistence\EquitySnapshotRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class StatisticsPayloadSerializationRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_corrupted_redis_cache_is_superseded_by_v5_and_does_not_crash(): void
    {
        $account = app(PaperAccountService::class)->default();

        // Simulate legacy corrupted cache entry under old key containing an incomplete class representation
        Cache::put('paper:statistics:response:v4:'.$account->id.':1:1:1', [
            'metrics' => null,
            'equity' => (object) ['__PHP_Incomplete_Class_Name' => 'Illuminate\\Support\\Collection'],
            'breakdowns' => [
                'symbols' => (object) ['__PHP_Incomplete_Class_Name' => 'Illuminate\\Database\\Eloquent\\Collection'],
            ],
        ], 30);

        // API request must use v5 key and return valid arrays
        $response = $this->getJson('/api/v1/statistics?account='.$account->id);
        $response->assertOk();

        $data = $response->json('data');
        self::assertIsArray($data['equity'], 'Equity must always be a JSON array');
        self::assertIsArray($data['breakdowns']['symbols'], 'Symbols breakdown must always be a JSON array');
        self::assertIsArray($data['period_pnl']['daily']['data'], 'Period PnL daily data must always be a JSON array');

        // Confirm new v5 cache key was populated with plain arrays
        $cached = Cache::get("paper:statistics:response:v5:{$account->id}:1:1:1");
        self::assertIsArray($cached);
        self::assertIsArray($cached['equity']);
        self::assertIsArray($cached['breakdowns']['symbols']);
    }

    public function test_empty_statistics_dataset_returns_strictly_sequential_arrays_not_objects(): void
    {
        $account = app(PaperAccountService::class)->default();

        $response = $this->getJson('/api/v1/statistics?account='.$account->id);
        $response->assertOk();

        $data = $response->json('data');
        self::assertTrue(array_is_list($data['equity']), 'Equity must be a list');
        self::assertSame([], $data['breakdowns']['symbols']);
        self::assertSame([], $data['breakdowns']['sides']);
        self::assertSame([], $data['breakdowns']['quality_classes']);
        self::assertSame([], $data['breakdowns']['r_distribution']);
        self::assertSame([], $data['period_pnl']['daily']['data']);
        self::assertSame([], $data['period_pnl']['weekly']['data']);
        self::assertSame([], $data['period_pnl']['monthly']['data']);
    }

    public function test_populated_statistics_with_multiple_symbols_and_sides_serializes_clean_dtos(): void
    {
        $account = app(PaperAccountService::class)->default();
        $now = CarbonImmutable::now('UTC');

        // Seed equity snapshots
        for ($i = 0; $i < 5; $i++) {
            EquitySnapshotRecord::query()->create([
                'paper_account_id' => $account->id,
                'recorded_at' => $now->subHours(5 - $i),
                'equity' => (string) (10000 + ($i * 100)),
                'realized_pnl' => '0',
                'unrealized_pnl' => '0',
                'drawdown_percent' => '0.50',
                'source_event_key' => 'test_snapshot_'.$i,
            ]);
        }

        // Seed closed positions for BTCUSDT and ETHUSDT across BUY and SELL
        $symbols = ['BTCUSDT', 'ETHUSDT'];
        $sides = ['BUY', 'SELL'];
        $classes = ['A', 'B'];

        for ($i = 0; $i < 4; $i++) {
            $sym = $symbols[$i % 2];
            $side = $sides[$i % 2];
            $cls = $classes[$i % 2];

            $sigId = (string) Uuid::uuid4();
            SignalRecord::query()->create([
                'id' => $sigId,
                'exchange' => 'bybit',
                'category' => 'linear',
                'symbol' => $sym,
                'timeframe' => '15',
                'side' => $side,
                'quality_class' => $cls,
                'score' => 75,
                'decision_time' => $now->subHours(10 - $i),
                'candle_close_time' => $now->subHours(10 - $i),
                'entry_low' => '50000.0',
                'entry_high' => '50100.0',
                'stop_loss' => '49500.0',
                'take_profit_1' => '51000.0',
                'take_profit_2' => '51500.0',
                'risk_reward_1' => '2.0',
                'risk_reward_2' => '3.0',
                'model_risk_percent' => '0.25',
                'expires_at' => $now->addHours(1),
                'maximum_holding_minutes' => 240,
                'reasons' => ['test_reason'],
                'risks' => [],
                'indicator_snapshot' => [],
                'contract' => [],
                'strategy_version' => '1.0.0',
                'schema_version' => '1.0.0',
                'strategy_id' => 'baseline',
            ]);

            VirtualPositionRecord::query()->create([
                'id' => (string) Uuid::uuid4(),
                'paper_account_id' => $account->id,
                'signal_id' => $sigId,
                'symbol' => $sym,
                'side' => $side,
                'quality_class' => $cls,
                'status' => 'CLOSED',
                'entry_price' => '50000.0',
                'stop_loss' => '49500.0',
                'take_profit_1' => '51000.0',
                'take_profit_2' => '51500.0',
                'initial_quantity' => '0.1',
                'remaining_quantity' => '0',
                'qty_step' => '0.001',
                'risk_capital' => '100.0',
                'execution_model' => 'SIMULATED',
                'strategy_version' => '1.0.0',
                'risk_version' => '1.0.0',
                'execution_version' => '1.0.0',
                'schema_version' => '1.0.0',
                'opened_at' => $now->subHours(8 - $i),
                'maximum_holding_until' => $now->addHours(4),
                'closed_at' => $now->subHours(4 - $i),
                'net_pnl' => (string) (($i % 2 === 0) ? '150.0' : '-50.0'),
                'gross_pnl' => (string) (($i % 2 === 0) ? '155.0' : '-45.0'),
                'fees' => '5.0',
                'funding' => '0',
                'r_multiple' => (string) (($i % 2 === 0) ? '1.5' : '-0.5'),
            ]);
        }

        $response = $this->getJson('/api/v1/statistics?account='.$account->id);
        $response->assertOk();

        $data = $response->json('data');

        // Verify equity is a list of scalar objects (1 initial + 5 seeded)
        self::assertCount(6, $data['equity']);
        self::assertTrue(array_is_list($data['equity']), 'Equity array must be a list');
        self::assertArrayHasKey('recorded_at', $data['equity'][0]);
        self::assertArrayHasKey('equity', $data['equity'][0]);

        // Verify breakdowns
        self::assertTrue(array_is_list($data['breakdowns']['symbols']));
        self::assertCount(2, $data['breakdowns']['symbols']);
        self::assertTrue(array_is_list($data['breakdowns']['sides']));
        self::assertTrue(array_is_list($data['breakdowns']['quality_classes']));
        self::assertTrue(array_is_list($data['breakdowns']['r_distribution']));
        self::assertCount(4, $data['breakdowns']['r_distribution']);

        // Verify period PnL
        self::assertTrue(array_is_list($data['period_pnl']['daily']['data']));
        self::assertGreaterThanOrEqual(1, count($data['period_pnl']['daily']['data']));
        self::assertArrayHasKey('period', $data['period_pnl']['daily']['data'][0]);
        self::assertArrayHasKey('net_pnl', $data['period_pnl']['daily']['data'][0]);
        self::assertArrayHasKey('cumulative_net_pnl', $data['period_pnl']['daily']['data'][0]);

        // Verify Redis cache contains zero PHP objects
        $cached = Cache::get("paper:statistics:response:v5:{$account->id}:1:1:1");
        $serialized = serialize($cached);
        self::assertStringNotContainsString('Illuminate\\Database\\Eloquent', $serialized);
        self::assertStringNotContainsString('Illuminate\\Support\\Collection', $serialized);
        self::assertStringNotContainsString('App\\PaperTrading\\Infrastructure', $serialized);
    }

    public function test_all_primary_strategy_accounts_generate_valid_statistics_dtos(): void
    {
        $accountService = app(PaperAccountService::class);
        $accounts = $accountService->allPrimaryAccounts();
        self::assertCount(13, $accounts);

        foreach ($accounts as $account) {
            $response = $this->getJson('/api/v1/statistics?account='.$account->id);
            $response->assertOk();

            $data = $response->json('data');
            self::assertIsArray($data['equity']);
            self::assertIsArray($data['breakdowns']['symbols']);
            self::assertIsArray($data['breakdowns']['sides']);
            self::assertIsArray($data['breakdowns']['quality_classes']);
            self::assertIsArray($data['breakdowns']['r_distribution']);
            self::assertIsArray($data['period_pnl']['daily']['data']);
        }
    }
}
