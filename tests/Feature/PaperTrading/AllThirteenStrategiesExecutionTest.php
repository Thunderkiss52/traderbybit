<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\Exchanges\Binance\BinanceDeploymentInitializer;
use App\Exchanges\Binance\BinancePaperActivation;
use App\Exchanges\Binance\BinancePaperSafety;
use App\Exchanges\Binance\ExchangePaperReconciliation;
use App\Exchanges\Infrastructure\Persistence\ExchangeRecord;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Application\PaperExecutionEligibilityService;
use App\PaperTrading\Application\PaperProcessingService;
use App\PaperTrading\Infrastructure\Persistence\MarketFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperSignalStateRecord;
use App\PaperTrading\Infrastructure\Persistence\RiskDecisionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Concerns\SeedsValidatedPaperEvidence;
use Tests\TestCase;

final class AllThirteenStrategiesExecutionTest extends TestCase
{
    use RefreshDatabase;
    use SeedsValidatedPaperEvidence;

    protected function setUp(): void
    {
        parent::setUp();
        // Dedicated preservation fixture for the original 26-account registry.
        config(['exchanges.enabled' => ['bybit', 'binance']]);
        DB::table('exchanges')->whereNotIn('id', ['bybit', 'binance'])->delete();
        config([
            'exchanges.binance.paper_taker_rate' => '0.0005',
            'exchanges.binance.paper_slippage_rate' => '0.0002',
            'exchanges.binance.paper_execution_approved' => false,
            'exchanges.binance.research_cycle_enabled' => true,
        ]);
        // Explicit synthetic catalog approval and fill-cost evidence for this cohort.
        $binance = ExchangeRecord::query()->findOrFail('binance');
        $binance->update([
            'lifecycle_status' => 'ACTIVE_PAPER', 'paper_approved' => true,
            'paper_approved_at' => now('UTC'),
            'cost_model' => array_replace((array) $binance->cost_model, [
                'maker_rate' => '0.0002', 'taker_rate' => '0.0005', 'slippage_rate' => '0.0002',
            ]),
        ]);
    }

    public function test_all_thirteen_strategies_are_execution_eligible_in_registry_and_eligibility_service(): void
    {
        $registry = $this->app->make(StrategyRegistry::class);
        $initializer = $this->app->make(BinanceDeploymentInitializer::class);
        $initializer->initialize('10000', 'OWNER-VECTOR-20261023');

        $eligibility = $this->app->make(PaperExecutionEligibilityService::class);
        $primaryStrategies = (array) config('paper.primary_strategies', []);

        self::assertCount(13, $primaryStrategies);
        self::assertSame([], (array) config('research.forward_paper.signal_only_strategies'));

        $metadata = collect($registry->metadata())->keyBy('id');

        foreach ($primaryStrategies as $strategyId) {
            $item = $metadata->get($strategyId);
            self::assertNotNull($item, "Strategy {$strategyId} missing from registry metadata.");
            self::assertTrue($item['execution_eligible'], "Strategy {$strategyId} should be execution_eligible.");
        }

        // Check Bybit primary accounts
        $accountService = $this->app->make(PaperAccountService::class);
        $bybitAccounts = $accountService->allPrimaryAccounts('bybit');
        self::assertCount(13, $bybitAccounts);

        foreach ($bybitAccounts as $account) {
            $strategy = $registry->get($account->strategy_id);
            if ($account->configuration_hash === null) {
                $account = $accountService->strategyForward($account->strategy_id, $strategy->version(), $strategy->parameters());
            }
            self::assertTrue($eligibility->isAccountEntryEligible($account), "Bybit account {$account->strategy_id} should be eligible for entry.");
            self::assertNull($eligibility->accountEntryRejectionReason($account));
        }
    }

