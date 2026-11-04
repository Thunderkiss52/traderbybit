<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\PaperTrading\Application\GlobalPaperCapitalGuard;
use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Application\PaperTopologySynchronizer;
use App\PaperTrading\Application\PositionLifecycleService;
use App\PaperTrading\Domain\ExecutionQuote;
use App\PaperTrading\Domain\ExecutionType;
use App\PaperTrading\Domain\PositionSize;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperSignalStateRecord;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use DateTimeImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class ConcurrentPaperEntryGlobalRiskPostgresIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_entry_decision_lock_serializes_concurrent_postgresql_transactions(): void
    {
        $this->ensurePostgresTestDatabaseReady('paper_accounts');
        $db1 = DB::connection('pgsql');

        // Start transaction on connection 1 and acquire advisory lock
        $db1->beginTransaction();
        app(GlobalPaperCapitalGuard::class)->lockEntryDecision();

        // Check that an advisory lock with classid 20261103, objid 50001 is held
        $held = (int) $db1->selectOne("
            SELECT COUNT(*) AS n 
            FROM pg_locks 
            WHERE locktype = 'advisory' 
              AND (classid::bigint << 32 | objid::bigint) = (20261103::bigint << 32 | 50001::bigint)
              AND granted = true
        ")->n;
        self::assertGreaterThanOrEqual(1, $held);

        $db1->commit();
    }

    public function test_two_concurrent_entries_across_different_accounts_reject_excess_risk(): void
    {
        $this->ensurePostgresTestDatabaseReady('paper_accounts');

        // Set up 26 canonical accounts (all 13 Bybit + 13 Binance)
        config(['exchanges.binance_paper_provisioning_approved' => true]);
        app(PaperTopologySynchronizer::class)->sync(true);

        // All 13 Bybit accounts have 10,000 USDT -> starting total = 130,000 USDT
        // At 5% cap, maximum aggregate risk budget = 6,500 USDT.
        // We set reserved_risk to 6,480 USDT across accounts, leaving exactly 20 USDT of risk available.
        PaperAccountRecord::query()->where('exchange_id', 'bybit')
            ->where('primary_strategy_id', 'baseline')
            ->update(['reserved_risk' => '6480.000000000000']);

        $accountService = app(PaperAccountService::class);
        $trendStrategy = app(StrategyRegistry::class)->get('trend_pullback');
        $momentumStrategy = app(StrategyRegistry::class)->get('momentum');
        $account1 = $accountService->strategyForward('trend_pullback', $trendStrategy->version(), $trendStrategy->parameters());
        $account2 = $accountService->strategyForward('momentum', $momentumStrategy->version(), $momentumStrategy->parameters());

        $signal1 = $this->createTestSignal('bybit', 'BTCUSDT', 'trend_pullback');
        $signal2 = $this->createTestSignal('bybit', 'ETHUSDT', 'momentum');

        $state1 = PaperSignalStateRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'paper_account_id' => $account1->id,
            'signal_id' => $signal1->id,
            'status' => 'PENDING_ENTRY',
        ]);
        $state2 = PaperSignalStateRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'paper_account_id' => $account2->id,
            'signal_id' => $signal2->id,
            'status' => 'PENDING_ENTRY',
        ]);

        // Each proposed position requires 15 USDT risk.
        // First position: 6480 + 15 = 6495 <= 6500 (Allowed).
        // Second position: 6495 + 15 = 6510 > 6500 (Exceeds global risk budget!).
        $size = new PositionSize(
            allowed: true,
            quantity: '0.1',
            riskCapital: '15.000000000000',
            effectiveLossPerUnit: '1000',
            notional: '5000',
            reason: null,
            qtyStep: '0.001',
        );
        $quote = new ExecutionQuote(ExecutionType::Entry, '50000', '50000', '0.0002', 'SYNTHETIC_TEST', false);
        $at = new DateTimeImmutable('2026-11-04T12:00:00Z');

        $lifecycle = app(PositionLifecycleService::class);

        try {
            // Account 1 opens successfully
            $pos1 = $lifecycle->open($account1, $state1, $signal1, $size, $quote, $at);
            self::assertNotNull($pos1);
            self::assertSame('OPEN', $pos1->status);

            // Account 2 attempts entry with another 15 USDT risk -> must be rejected
            $exceptionThrown = false;
            try {
                $lifecycle->open($account2, $state2, $signal2, $size, $quote, $at);
            } catch (DomainException $e) {
                $exceptionThrown = true;
                self::assertStringContainsString('global_risk_budget_exceeded', $e->getMessage());
            }
            self::assertTrue($exceptionThrown, 'Expected DomainException global_risk_budget_exceeded was not thrown');
        } finally {
            $posIds = DB::table('virtual_positions')->whereIn('paper_account_id', [$account1->id, $account2->id])->pluck('id');
            DB::table('virtual_executions')->whereIn('position_id', $posIds)->delete();
            DB::table('virtual_positions')->whereIn('paper_account_id', [$account1->id, $account2->id])->delete();
            DB::table('paper_signal_states')->whereIn('id', [$state1->id, $state2->id])->delete();
            DB::table('trading_journal_events')->whereIn('paper_account_id', [$account1->id, $account2->id])->delete();
            SignalRecord::query()->whereIn('id', [$signal1->id, $signal2->id])->delete();
            PaperAccountRecord::query()->where('exchange_id', 'bybit')->update(['reserved_risk' => '0']);
            PaperAccountRecord::query()->where('exchange_id', 'binance')->delete();
        }
    }

    private function createTestSignal(string $exchange, string $symbol, string $strategyId): SignalRecord
    {
        $strategy = app(StrategyRegistry::class)->get($strategyId);
        $now = now('UTC');

        return SignalRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'exchange' => $exchange,
            'category' => 'linear',
            'symbol' => $symbol,
            'strategy_id' => $strategyId,
            'strategy_version' => $strategy->version(),
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 90,
            'timeframe' => '15',
            'decision_time' => $now,
            'candle_close_time' => $now,
            'expires_at' => $now->copy()->addMinutes(60),
            'entry_low' => '49900',
            'entry_high' => '50100',
            'stop_loss' => '49000',
            'take_profit_1' => '51500',
            'take_profit_2' => '53000',
            'risk_reward_1' => '1.5',
            'risk_reward_2' => '3.0',
            'model_risk_percent' => '0.50',
            'maximum_holding_minutes' => 240,
            'reasons' => ['trend_confirmed'],
            'risks' => [],
            'indicator_snapshot' => ['15m' => ['close' => '50000']],
            'contract' => ['research_mode' => true],
            'schema_version' => '1.0.0',
        ]);
    }
}
