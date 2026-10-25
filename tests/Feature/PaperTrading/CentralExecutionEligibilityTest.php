<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\Audit\Application\AuditExportService;
use App\Audit\Infrastructure\Persistence\AuditExportRecord as PersistenceAuditExportRecord;
use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Application\PaperExecutionEligibilityService;
use App\PaperTrading\Application\PaperProcessingService;
use App\PaperTrading\Application\PositionLifecycleService;
use App\PaperTrading\Application\PositionSizingService;
use App\PaperTrading\Domain\ExecutionQuote;
use App\PaperTrading\Domain\ExecutionType;
use App\PaperTrading\Domain\InstrumentConstraints;
use App\PaperTrading\Domain\SignalLifecycle;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperSignalStateRecord;
use App\PaperTrading\Infrastructure\Persistence\RiskDecisionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\PaperTrading\Jobs\ProcessPaperTradingJob;
use App\Research\Infrastructure\Persistence\LearningCandidateStrategyRecord;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use DateTimeImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class CentralExecutionEligibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_signal_only_strategy_is_rejected_and_never_fills_even_when_job_runs(): void
    {
        config()->set('research.forward_paper.signal_only_strategies', ['btc_eth_lead_lag']);
        $account = $this->app->make(PaperAccountService::class)->primaryAccount('btc_eth_lead_lag');
        self::assertSame('ACTIVE', $account->status);

        $signal = $this->createSignal('btc_eth_lead_lag', 'BUY');
        $this->createContext();
        $this->createCandle('10:15', '100.5', '102', '100.5', '101');

        // Execute general job which processes all accounts
        $job = new ProcessPaperTradingJob(accountId: $account->id, at: '2026-10-14T10:20:00Z');
        $job->handle($this->app->make(PaperProcessingService::class));

        // Invariant: No virtual position created
        self::assertSame(0, VirtualPositionRecord::query()->where('paper_account_id', $account->id)->count());

        // Invariant: Signal was not ingested for entry or rejected
        $state = PaperSignalStateRecord::query()->where('paper_account_id', $account->id)->where('signal_id', $signal->id)->first();
        self::assertNull($state);

        // Attempting direct open through PositionLifecycleService must throw DomainException
        $stateRecord = PaperSignalStateRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'paper_account_id' => $account->id,
            'exchange_id' => $account->exchange_id,
            'signal_id' => $signal->id,
            'status' => SignalLifecycle::PendingEntry->value,
        ]);
        $limits = new InstrumentConstraints('0.001', '0.001', '1000', '5');
        $size = $this->app->make(PositionSizingService::class)->calculate('10000', '0.5', '100.5', '95', $limits, ['taker_fee_rate' => '0.0006', 'slippage_rate' => '0.0002']);
        $quote = new ExecutionQuote(ExecutionType::Entry, '100.5', '100.5', '0.02', 'close_5m', false);

        try {
            $this->app->make(PositionLifecycleService::class)->open($account, $stateRecord, $signal, $size, $quote, new DateTimeImmutable('2026-10-14T10:20:00Z'));
            self::fail('Expected PositionLifecycleService::open to reject signal_only_strategy.');
        } catch (DomainException $e) {
            self::assertStringContainsString('signal_only_strategy', $e->getMessage());
        }
    }

    public function test_missing_or_mismatched_configuration_hash_rejects_entry(): void
    {
        $account = $this->app->make(PaperAccountService::class)->primaryAccount('trend_pullback');
        $account->update(['configuration_hash' => null]);

        $eligibility = $this->app->make(PaperExecutionEligibilityService::class);
        self::assertFalse($eligibility->isAccountEntryEligible($account));
        self::assertSame('forward_configuration_retired', $eligibility->accountEntryRejectionReason($account));

        // Mismatched hash
        $account->update(['configuration_hash' => hash('sha256', 'mismatched_parameters')]);
        self::assertFalse($eligibility->isAccountEntryEligible($account));
        self::assertSame('forward_configuration_retired', $eligibility->accountEntryRejectionReason($account));

        // Retired version
        $account->update([
            'configuration_hash' => StrategyRegistry::hash($this->app->make(StrategyRegistry::class)->get('trend_pullback')->parameters()),
            'strategy_version' => '0.9.0',
        ]);
        self::assertFalse($eligibility->isAccountEntryEligible($account));
        self::assertSame('forward_configuration_retired', $eligibility->accountEntryRejectionReason($account));
    }

    public function test_unpromoted_research_candidate_rejects_entry(): void
    {
        $account = PaperAccountRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'name' => 'Research Candidate Test',
            'status' => 'ACTIVE',
            'starting_balance' => '10000',
            'current_equity' => '10000',
            'peak_equity' => '10000',
            'realized_pnl' => '0',
            'unrealized_pnl' => '0',
            'reserved_risk' => '0',
            'maximum_drawdown_percent' => '0',
            'current_drawdown_percent' => '0',
            'mode' => 'RESEARCH_FORWARD_PAPER',
            'exchange_id' => 'bybit',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.3.0',
        ]);

        $eligibility = $this->app->make(PaperExecutionEligibilityService::class);
        self::assertFalse($eligibility->isAccountEntryEligible($account));
        self::assertSame('unpromoted_research_candidate', $eligibility->accountEntryRejectionReason($account));

        // Once promoted, candidate becomes eligible
        LearningCandidateStrategyRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'name' => 'Candidate 1',
            'parent_strategy_id' => 'trend_pullback',
            'base_version' => '1.0.0',
            'candidate_version' => '1.3.0',
            'hypothesis' => 'Test',
            'status' => 'PAPER_PROMOTED',
            'paper_account_id' => $account->id,
            'parameters' => ['test' => true],
            'parameter_diff' => ['test' => true],
            'search_metadata' => ['seed' => 1],
            'configuration_hash' => hash('sha256', 'test'),
            'promoted_at' => now(),
        ]);

        self::assertTrue($eligibility->isAccountEntryEligible($account));
    }

    public function test_inactive_account_rejects_new_entries_while_processing(): void
    {
        $account = $this->app->make(PaperAccountService::class)->primaryAccount('trend_pullback');
        $account->update(['status' => 'BLOCKED']);

        $eligibility = $this->app->make(PaperExecutionEligibilityService::class);
        self::assertFalse($eligibility->isAccountEntryEligible($account));
        self::assertSame('account_not_active', $eligibility->accountEntryRejectionReason($account));

        $signal = $this->createSignal('trend_pullback', 'BUY');
        $state = PaperSignalStateRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'paper_account_id' => $account->id,
            'exchange_id' => $account->exchange_id,
            'signal_id' => $signal->id,
            'status' => SignalLifecycle::PendingEntry->value,
            'processed_at' => new DateTimeImmutable('2026-10-14T10:15:00Z'),
        ]);

        $this->app->make(PaperProcessingService::class)->process(
            accountId: $account->id,
            symbol: 'BTCUSDT',
            at: new DateTimeImmutable('2026-10-14T10:20:00Z'),
        );

        $state->refresh();
        self::assertSame(SignalLifecycle::Rejected->value, $state->status);
        self::assertSame('account_not_active', $state->reason);
    }

    public function test_safe_prospective_hash_binding_for_canonical_primary_account_with_history(): void
    {
        // 1. Setup canonical primary account with null configuration hash (simulating pre-upgrade state)
        $account = $this->app->make(PaperAccountService::class)->primaryAccount('trend_pullback');
        $account->update(['configuration_hash' => null, 'strategy_parameters' => null]);
        self::assertNull($account->refresh()->configuration_hash);

        // 2. Add historical rows (signals, risk decisions, journal events)
        $signal = $this->createSignal('trend_pullback', 'BUY');
        $decisionId = (string) Uuid::uuid4();
        RiskDecisionRecord::query()->create([
            'id' => $decisionId,
            'paper_account_id' => $account->id,
            'exchange_id' => 'bybit',
            'signal_id' => $signal->id,
            'allowed' => false,
            'reasons' => ['market_data_stale'],
            'snapshot' => ['test' => true],
            'risk_version' => '1.0.0',
            'decided_at' => now(),
        ]);
        $state = PaperSignalStateRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'paper_account_id' => $account->id,
            'exchange_id' => 'bybit',
            'signal_id' => $signal->id,
            'status' => SignalLifecycle::Rejected->value,
            'reason' => 'market_data_stale',
            'processed_at' => now(),
        ]);

        // 3. Invoke strategyForward to bind configuration hash prospectively
        $strategy = $this->app->make(StrategyRegistry::class)->get('trend_pullback');
        $bound = $this->app->make(PaperAccountService::class)->strategyForward(
            'trend_pullback',
            $strategy->version(),
            $strategy->parameters()
        );

        // Assert: configuration hash is now bound
        self::assertNotNull($bound->configuration_hash);
        self::assertSame(StrategyRegistry::hash($strategy->parameters()), $bound->configuration_hash);

        // Assert: historical records remain 100% intact
        self::assertSame(1, RiskDecisionRecord::query()->where('id', $decisionId)->count());
        self::assertSame(1, PaperSignalStateRecord::query()->where('id', $state->id)->count());
    }

    public function test_audit_export_primary_coverage_requires_non_null_configuration_hash(): void
    {
        // Set up one account with null hash and one with valid hash
        $bybit = $this->app->make(PaperAccountService::class)->primaryAccount('trend_pullback');
        $bybit->update(['configuration_hash' => null]);

        $export = PersistenceAuditExportRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'cache_key' => 'test-cache-key-'.Uuid::uuid4()->toString(),
            'period_days' => 1,
            'from_at' => now()->subDay(),
            'to_at' => now(),
            'as_of' => now(),
            'status' => 'PENDING',
            'progress_percent' => 0,
        ]);

        $workingDir = sys_get_temp_dir().'/audit_test_'.Uuid::uuid4()->toString();
        mkdir($workingDir);
        $counts = [];
        $entries = [];

        $service = $this->app->make(AuditExportService::class);
        $refMethod = new \ReflectionMethod(AuditExportService::class, 'writePrimaryCoverage');
        $refMethod->invokeArgs($service, [$workingDir.'/primary_strategy_coverage.csv', &$counts, &$entries]);

        $csv = file_get_contents($workingDir.'/primary_strategy_coverage.csv');
        $lines = array_map('str_getcsv', explode("\n", trim($csv)));
        $headers = array_shift($lines);

        $trendRow = collect($lines)->first(fn ($r) => ($r[0] ?? null) === 'bybit' && ($r[1] ?? null) === 'trend_pullback');
        self::assertNotNull($trendRow);
        // With null configuration hash, it must NOT be MATCHED
        self::assertSame('VERSION_OR_CONFIG_MISMATCH', $trendRow[7]);

        // Cleanup
        unlink($workingDir.'/primary_strategy_coverage.csv');
        rmdir($workingDir);
    }

    private function createSignal(string $strategyId, string $side): SignalRecord
    {
        $at = new DateTimeImmutable('2026-10-14T10:15:00Z');
        $strategy = $this->app->make(StrategyRegistry::class)->get($strategyId);

        return SignalRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15',
            'side' => $side,
            'quality_class' => 'A',
            'score' => 90,
            'decision_time' => $at,
            'candle_close_time' => $at,
            'entry_low' => '100',
            'entry_high' => '101',
            'stop_loss' => '95',
            'take_profit_1' => '105',
            'take_profit_2' => '110',
            'risk_reward_1' => '1.5',
            'risk_reward_2' => '2.5',
            'model_risk_percent' => '0.50',
            'expires_at' => $at->modify('+30 minutes'),
            'maximum_holding_minutes' => 240,
            'reasons' => ['test_fixture'],
            'risks' => [],
            'indicator_snapshot' => ['15m' => ['atr_14' => '1', 'close' => '100']],
            'contract' => ['research_mode' => true],
            'strategy_version' => $strategy->version(),
            'schema_version' => '1.0.0',
            'strategy_id' => $strategyId,
        ]);
    }

    private function createContext(): void
    {
        MarketContextRecord::query()->create([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT',
            'observed_at' => new DateTimeImmutable('2026-10-14T10:15:00Z'),
            'bid' => '100', 'ask' => '100.05', 'turnover_24h' => '100000000', 'tick_size' => '0.1',
            'funding_rate' => '0.0001', 'open_interest' => '1000000', 'qty_step' => '0.001',
            'min_order_qty' => '0.001', 'max_order_qty' => '1000', 'min_notional_value' => '5',
        ]);
    }

    private function createCandle(string $time, string $open, string $high, string $low, string $close): void
    {
        DB::table('market_candles')->insert([
            'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'interval' => '5',
            'open_time' => new DateTimeImmutable("2026-10-14T{$time}:00Z"),
            'open' => $open, 'high' => $high, 'low' => $low, 'close' => $close,
            'volume' => '100', 'turnover' => '10000', 'is_closed' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