    public function test_binance_activation_activates_all_13_strategies_when_approved(): void
    {
        $initializer = $this->app->make(BinanceDeploymentInitializer::class);
        $initializer->initialize('10000', 'OWNER-VECTOR-20261023');

        $baseHour = (int) floor(time() / 3600) * 3600;
        $now = (new DateTimeImmutable("@{$baseHour}"))->modify('+3 minutes');
        $this->travelTo($now);
        $this->seedBinanceReadinessData($now);

        $activationService = $this->app->make(BinancePaperActivation::class);
        $result = $activationService->activate();

        self::assertSame(13, $result['paper_activated']);
        self::assertSame(0, $result['already_active']);
        self::assertSame(0, $result['still_signal_only']);

        $binanceAccounts = PaperAccountRecord::query()->where('exchange_id', 'binance')->where('is_primary', true)->get();
        self::assertCount(13, $binanceAccounts);

        foreach ($binanceAccounts as $account) {
            self::assertSame('ACTIVE', $account->status, "Binance account {$account->strategy_id} should be ACTIVE.");
        }

        // When paper_execution_approved is false, assertExecutable rejects
        $leadLagBinance = $binanceAccounts->firstWhere('strategy_id', 'btc_eth_lead_lag');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Binance paper execution is not approved.');
        BinancePaperSafety::assertExecutable($leadLagBinance);
    }

    public function test_btc_eth_lead_lag_and_funding_squeeze_execute_simulated_paper_positions_on_bybit(): void
    {
        $initializer = $this->app->make(BinanceDeploymentInitializer::class);
        $initializer->initialize('10000', 'OWNER-VECTOR-20261023');

        $eligibility = $this->app->make(PaperExecutionEligibilityService::class);
        $accountService = $this->app->make(PaperAccountService::class);
        $processingService = $this->app->make(PaperProcessingService::class);

        $leadLagStrategy = $this->app->make(StrategyRegistry::class)->get('btc_eth_lead_lag');
        $leadLagAccount = $accountService->strategyForward('btc_eth_lead_lag', $leadLagStrategy->version(), $leadLagStrategy->parameters());

        $squeezeStrategy = $this->app->make(StrategyRegistry::class)->get('funding_open_interest_squeeze');
        $squeezeAccount = $accountService->strategyForward('funding_open_interest_squeeze', $squeezeStrategy->version(), $squeezeStrategy->parameters());

        self::assertTrue($eligibility->isAccountEntryEligible($leadLagAccount));
        self::assertTrue($eligibility->isAccountEntryEligible($squeezeAccount));

        $decisionTime = new DateTimeImmutable('2026-10-25T14:15:00Z');
        $processTime = new DateTimeImmutable('2026-10-25T14:20:00Z');
        $this->createMarketContext('bybit', 'BTCUSDT', $decisionTime);
        $this->createMarketCandle('bybit', 'BTCUSDT', '5', $decisionTime, '100.2', '101.0', '100.0', '100.5');

        // Create signal for btc_eth_lead_lag
        $this->createSignal('bybit', 'btc_eth_lead_lag', 'BTCUSDT', 'BUY', $decisionTime);

        // Process through PaperProcessingService at processTime (decision + 5m)
        $result = $processingService->process($leadLagAccount->id, 'BTCUSDT', $processTime);
        self::assertGreaterThanOrEqual(1, $result['events_processed']);

        $position = VirtualPositionRecord::query()->where('paper_account_id', $leadLagAccount->id)->first();
        self::assertNotNull($position, 'Virtual position must be opened for btc_eth_lead_lag on Bybit.');
        self::assertSame('OPEN', $position->status);
        self::assertSame('bybit', $position->exchange_id);
        self::assertSame('BTCUSDT', $position->symbol);

        // Verify executions
        $executions = VirtualExecutionRecord::query()->where('position_id', $position->id)->get();
        self::assertCount(1, $executions);
        self::assertSame('ENTRY', $executions->first()->type);
        self::assertSame('bybit', $executions->first()->exchange_id);

        // Create signal for funding_open_interest_squeeze
        $this->createSignal('bybit', 'funding_open_interest_squeeze', 'BTCUSDT', 'BUY', $decisionTime);
        $squeezeResult = $processingService->process($squeezeAccount->id, 'BTCUSDT', $processTime);
        self::assertGreaterThanOrEqual(1, $squeezeResult['events_processed']);

        $squeezePosition = VirtualPositionRecord::query()->where('paper_account_id', $squeezeAccount->id)->first();
        self::assertNotNull($squeezePosition, 'Virtual position must be opened for funding_open_interest_squeeze on Bybit.');
        self::assertSame('OPEN', $squeezePosition->status);
        self::assertSame('bybit', $squeezePosition->exchange_id);

        // Verify M9 reconciliation passes
        $m9 = $this->app->make(ExchangePaperReconciliation::class)->inspect();
        self::assertSame('LEDGER_PASS_ONLY', $m9['status']);
    }

