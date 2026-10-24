<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Infrastructure\Persistence\EquitySnapshotRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class PrimaryAccountMigrationRehearsalTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_rehearsal_preserves_eight_accounts_and_adds_five_without_history_loss(): void
    {
        $strategies = [
            'baseline',
            'trend_pullback',
            'bollinger_mean_reversion',
            'donchian_atr_breakout',
            'regime_switching',
            'momentum',
            'support_resistance_bounce',
            'support_resistance_breakout',
        ];

        // RefreshDatabase runs production migrations; restore the pre-migration
        // fixture only inside this rolled-back isolated test transaction.
        DB::table('equity_snapshots')->delete();
        DB::table('paper_accounts')->delete();

        // 1. Seed the eight pre-existing accounts with fixed UUIDs and custom balances
        $seededAccounts = [];
        foreach ($strategies as $index => $strategyId) {
            $customId = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, "custom:pre_existing:{$strategyId}");
            $balance = (string) (10000 + ($index * 500)); // Unique starting balances

            $acc = PaperAccountRecord::query()->create([
                'id' => $customId,
                'name' => 'Legacy Forward '.$strategyId,
                'status' => 'ACTIVE',
                'starting_balance' => $balance,
                'current_equity' => $balance,
                'peak_equity' => $balance,
                'realized_pnl' => (string) ($index * 10.5),
                'unrealized_pnl' => '0',
                'reserved_risk' => '0',
                'maximum_drawdown_percent' => '1.5',
                'current_drawdown_percent' => '0.5',
                'mode' => $strategyId === 'baseline' ? 'FORWARD_PAPER' : 'STRATEGY_FORWARD_PAPER',
                'strategy_id' => $strategyId,
                'strategy_version' => '1.0.0',
            ]);

            $signalId = (string) Uuid::uuid4();
            SignalRecord::query()->create([
                'id' => $signalId,
                'exchange' => 'bybit',
                'category' => 'linear',
                'symbol' => 'BTCUSDT',
                'timeframe' => '15',
                'side' => 'BUY',
                'quality_class' => 'A',
                'score' => 85,
                'decision_time' => CarbonImmutable::now()->subMinutes(15),
                'candle_close_time' => CarbonImmutable::now()->subMinutes(15),
                'entry_low' => '49900.0',
                'entry_high' => '50100.0',
                'stop_loss' => '49000.0',
                'take_profit_1' => '51500.0',
                'take_profit_2' => '52000.0',
                'risk_reward_1' => '1.5',
                'risk_reward_2' => '2.5',
                'model_risk_percent' => '0.25',
                'expires_at' => CarbonImmutable::now()->addMinutes(15),
                'maximum_holding_minutes' => 240,
                'reasons' => ['test'],
                'risks' => [],
                'indicator_snapshot' => ['atr' => 100],
                'contract' => ['research_mode' => true],
                'strategy_version' => '1.0.0',
                'schema_version' => '1.0.0',
                'strategy_id' => $strategyId,
            ]);

            // Add position linked to account
            VirtualPositionRecord::query()->create([
                'id' => (string) Uuid::uuid4(),
                'paper_account_id' => $customId,
                'signal_id' => $signalId,
                'symbol' => 'BTCUSDT',
                'side' => 'BUY',
                'quality_class' => 'A',
                'status' => 'OPEN',
                'entry_price' => '50000.0',
                'stop_loss' => '49000.0',
                'take_profit_1' => '51500.0',
                'take_profit_2' => '52000.0',
                'initial_quantity' => '0.1',
                'remaining_quantity' => '0.1',
                'qty_step' => '0.001',
                'risk_capital' => '100.0',
                'execution_model' => 'SIMULATED',
                'strategy_version' => '1.0.0',
                'risk_version' => '1.0.0',
                'execution_version' => '1.0.0',
                'schema_version' => '1.0.0',
                'opened_at' => CarbonImmutable::now()->subMinutes(10),
                'maximum_holding_until' => CarbonImmutable::now()->addHours(4),
            ]);

            // Add equity snapshot linked to account
            EquitySnapshotRecord::query()->create([
                'paper_account_id' => $customId,
                'recorded_at' => CarbonImmutable::now()->subMinutes(10),
                'equity' => $balance,
                'realized_pnl' => (string) ($index * 10.5),
                'unrealized_pnl' => '0',
                'drawdown_percent' => '0.5',
                'source_event_key' => 'test:rehearsal:'.$strategyId,
            ]);

            $seededAccounts[$strategyId] = [
                'id' => $customId,
                'balance' => $balance,
                'realized_pnl' => (string) ($index * 10.5),
            ];
        }

        self::assertSame(8, PaperAccountRecord::query()->count());

        // 2. Execute migration logic
        $migration = require database_path('migrations/2026_10_18_010000_enforce_eight_primary_accounts_and_widen_quality.php');
        // Schema alterations were already applied by RefreshDatabase; run the data alignment loop
        foreach ($strategies as $strategyId) {
            $existing = DB::table('paper_accounts')
                ->where('strategy_id', $strategyId)
                ->whereIn('mode', ['FORWARD_PAPER', 'STRATEGY_FORWARD_PAPER', 'PRIMARY'])
                ->orderBy('created_at')
                ->first();

            self::assertNotNull($existing);
            DB::table('paper_accounts')
                ->where('id', $existing->id)
                ->update([
                    'primary_strategy_id' => $strategyId,
                    'is_primary' => true,
                ]);
        }

        $newStrategies = [
            'funding_open_interest_squeeze',
            'liquidity_sweep_reversal',
            'volatility_compression_breakout',
            'btc_eth_lead_lag',
            'multi_timeframe_trend_following',
        ];

        $migration = require database_path('migrations/2026_10_22_020000_add_five_primary_strategy_accounts.php');
        $previousEnvironment = $this->app['env'];
        $this->app['env'] = 'local';
        try {
            $migration->up();
            self::assertCount(13, PaperAccountRecord::query()->where('is_primary', true)->get());
            $migration->up();
        } finally {
            $this->app['env'] = $previousEnvironment;
        }

        self::assertCount(13, PaperAccountRecord::query()->where('is_primary', true)->get());
        self::assertSame(13, PaperAccountRecord::query()->distinct()->count('primary_strategy_id'));

        // 3. Verify all 13 primary accounts exist; the original 8 keep their IDs and balances
        $accountService = $this->app->make(PaperAccountService::class);
        $accounts = $accountService->allPrimaryAccounts();

        self::assertCount(13, $accounts);

        foreach ($accounts as $account) {
            $strat = (string) $account->primary_strategy_id;
            if (! array_key_exists($strat, $seededAccounts)) {
                self::assertContains($strat, $newStrategies);
                self::assertTrue(BigDecimal::of((string) $account->starting_balance)->isEqualTo(10000));
                self::assertTrue(BigDecimal::of((string) $account->current_equity)->isEqualTo(10000));
                self::assertTrue((bool) $account->is_primary);

                continue;
            }

            $expected = $seededAccounts[$strat];

            // UUID must match exactly
            self::assertSame($expected['id'], $account->id, "Account ID for {$strat} was altered!");

            // Balance and PnL must be unchanged
            self::assertTrue(BigDecimal::of((string) $account->starting_balance)->isEqualTo($expected['balance']));
            self::assertTrue(BigDecimal::of((string) $account->current_equity)->isEqualTo($expected['balance']));
            self::assertTrue(BigDecimal::of((string) $account->realized_pnl)->isEqualTo($expected['realized_pnl']));

            // Associated positions and snapshots must remain attached
            $posCount = VirtualPositionRecord::query()->where('paper_account_id', $account->id)->count();
            self::assertSame(1, $posCount, "Positions lost for {$strat}!");

            $snapCount = EquitySnapshotRecord::query()->where('paper_account_id', $account->id)->count();
            self::assertGreaterThanOrEqual(1, $snapCount, "Equity snapshots lost for {$strat}!");
        }
    }
}
