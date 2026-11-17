<?php

declare(strict_types=1);

namespace Tests\Feature\PaperTrading;

use App\Exchanges\Binance\BinanceDeploymentInitializer;
use App\Exchanges\Domain\ExchangeLifecycleStatus;
use App\Exchanges\Infrastructure\Persistence\ExchangeRecord;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Application\PaperProcessingService;
use App\PaperTrading\Domain\PositionStatus;
use App\PaperTrading\Domain\SignalLifecycle;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperSignalStateRecord;
use App\PaperTrading\Infrastructure\Persistence\RiskDecisionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Application\StrategyRegistry;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Tests\Concerns\SeedsValidatedPaperEvidence;
use Tests\TestCase;

final class TemporalIntegrityAndCausalityTest extends TestCase
{
    use RefreshDatabase;
    use SeedsValidatedPaperEvidence;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'exchanges.binance.paper_taker_rate' => '0.0005',
            'exchanges.binance.paper_slippage_rate' => '0.0002',
            'exchanges.binance.paper_execution_approved' => true,
            'exchanges.binance.research_cycle_enabled' => true,
            'paper.processing.max_forward_fill_lag_seconds' => 300,
            'paper.processing.max_signal_ingest_lag_seconds' => 300,
            'paper.market_safety.maximum_context_age_seconds' => 600,
        ]);
    }

    private function initializeAndActivateBinanceAccounts(?DateTimeImmutable $createdAt = null): void
    {
        $createdAt ??= new DateTimeImmutable('2026-10-25 17:00:00', new DateTimeZone('UTC'));
        $this->travelTo($createdAt);
        // This isolated fixture explicitly authorizes the exchange as well as its accounts.
        $exchange = ExchangeRecord::query()->findOrFail('binance');
        $exchange->update([
            'paper_approved' => true,
            'lifecycle_status' => ExchangeLifecycleStatus::ActivePaper->value,
            'paper_approved_at' => $createdAt,
            // Synthetic fixture contract; no claim about a production fee tier.
            'cost_model' => array_replace((array) $exchange->cost_model, ['maker_rate' => '0.0002']),
        ]);
        $initializer = $this->app->make(BinanceDeploymentInitializer::class);
        $initializer->initialize('10000', 'OWNER-VECTOR-20261023');
        PaperAccountRecord::query()->where('exchange_id', 'binance')->update([
            'status' => 'ACTIVE',
            'created_at' => $createdAt,
            'sizing_settings' => ['mode' => 'risk_percent'],
        ]);
    }

    /**
     * Exact reproduction of the confirmed production incident:
     * Strategy: momentum, exchange: binance, symbol: BTCUSDT.
     * Signal decision time: 2026-10-25 17:45:00 UTC.
     * Signal persisted: 17:48:00 UTC.
     * Signal expiration: 18:15:00 UTC.
     * ENTRY market event: 18:10:00 UTC.
     * Processor runs at: 18:15:10 / 18:19:11 UTC.
     * Invariant: The system MUST NEVER open a position with opened_at = 18:10:00 retrospectively.
     */
    public function test_exact_production_incident_prevents_retrospective_fill(): void
    {
        $this->initializeAndActivateBinanceAccounts();

        $account = PaperAccountRecord::query()
            ->where('exchange_id', 'binance')
            ->where('is_primary', true)
            ->where('strategy_id', 'momentum')
            ->firstOrFail();

        // 1. Context observed at 17:44:00 (before 17:45:00 decision time)
        $this->createContext('binance', 'BTCUSDT', '2026-10-25 17:44:00');

        // 2. Signal generated at 17:45:00, persisted at 17:48:00, expires at 18:15:00
        $signalTime = new DateTimeImmutable('2026-10-25 17:45:00', new DateTimeZone('UTC'));
        $persistedTime = new DateTimeImmutable('2026-10-25 17:48:00', new DateTimeZone('UTC'));
        $expiresAt = new DateTimeImmutable('2026-10-25 18:15:00', new DateTimeZone('UTC'));

        $signal = $this->createSignal(
            exchange: 'binance',
            strategyId: 'momentum',
            symbol: 'BTCUSDT',
            decisionTime: $signalTime,
            expiresAt: $expiresAt,
            createdAt: $persistedTime,
            entryLow: '65000',
            entryHigh: '65100',
            stopLoss: '64000',
            takeProfit1: '66000',
            takeProfit2: '67000'
        );

        // 3. Historical 5m candle that closed at 18:10:00 (which hit the entry price range)
        $this->createCandle('binance', 'BTCUSDT', '2026-10-25 18:05:00', '65020', '65080', '64990', '65050');

        // 4. Processing runs in forward mode at 18:15:10 (signal already expired at 18:15:00)
        $this->travelTo(new DateTimeImmutable('2026-10-25 18:15:10', new DateTimeZone('UTC')));

        $processor = $this->app->make(PaperProcessingService::class);
        $processor->process($account->id, 'BTCUSDT');

        // 5. Invariant verification:
        // Position count MUST be 0 - absolutely NO retrospective fill!
        self::assertSame(0, VirtualPositionRecord::query()->where('paper_account_id', $account->id)->count());

        // Signal state MUST be EXPIRED, reason signal_expired_before_ingestion
        $state = PaperSignalStateRecord::query()->where('paper_account_id', $account->id)->where('signal_id', $signal->id)->first();
        self::assertNotNull($state);
        self::assertSame(SignalLifecycle::Expired->value, $state->status);
        self::assertSame('signal_expired_before_ingestion', $state->reason);

        // Risk decision must be recorded as rejected due to expired signal
        $decision = RiskDecisionRecord::query()->where('paper_account_id', $account->id)->where('signal_id', $signal->id)->first();
        self::assertNotNull($decision);
        self::assertFalse($decision->allowed);
        self::assertContains('signal_expired_before_ingestion', $decision->reasons);

        // 6. Even if processor runs again at 18:19:11, nothing changes
        $this->travelTo(new DateTimeImmutable('2026-10-25 18:19:11', new DateTimeZone('UTC')));
        $processor->process($account->id, 'BTCUSDT');
        self::assertSame(0, VirtualPositionRecord::query()->where('paper_account_id', $account->id)->count());
    }

    /**
     * Invariant: A forward paper order in PENDING_ENTRY cannot be filled by a candle
     * that closed before or at the moment the order was created.
     */
    public function test_forward_paper_never_fills_on_candle_closed_before_state_creation(): void
    {
        $this->initializeAndActivateBinanceAccounts();

        $account = PaperAccountRecord::query()
            ->where('exchange_id', 'binance')
            ->where('is_primary', true)
            ->where('strategy_id', 'trend_pullback')
            ->firstOrFail();

        $this->createContext('binance', 'BTCUSDT', '2026-10-25 17:59:00');

        // Signal generated at 18:00:00, persisted and ingested at 18:02:00
        $decisionTime = new DateTimeImmutable('2026-10-25 18:00:00', new DateTimeZone('UTC'));
        $expiresAt = new DateTimeImmutable('2026-10-25 18:30:00', new DateTimeZone('UTC'));

        $this->travelTo(new DateTimeImmutable('2026-10-25 18:02:00', new DateTimeZone('UTC')));
        $signal = $this->createSignal(
            exchange: 'binance',
            strategyId: 'trend_pullback',
            symbol: 'BTCUSDT',
            decisionTime: $decisionTime,
            expiresAt: $expiresAt,
            createdAt: now('UTC')->toDateTimeImmutable(),
            entryLow: '65000',
            entryHigh: '65100',
            stopLoss: '64000',
            takeProfit1: '66000',
            takeProfit2: '67000'
        );

        // A candle that closed at 18:00:00 (before state creation at 18:02:00) with price in entry range
        $this->createCandle('binance', 'BTCUSDT', '2026-10-25 17:55:00', '65020', '65080', '64990', '65050');

        $processor = $this->app->make(PaperProcessingService::class);
        $processor->process($account->id, 'BTCUSDT');

        // Invariant: The 18:00:00 candle CANNOT fill this order because it closed before the order existed!
        self::assertSame(0, VirtualPositionRecord::query()->where('paper_account_id', $account->id)->count());

        $state = PaperSignalStateRecord::query()->where('paper_account_id', $account->id)->where('signal_id', $signal->id)->firstOrFail();
        self::assertSame(SignalLifecycle::PendingEntry->value, $state->status, (string) $state->reason);

        // Now subsequent 5m candle closing at 18:05:00 arrives (after state creation)
        $this->createCandle('binance', 'BTCUSDT', '2026-10-25 18:00:00', '65020', '65080', '64990', '65050');
        $this->travelTo(new DateTimeImmutable('2026-10-25 18:05:30', new DateTimeZone('UTC')));

        $processor->process($account->id, 'BTCUSDT');

        // Now the order CAN and DOES fill on the valid forward candle closing at 18:05:00
        self::assertSame(1, VirtualPositionRecord::query()->where('paper_account_id', $account->id)->count());
        $position = VirtualPositionRecord::query()->where('paper_account_id', $account->id)->firstOrFail();
        self::assertSame('2026-10-25 18:05:00', $position->opened_at->format('Y-m-d H:i:s'));
        self::assertSame(PositionStatus::Open->value, $position->status);
    }

    /**
     * Invariant: Forward paper trading ignores bars older than max_forward_fill_lag_seconds.
     */
    public function test_forward_paper_never_fills_on_candle_exceeding_max_forward_fill_lag(): void
    {
        $this->initializeAndActivateBinanceAccounts();

        $account = PaperAccountRecord::query()
            ->where('exchange_id', 'binance')
            ->where('is_primary', true)
            ->where('strategy_id', 'bollinger_mean_reversion')
            ->firstOrFail();

        $this->createContext('binance', 'BTCUSDT', '2026-10-25 17:59:00');

        $decisionTime = new DateTimeImmutable('2026-10-25 18:00:00', new DateTimeZone('UTC'));
        $expiresAt = new DateTimeImmutable('2026-10-25 18:30:00', new DateTimeZone('UTC'));

        $this->travelTo(new DateTimeImmutable('2026-10-25 18:01:00', new DateTimeZone('UTC')));
        $signal = $this->createSignal(
            exchange: 'binance',
            strategyId: 'bollinger_mean_reversion',
            symbol: 'BTCUSDT',
            decisionTime: $decisionTime,
            expiresAt: $expiresAt,
            createdAt: now('UTC')->toDateTimeImmutable(),
            entryLow: '65000',
            entryHigh: '65100',
            stopLoss: '64000',
            takeProfit1: '66000',
            takeProfit2: '67000'
        );

        $processor = $this->app->make(PaperProcessingService::class);
        $processor->process($account->id, 'BTCUSDT');

        $state = PaperSignalStateRecord::query()->where('paper_account_id', $account->id)->where('signal_id', $signal->id)->firstOrFail();
        self::assertSame(SignalLifecycle::PendingEntry->value, $state->status);

        // Candle closed at 18:05:00
        $this->createCandle('binance', 'BTCUSDT', '2026-10-25 18:00:00', '65020', '65080', '64990', '65050');

        // But processor runs at 18:12:00 (lag = 420 seconds > 300s max_forward_fill_lag_seconds)
        $this->travelTo(new DateTimeImmutable('2026-10-25 18:12:00', new DateTimeZone('UTC')));

        $processor->process($account->id, 'BTCUSDT');

        // Candle was too old, fill must NOT occur!
        self::assertSame(0, VirtualPositionRecord::query()->where('paper_account_id', $account->id)->count());
        $state->refresh();
        self::assertSame(SignalLifecycle::PendingEntry->value, $state->status);
    }

    /**
     * Invariant: If a signal expires while in PENDING_ENTRY, it transitions to EXPIRED
     * and cannot be filled by subsequent market bars.
     */
    public function test_signal_expired_during_pending_entry_is_marked_expired(): void
    {
        $this->initializeAndActivateBinanceAccounts();

        $account = PaperAccountRecord::query()
            ->where('exchange_id', 'binance')
            ->where('is_primary', true)
            ->where('strategy_id', 'regime_switching')
            ->firstOrFail();

        $this->createContext('binance', 'BTCUSDT', '2026-10-25 17:59:00');

        $decisionTime = new DateTimeImmutable('2026-10-25 18:00:00', new DateTimeZone('UTC'));
        $expiresAt = new DateTimeImmutable('2026-10-25 18:15:00', new DateTimeZone('UTC'));

        $this->travelTo(new DateTimeImmutable('2026-10-25 18:01:00', new DateTimeZone('UTC')));
        $signal = $this->createSignal(
            exchange: 'binance',
            strategyId: 'regime_switching',
            symbol: 'BTCUSDT',
            decisionTime: $decisionTime,
            expiresAt: $expiresAt,
            createdAt: now('UTC')->toDateTimeImmutable(),
            entryLow: '65000',
            entryHigh: '65100',
            stopLoss: '64000',
            takeProfit1: '66000',
            takeProfit2: '67000'
        );

        $processor = $this->app->make(PaperProcessingService::class);
        $processor->process($account->id, 'BTCUSDT');

        $state = PaperSignalStateRecord::query()->where('paper_account_id', $account->id)->where('signal_id', $signal->id)->firstOrFail();
        self::assertSame(SignalLifecycle::PendingEntry->value, $state->status);

        // Candle closing at 18:20:00 (past 18:15:00 expiration)
        $this->createCandle('binance', 'BTCUSDT', '2026-10-25 18:00:00', '65500', '65600', '65400', '65550');
        $this->createCandle('binance', 'BTCUSDT', '2026-10-25 18:05:00', '65500', '65600', '65400', '65550');
        $this->createCandle('binance', 'BTCUSDT', '2026-10-25 18:10:00', '65500', '65600', '65400', '65550');
        $this->createCandle('binance', 'BTCUSDT', '2026-10-25 18:15:00', '65020', '65080', '64990', '65050');

        $this->travelTo(new DateTimeImmutable('2026-10-25 18:20:30', new DateTimeZone('UTC')));

        $processor->process($account->id, 'BTCUSDT');

        $state->refresh();
        self::assertSame(SignalLifecycle::Expired->value, $state->status);
        self::assertSame('entry_window_expired', $state->reason);
        self::assertSame(0, VirtualPositionRecord::query()->where('paper_account_id', $account->id)->count());
    }

    /**
     * Invariant: In forward mode, RiskDecisionRecord decided_at reflects the actual evaluation time,
     * not a backdated candle close time.
     */
    public function test_risk_decision_decided_at_reflects_actual_evaluation_timestamp_in_forward_mode(): void
    {
        $this->initializeAndActivateBinanceAccounts();

        $account = PaperAccountRecord::query()
            ->where('exchange_id', 'binance')
            ->where('is_primary', true)
            ->where('strategy_id', 'momentum')
            ->firstOrFail();

        $this->createContext('binance', 'BTCUSDT', '2026-10-25 17:59:00');

        $decisionTime = new DateTimeImmutable('2026-10-25 18:00:00', new DateTimeZone('UTC'));
        $expiresAt = new DateTimeImmutable('2026-10-25 18:30:00', new DateTimeZone('UTC'));

        $signal = $this->createSignal(
            exchange: 'binance',
            strategyId: 'momentum',
            symbol: 'BTCUSDT',
            decisionTime: $decisionTime,
            expiresAt: $expiresAt,
            createdAt: new DateTimeImmutable('2026-10-25 18:01:30', new DateTimeZone('UTC')),
            entryLow: '65000',
            entryHigh: '65100',
            stopLoss: '64000',
            takeProfit1: '66000',
            takeProfit2: '67000'
        );

        $this->travelTo(new DateTimeImmutable('2026-10-25 18:02:45', new DateTimeZone('UTC')));
        $processor = $this->app->make(PaperProcessingService::class);
        $processor->process($account->id, 'BTCUSDT');

        $riskDecision = RiskDecisionRecord::query()->where('paper_account_id', $account->id)->where('signal_id', $signal->id)->firstOrFail();

        // In forward mode, decided_at must match current evaluation time (18:02:45), NOT backdated to 18:00:00
        self::assertSame('2026-10-25 18:02:45', $riskDecision->decided_at->format('Y-m-d H:i:s'));
    }

    /**
     * Invariant: Historical mode (--at parameter specified) preserves deterministic backtest replay
     * without forward lag or forward wall-clock rejections.
     */
    public function test_historical_mode_preserves_deterministic_backtest_replay(): void
    {
        $historicalStart = new DateTimeImmutable('2026-06-01 09:00:00', new DateTimeZone('UTC'));
        $this->initializeAndActivateBinanceAccounts($historicalStart);

        $account = PaperAccountRecord::query()
            ->where('exchange_id', 'binance')
            ->where('is_primary', true)
            ->where('strategy_id', 'donchian_atr_breakout')
            ->firstOrFail();

        $historicalDecision = new DateTimeImmutable('2026-06-01 10:00:00', new DateTimeZone('UTC'));
        $historicalExpiry = new DateTimeImmutable('2026-06-01 10:30:00', new DateTimeZone('UTC'));

        $this->createContext('binance', 'BTCUSDT', '2026-06-01 09:59:00');

        $signal = $this->createSignal(
            exchange: 'binance',
            strategyId: 'donchian_atr_breakout',
            symbol: 'BTCUSDT',
            decisionTime: $historicalDecision,
            expiresAt: $historicalExpiry,
            createdAt: $historicalDecision,
            entryLow: '65000',
            entryHigh: '65100',
            stopLoss: '64000',
            takeProfit1: '66000',
            takeProfit2: '67000'
        );

        // Historical bar at 10:05:00
        $this->createCandle('binance', 'BTCUSDT', '2026-06-01 10:00:00', '65020', '65080', '64990', '65050');

        $processor = $this->app->make(PaperProcessingService::class);
        // Explicit --at replay at 10:10:00
        $replayTime = new DateTimeImmutable('2026-06-01 10:10:00', new DateTimeZone('UTC'));
        $result = $processor->process($account->id, 'BTCUSDT', at: $replayTime);

        self::assertGreaterThanOrEqual(1, $result['events_processed']);
        self::assertSame(1, VirtualPositionRecord::query()->where('paper_account_id', $account->id)->count());
        $position = VirtualPositionRecord::query()->where('paper_account_id', $account->id)->firstOrFail();
        self::assertSame('2026-06-01 10:05:00', $position->opened_at->format('Y-m-d H:i:s'));
    }

    /**
     * Invariant: Idempotency under multiple sequential / duplicate scheduler runs.
     */
    public function test_idempotent_processing_under_duplicate_runs_and_retries(): void
    {
        $this->initializeAndActivateBinanceAccounts();

        $account = PaperAccountRecord::query()
            ->where('exchange_id', 'binance')
            ->where('is_primary', true)
            ->where('strategy_id', 'support_resistance_bounce')
            ->firstOrFail();

        $this->createContext('binance', 'BTCUSDT', '2026-10-25 17:59:00');

        $decisionTime = new DateTimeImmutable('2026-10-25 18:00:00', new DateTimeZone('UTC'));
        $expiresAt = new DateTimeImmutable('2026-10-25 18:30:00', new DateTimeZone('UTC'));

        $this->travelTo(new DateTimeImmutable('2026-10-25 18:01:00', new DateTimeZone('UTC')));
        $signal = $this->createSignal(
            exchange: 'binance',
            strategyId: 'support_resistance_bounce',
            symbol: 'BTCUSDT',
            decisionTime: $decisionTime,
            expiresAt: $expiresAt,
            createdAt: now('UTC')->toDateTimeImmutable(),
            entryLow: '65000',
            entryHigh: '65100',
            stopLoss: '64000',
            takeProfit1: '66000',
            takeProfit2: '67000'
        );

        $processor = $this->app->make(PaperProcessingService::class);
        $processor->process($account->id, 'BTCUSDT');

        $this->createCandle('binance', 'BTCUSDT', '2026-10-25 18:00:00', '65020', '65080', '64990', '65050');
        $this->travelTo(new DateTimeImmutable('2026-10-25 18:05:15', new DateTimeZone('UTC')));

        // Run 1
        $processor->process($account->id, 'BTCUSDT');
        self::assertSame(1, VirtualPositionRecord::query()->where('paper_account_id', $account->id)->count());

        // Run 2 (duplicate scheduler run)
        $processor->process($account->id, 'BTCUSDT');
        self::assertSame(1, VirtualPositionRecord::query()->where('paper_account_id', $account->id)->count());

        // Run 3
        $processor->process($account->id, 'BTCUSDT');
        self::assertSame(1, VirtualPositionRecord::query()->where('paper_account_id', $account->id)->count());
    }

    /**
     * Invariant: Temporal integrity is enforced across ALL 13 primary strategies on Binance.
     */
    public function test_all_thirteen_binance_strategies_enforce_temporal_integrity(): void
    {
        $this->initializeAndActivateBinanceAccounts();

        $this->createContext('binance', 'BTCUSDT', '2026-10-25 17:44:00');

        $primaryStrategies = (array) config('paper.primary_strategies', []);
        self::assertCount(13, $primaryStrategies);

        $signalTime = new DateTimeImmutable('2026-10-25 17:45:00', new DateTimeZone('UTC'));
        $persistedTime = new DateTimeImmutable('2026-10-25 17:48:00', new DateTimeZone('UTC'));
        $expiresAt = new DateTimeImmutable('2026-10-25 18:15:00', new DateTimeZone('UTC'));

        // Candle closing at 18:10:00
        $this->createCandle('binance', 'BTCUSDT', '2026-10-25 18:05:00', '65020', '65080', '64990', '65050');

        foreach ($primaryStrategies as $strategyId) {
            $this->createSignal(
                exchange: 'binance',
                strategyId: $strategyId,
                symbol: 'BTCUSDT',
                decisionTime: $signalTime,
                expiresAt: $expiresAt,
                createdAt: $persistedTime,
                entryLow: '65000',
                entryHigh: '65100',
                stopLoss: '64000',
                takeProfit1: '66000',
                takeProfit2: '67000'
            );
        }

        // Processing runs at 18:15:10 (all signals expired)
        $this->travelTo(new DateTimeImmutable('2026-10-25 18:15:10', new DateTimeZone('UTC')));

        $processor = $this->app->make(PaperProcessingService::class);
        foreach ($primaryStrategies as $strategyId) {
            $account = PaperAccountRecord::query()
                ->where('exchange_id', 'binance')
                ->where('is_primary', true)
                ->where('strategy_id', $strategyId)
                ->firstOrFail();

            $processor->process($account->id, 'BTCUSDT');

            // Not a single strategy opens a retrospective position!
            self::assertSame(0, VirtualPositionRecord::query()->where('paper_account_id', $account->id)->count(), "Strategy {$strategyId} opened an illegal retrospective position!");
        }

        self::assertSame(0, VirtualPositionRecord::query()->whereIn('paper_account_id', PaperAccountRecord::query()->where('exchange_id', 'binance')->where('is_primary', true)->select('id'))->count());
        self::assertSame(13, PaperSignalStateRecord::query()->where('status', SignalLifecycle::Expired->value)->count());
    }

    private function createSignal(
        string $exchange,
        string $strategyId,
        string $symbol,
        DateTimeImmutable $decisionTime,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $createdAt,
        string $entryLow,
        string $entryHigh,
        string $stopLoss,
        string $takeProfit1,
        string $takeProfit2
    ): SignalRecord {
        $strategy = $this->app->make(StrategyRegistry::class)->get($strategyId);

        $signal = SignalRecord::query()->create([
            'id' => Uuid::uuid5(Uuid::NAMESPACE_URL, "temporal-test:{$exchange}:{$strategyId}:{$symbol}:{$decisionTime->format('U')}")->toString(),
            'exchange' => $exchange,
            'category' => 'linear',
            'symbol' => $symbol,
            'timeframe' => '15',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 90,
            'decision_time' => $decisionTime,
            'candle_close_time' => $decisionTime,
            'entry_low' => $entryLow,
            'entry_high' => $entryHigh,
            'stop_loss' => $stopLoss,
            'take_profit_1' => $takeProfit1,
            'take_profit_2' => $takeProfit2,
            'risk_reward_1' => '1.5',
            'risk_reward_2' => '2.5',
            'model_risk_percent' => '0.50',
            'expires_at' => $expiresAt,
            'maximum_holding_minutes' => 240,
            'reasons' => ['temporal_test_trigger'],
            'risks' => [],
            'indicator_snapshot' => ['15m' => ['atr_14' => '100', 'close' => '65000']],
            'contract' => ['research_mode' => true],
            'strategy_version' => $strategy->version(),
            'schema_version' => '1.0.0',
            'strategy_id' => $strategyId,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        if (in_array($signal->side, ['BUY', 'SELL'], true)) {
            $this->seedValidatedPaperEvidence($signal);
        }

        return $signal;
    }

    private function createContext(string $exchange, string $symbol, string $observedAt): void
    {
        MarketContextRecord::query()->create([
            'exchange' => $exchange,
            'category' => 'linear',
            'symbol' => $symbol,
            'observed_at' => new DateTimeImmutable($observedAt, new DateTimeZone('UTC')),
            'bid' => '65040',
            'ask' => '65060',
            'turnover_24h' => '100000000',
            'tick_size' => '0.1',
            'qty_step' => '0.001',
            'min_order_qty' => '0.001',
            'max_order_qty' => '1000',
            'min_notional_value' => '5',
            'funding_rate' => '0.0001',
            'open_interest' => '1000000',
        ]);
    }

    private function createCandle(
        string $exchange,
        string $symbol,
        string $openTime,
        string $open,
        string $high,
        string $low,
        string $close
    ): void {
        CandleRecord::query()->create([
            'exchange' => $exchange,
            'category' => 'linear',
            'symbol' => $symbol,
            'interval' => '5',
            'open_time' => new DateTimeImmutable($openTime, new DateTimeZone('UTC')),
            'open' => $open,
            'high' => $high,
            'low' => $low,
            'close' => $close,
            'volume' => '100',
            'turnover' => '10000',
            'is_closed' => true,
        ]);
    }
}