    public function test_all_thirteen_strategies_execute_simulated_paper_positions_on_binance_when_approved(): void
    {
        $initializer = $this->app->make(BinanceDeploymentInitializer::class);
        $initializer->initialize('10000', 'OWNER-VECTOR-20261023');

        $baseHour = (int) floor(time() / 3600) * 3600;
        $now = (new DateTimeImmutable("@{$baseHour}"))->modify('+3 minutes');
        $this->travelTo($now);
        $this->seedBinanceReadinessData($now);

        $activationService = $this->app->make(BinancePaperActivation::class);
        $activationService->activate();

        // Enable execution approval
        config(['exchanges.binance.paper_execution_approved' => true]);

        $binanceAccounts = PaperAccountRecord::query()->where('exchange_id', 'binance')->where('is_primary', true)->get()->keyBy('primary_strategy_id');
        self::assertCount(13, $binanceAccounts);

        $initializer = $this->app->make(BinanceDeploymentInitializer::class);
        $initializer->initialize('10000', 'OWNER-VECTOR-20261023');

        $eligibility = $this->app->make(PaperExecutionEligibilityService::class);
        $processingService = $this->app->make(PaperProcessingService::class);

        // Verify every one of the 13 Binance accounts passes assertExecutable and entry eligibility
        foreach ($binanceAccounts as $id => $account) {
            BinancePaperSafety::assertExecutable($account);
            self::assertTrue($eligibility->isAccountEntryEligible($account), "Binance {$id} should be entry eligible.");
        }

        $decisionTime = $now->modify('-15 minutes');
        $processTime = $now->modify('-10 minutes');
        $this->createMarketContext('binance', 'BTCUSDT', $decisionTime);
        $this->createMarketCandle('binance', 'BTCUSDT', '5', $decisionTime, '100.2', '101.0', '100.0', '100.5');

        // Test execution specifically on btc_eth_lead_lag and funding_open_interest_squeeze
        $leadLagBinance = $binanceAccounts->get('btc_eth_lead_lag');
        $this->createSignal('binance', 'btc_eth_lead_lag', 'BTCUSDT', 'BUY', $decisionTime);
        $llResult = $processingService->process($leadLagBinance->id, 'BTCUSDT', $processTime);
        self::assertGreaterThanOrEqual(1, $llResult['events_processed']);

        $llPos = VirtualPositionRecord::query()->where('paper_account_id', $leadLagBinance->id)->first();
        self::assertNotNull($llPos);
        self::assertSame('OPEN', $llPos->status);
        self::assertSame('binance', $llPos->exchange_id);

        // Check taker fee applied using Binance rate (0.0005)
        $llExec = VirtualExecutionRecord::query()->where('position_id', $llPos->id)->first();
        self::assertNotNull($llExec);
        self::assertSame('binance', $llExec->exchange_id);
        self::assertGreaterThan('0', (string) $llExec->commission);

        $squeezeBinance = $binanceAccounts->get('funding_open_interest_squeeze');
        $this->createSignal('binance', 'funding_open_interest_squeeze', 'BTCUSDT', 'BUY', $decisionTime);
        $sqResult = $processingService->process($squeezeBinance->id, 'BTCUSDT', $processTime);
        self::assertGreaterThanOrEqual(1, $sqResult['events_processed']);

        $sqPos = VirtualPositionRecord::query()->where('paper_account_id', $squeezeBinance->id)->first();
        self::assertNotNull($sqPos);
        self::assertSame('OPEN', $sqPos->status);
        self::assertSame('binance', $sqPos->exchange_id);

        // Verify M9 reconciliation passes for all 26 accounts
        $m9 = $this->app->make(ExchangePaperReconciliation::class)->inspect();
        self::assertSame('LEDGER_PASS_ONLY', $m9['status']);
        self::assertSame(26, $m9['verified_pairs']);
    }

