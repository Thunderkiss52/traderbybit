<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\MarketData\Domain\Interval;
use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Application\StrategyInputFactory;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Domain\SignalSide;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class PrimaryStrategyReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_primary_strategies_are_registered_scheduled_and_eligible(): void
    {
        $ids = array_values((array) config('paper.primary_strategies'));
        $registry = $this->app->make(StrategyRegistry::class);
        $metadata = collect($registry->metadata())->keyBy('id');

        self::assertCount(13, $ids);
        self::assertSame($ids, array_keys($metadata->all()));
        self::assertSame(array_values(array_diff($ids, ['baseline'])), array_values((array) config('research.forward_paper.enabled_strategies')));
        self::assertSame(['btc_eth_lead_lag'], (array) config('research.forward_paper.signal_only_strategies'));

        foreach ($ids as $id) {
            $item = $metadata->get($id);
            self::assertNotNull($item, "Strategy {$id} is not registered.");
            self::assertSame($registry->get($id)->version(), $item['version']);
            if (in_array($id, ['funding_open_interest_squeeze', 'liquidity_sweep_reversal', 'volatility_compression_breakout', 'btc_eth_lead_lag', 'multi_timeframe_trend_following'], true)) {
                self::assertSame('1.0.0', $item['version']);
            }
            self::assertSame($id !== 'btc_eth_lead_lag', $item['execution_eligible'], "Unexpected execution eligibility for {$id}.");
            self::assertSame($id, $registry->get($id)->id());
            self::assertNotSame([], $item['parameters']);
        }

        $events = collect(Schedule::events());
        self::assertNotNull($events->first(fn ($event): bool => str_contains((string) $event->description, 'research:forward-paper')));
        self::assertNotNull($events->first(fn ($event): bool => str_contains((string) $event->description, 'research:ensure-coverage')));

        $accounts = $this->app->make(PaperAccountService::class)->allPrimaryAccounts();
        self::assertSame($ids, $accounts->pluck('primary_strategy_id')->all());
        self::assertCount(13, $accounts->pluck('id')->unique());
        foreach ($accounts as $account) {
            self::assertTrue((bool) $account->is_primary);
            self::assertDecimalSame('10000', (string) $account->starting_balance);
            self::assertDecimalSame('10000', (string) $account->current_equity);
        }
    }

    public function test_each_primary_strategy_produces_a_causal_decision(): void
    {
        $at = new DateTimeImmutable('2026-10-13T10:15:00Z');
        $this->seedSymbol('BTCUSDT', '100');
        $this->seedSymbol('ETHUSDT', '101');
        $this->seedContext('BTCUSDT');
        $this->seedContext('ETHUSDT');

        $factory = $this->app->make(StrategyInputFactory::class);
        $baseInput = $factory->make('bybit', 'linear', 'BTCUSDT', $at);
        $peerInput = $factory->make('bybit', 'linear', 'BTCUSDT', $at, 'ETHUSDT');

        foreach ($this->app->make(StrategyRegistry::class)->all() as $strategy) {
            $decision = $strategy->evaluate($strategy->id() === 'btc_eth_lead_lag' ? $peerInput : $baseInput);
            self::assertInstanceOf(SignalSide::class, $decision->side, "{$strategy->id()} did not return a decision.");
            if ($strategy->id() !== 'baseline') {
                self::assertSame($strategy->id(), $decision->indicatorSnapshot['strategy_id']);
            }
            self::assertSame($at->getTimestamp(), $decision->decisionTime->getTimestamp());
            self::assertNotSame('', $decision->schemaVersion);
            self::assertSame($strategy->version(), $decision->strategyVersion);
        }
    }

    public function test_primary_accounts_are_individually_visible_through_apis_and_accounting_is_isolated(): void
    {
        $ids = array_values((array) config('paper.primary_strategies'));
        $accounts = $this->app->make(PaperAccountService::class)->allPrimaryAccounts();
        $target = $accounts->firstWhere('primary_strategy_id', 'liquidity_sweep_reversal');

        $this->getJson('/api/v1/system/version')->assertOk()->assertJsonPath('active_strategies_count', 13);

        $catalog = $this->getJson('/api/v1/research/strategies?per_page=20')->assertOk();
        $catalog->assertJsonPath('data.meta.total', 13);
        self::assertCount(13, $catalog->json('data.data'));
        foreach ($catalog->json('data.data') as $item) {
            self::assertArrayHasKey('execution_eligible', $item);
        }
        foreach ($ids as $id) {
            $this->getJson('/api/v1/research/strategies/'.$id)->assertOk()->assertJsonPath('data.strategy.id', $id);
        }

        $profitability = $this->getJson('/api/v1/research/profitability/strategies')->assertOk();
        $strategies = collect($profitability->json('strategies'));
        self::assertCount(13, $strategies);
        self::assertCount(13, $strategies->pluck('account_id')->unique());
        self::assertEqualsCanonicalizing($ids, $strategies->pluck('strategy_id')->all());
        foreach ($ids as $id) {
            $this->getJson('/api/v1/research/profitability/strategies/'.$id)->assertOk()->assertJsonPath('strategy_id', $id);
        }

        $this->seedPosition($target->id, 'liquidity_sweep_reversal');

        $after = collect($this->getJson('/api/v1/research/profitability/strategies')->assertOk()->json('strategies'));
        foreach ($after as $row) {
            self::assertSame($row['strategy_id'] === 'liquidity_sweep_reversal' ? 1 : 0, $row['full_lifecycle']['position_count'], "Position leaked into {$row['strategy_id']}.");
        }
    }

    private function seedPosition(string $accountId, string $strategyId): void
    {
        $signalId = (string) Uuid::uuid4();
        SignalRecord::query()->create([
            'id' => $signalId, 'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT',
            'timeframe' => '15', 'side' => 'BUY', 'quality_class' => 'A', 'score' => 85,
            'decision_time' => now()->subMinutes(15), 'candle_close_time' => now()->subMinutes(15),
            'entry_low' => '49900', 'entry_high' => '50100', 'stop_loss' => '49000',
            'take_profit_1' => '51500', 'take_profit_2' => '52000', 'risk_reward_1' => '1.5',
            'risk_reward_2' => '2.5', 'model_risk_percent' => '0.25', 'expires_at' => now()->addMinutes(15),
            'maximum_holding_minutes' => 240, 'reasons' => ['readiness'], 'risks' => [],
            'indicator_snapshot' => ['atr' => 100], 'contract' => ['research_mode' => true],
            'strategy_version' => '1.0.0', 'schema_version' => '1.0.0', 'strategy_id' => $strategyId,
        ]);

        VirtualPositionRecord::query()->create([
            'id' => (string) Uuid::uuid4(), 'paper_account_id' => $accountId, 'signal_id' => $signalId,
            'symbol' => 'BTCUSDT', 'side' => 'BUY', 'quality_class' => 'A', 'status' => 'OPEN',
            'entry_price' => '50000', 'stop_loss' => '49000', 'take_profit_1' => '51500', 'take_profit_2' => '52000',
            'initial_quantity' => '0.1', 'remaining_quantity' => '0.1', 'qty_step' => '0.001',
            'risk_capital' => '100', 'execution_model' => 'SIMULATED', 'strategy_version' => '1.0.0',
            'risk_version' => '1.0.0', 'execution_version' => '1.0.0', 'schema_version' => '1.0.0',
            'opened_at' => now()->subMinute(), 'maximum_holding_until' => now()->addHours(4),
        ]);
    }

    private function seedSymbol(string $symbol, string $lastClose): void
    {
        $spec = [
            [Interval::FiveMinutes, new DateTimeImmutable('2026-10-13T10:10:00Z')],
            [Interval::FifteenMinutes, new DateTimeImmutable('2026-10-13T10:00:00Z')],
            [Interval::ThirtyMinutes, new DateTimeImmutable('2026-10-13T09:30:00Z')],
            [Interval::OneHour, new DateTimeImmutable('2026-10-13T09:00:00Z')],
        ];
        foreach ($spec as [$interval, $lastOpen]) {
            $seconds = intdiv($interval->milliseconds(), 1000);
            for ($index = 219; $index >= 0; $index--) {
                $open = $lastOpen->modify('-'.($index * $seconds).' seconds');
                $close = $index === 0 && $interval === Interval::FifteenMinutes ? $lastClose : '100';
                DB::table('market_candles')->insert([
                    'exchange' => 'bybit', 'category' => 'linear', 'symbol' => $symbol, 'interval' => $interval->value,
                    'open_time' => $open, 'open' => $close, 'high' => (string) ((float) $close + 0.5),
                    'low' => (string) ((float) $close - 0.5), 'close' => $close, 'volume' => '100',
                    'turnover' => '200000', 'is_closed' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    private function seedContext(string $symbol): void
    {
        MarketContextRecord::query()->create([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => $symbol,
            'observed_at' => new DateTimeImmutable('2026-10-13T10:15:00Z'), 'bid' => '100.9', 'ask' => '101',
            'turnover_24h' => '100000000', 'tick_size' => '0.1', 'funding_rate' => '0.0001',
            'open_interest' => '1000000', 'open_interest_change' => '0.01',
        ]);
    }
}