    public function test_risk_control_rejects_oversized_orders_for_previously_signal_only_strategies(): void
    {
        $accountService = $this->app->make(PaperAccountService::class);
        $leadLagAccount = $accountService->primaryAccount('btc_eth_lead_lag', 'bybit');
        $processingService = $this->app->make(PaperProcessingService::class);

        $decisionTime = new DateTimeImmutable('2026-10-25T14:15:00Z');
        $processTime = new DateTimeImmutable('2026-10-25T14:20:00Z');
        $this->createMarketContext('bybit', 'BTCUSDT', $decisionTime);
        $this->createMarketCandle('bybit', 'BTCUSDT', '5', $decisionTime, '100.2', '101.0', '100.0', '100.5');

        // Create signal with excessive risk percent (2.50% while Class A max is 0.50%)
        $signal = $this->createSignal('bybit', 'btc_eth_lead_lag', 'BTCUSDT', 'BUY', $decisionTime, modelRiskPercent: '2.50');

        $result = $processingService->process($leadLagAccount->id, 'BTCUSDT', $processTime);

        // No position should be opened
        self::assertSame(0, VirtualPositionRecord::query()->where('paper_account_id', $leadLagAccount->id)->count());

        // Signal state must be REJECTED
        $state = PaperSignalStateRecord::query()->where('paper_account_id', $leadLagAccount->id)->where('signal_id', $signal->id)->first();
        self::assertNotNull($state);
        self::assertSame('REJECTED', $state->status);
        self::assertSame('signal_risk_above_class_limit', $state->reason);

        // Risk decision record must be persisted with allowed = false
        $decision = RiskDecisionRecord::query()->where('paper_account_id', $leadLagAccount->id)->where('signal_id', $signal->id)->first();
        self::assertNotNull($decision);
        self::assertFalse((bool) $decision->allowed);
        $reasons = is_array($decision->reasons) ? $decision->reasons : json_decode((string) $decision->reasons, true);
        self::assertContains('signal_risk_above_class_limit', $reasons);
    }

    public function test_isolation_between_bybit_and_binance_is_strictly_enforced_for_all_strategies(): void
    {
        $initializer = $this->app->make(BinanceDeploymentInitializer::class);
        $initializer->initialize('10000', 'OWNER-VECTOR-20261023');

        $baseHour = (int) floor(time() / 3600) * 3600;
        $now = (new DateTimeImmutable("@{$baseHour}"))->modify('+3 minutes');
        $this->travelTo($now);
        $this->seedBinanceReadinessData($now);
        $this->app->make(BinancePaperActivation::class)->activate();

        config(['exchanges.binance.paper_execution_approved' => true]);

        $bybitAccount = $this->app->make(PaperAccountService::class)->primaryAccount('btc_eth_lead_lag', 'bybit');
        $binanceAccount = PaperAccountRecord::query()->where('exchange_id', 'binance')->where('primary_strategy_id', 'btc_eth_lead_lag')->firstOrFail();

        self::assertNotSame($bybitAccount->id, $binanceAccount->id);
        self::assertSame('bybit', $bybitAccount->exchange_id);
        self::assertSame('binance', $binanceAccount->exchange_id);

        $decisionTime = $now->modify('-15 minutes');
        $processTime = $now->modify('-10 minutes');
        $this->createMarketContext('bybit', 'BTCUSDT', $decisionTime);
        $this->createMarketCandle('bybit', 'BTCUSDT', '5', $decisionTime, '100.2', '101.0', '100.0', '100.5');

        // Execute only on Bybit
        $this->createSignal('bybit', 'btc_eth_lead_lag', 'BTCUSDT', 'BUY', $decisionTime);
        $this->app->make(PaperProcessingService::class)->process($bybitAccount->id, 'BTCUSDT', $processTime);

        // Verify Bybit has position, Binance has none
        self::assertSame(1, VirtualPositionRecord::query()->where('paper_account_id', $bybitAccount->id)->count());
        self::assertSame(0, VirtualPositionRecord::query()->where('paper_account_id', $binanceAccount->id)->count());

        // Positions table exchange_id is correctly labeled
        $bybitPos = VirtualPositionRecord::query()->where('paper_account_id', $bybitAccount->id)->first();
        self::assertSame('bybit', $bybitPos->exchange_id);

        // Financial ledger reconciliation passes
        $m9 = $this->app->make(ExchangePaperReconciliation::class)->inspect();
        self::assertSame('LEDGER_PASS_ONLY', $m9['status']);
    }

    private function createSignal(string $exchange, string $strategyId, string $symbol, string $side, DateTimeImmutable $at, string $modelRiskPercent = '0.50'): SignalRecord
    {
        $strategy = $this->app->make(StrategyRegistry::class)->get($strategyId);

        $signal = SignalRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'exchange' => $exchange,
            'category' => 'linear',
            'symbol' => $symbol,
            'timeframe' => '15',
            'side' => $side,
            'quality_class' => 'A',
            'score' => 90,
            'decision_time' => $at,
            'candle_close_time' => $at,
            'entry_low' => '100.0',
            'entry_high' => '101.0',
            'stop_loss' => '95.0',
            'take_profit_1' => '108.0',
            'take_profit_2' => '112.0',
            'risk_reward_1' => '1.5',
            'risk_reward_2' => '2.5',
            'model_risk_percent' => $modelRiskPercent,
            'expires_at' => $at->modify('+30 minutes'),
            'maximum_holding_minutes' => 240,
            'reasons' => ['test_fixture'],
            'risks' => [],
            'indicator_snapshot' => ['15m' => ['atr_14' => '1', 'close' => '100.5']],
            'contract' => ['research_mode' => true],
            'strategy_version' => $strategy->version(),
            'schema_version' => '1.0.0',
            'strategy_id' => $strategyId,
        ]);
        $this->seedValidatedPaperEvidence($signal);

        return $signal;
    }

    private function createMarketContext(string $exchange, string $symbol, DateTimeImmutable $at): void
    {
        MarketContextRecord::query()->updateOrCreate([
            'exchange' => $exchange,
            'category' => 'linear',
            'symbol' => $symbol,
            'observed_at' => $at,
        ], [
            'bid' => '100.48',
            'ask' => '100.52',
            'turnover_24h' => '100000000',
            'tick_size' => '0.1',
            'qty_step' => '0.001',
            'min_order_qty' => '0.001',
            'max_order_qty' => '1000',
            'min_notional_value' => '1',
            'funding_rate' => '0.0001',
            'open_interest' => '1000000',
        ]);
    }

    private function createMarketCandle(string $exchange, string $symbol, string $interval, DateTimeImmutable $at, string $open, string $high, string $low, string $close): void
    {
        CandleRecord::query()->updateOrCreate([
            'exchange' => $exchange,
            'category' => 'linear',
            'symbol' => $symbol,
            'interval' => $interval,
            'open_time' => $at,
        ], [
            'open' => $open,
            'high' => $high,
            'low' => $low,
            'close' => $close,
            'volume' => '100',
            'turnover' => '10000',
            'is_closed' => true,
        ]);
    }

    private function seedBinanceReadinessData(DateTimeImmutable|CarbonInterface $now): void
    {
        $symbols = ['BTCUSDT', 'ETHUSDT'];
        $nowSeconds = $now->getTimestamp();
        $baseHour = (int) floor($nowSeconds / 3600) * 3600;
        foreach ($symbols as $symbol) {
            foreach (['5', '15', '30', '60'] as $interval) {
                $seconds = (int) $interval * 60;
                for ($i = 1; $i <= 220; $i++) {
                    $timestamp = $baseHour - ($i * $seconds);
                    $openTime = (new DateTimeImmutable("@{$timestamp}"))->setTimezone(new \DateTimeZone('UTC'));
                    CandleRecord::query()->create([
                        'exchange' => 'binance',
                        'category' => 'linear',
                        'symbol' => $symbol,
                        'interval' => $interval,
                        'open_time' => $openTime,
                        'open' => '100',
                        'high' => '101',
                        'low' => '99',
                        'close' => '100.5',
                        'volume' => '100',
                        'turnover' => '10000',
                        'is_closed' => true,
                    ]);
                }
            }
            MarketContextRecord::query()->create([
                'exchange' => 'binance',
                'category' => 'linear',
                'symbol' => $symbol,
                'observed_at' => $now->modify('-5 minutes'),
                'bid' => '100.48',
                'ask' => '100.52',
                'turnover_24h' => '1000000',
                'tick_size' => '0.1',
                'qty_step' => '0.001',
                'min_order_qty' => '0.001',
                'max_order_qty' => '1000',
                'min_notional_value' => '1',
                'funding_rate' => '0.0001',
                'open_interest' => '1000000',
            ]);
            MarketFundingEventRecord::query()->create([
                'exchange' => 'binance',
                'category' => 'linear',
                'symbol' => $symbol,
                'rate' => '0.0001',
                'funding_at' => $now->modify('-2 hours'),
                'mark_price' => '100.5',
            ]);
        }
    }
}
