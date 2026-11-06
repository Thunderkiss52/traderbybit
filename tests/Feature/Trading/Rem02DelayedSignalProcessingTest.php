<?php

declare(strict_types=1);

namespace Tests\Feature\Trading;

use App\PaperTrading\Application\PaperProcessingService;
use App\PaperTrading\Domain\SignalLifecycle;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperSignalStateRecord;
use App\PaperTrading\Infrastructure\Persistence\RiskDecisionRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use App\Trading\Application\TradingPipelineEventService;
use App\Trading\Jobs\ProcessPaperPipelineJob;
use Carbon\Carbon;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class Rem02DelayedSignalProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    /**
     * Test the 26 Bybit delayed signal scenario from the 24-hour audit:
     * - decision_time around 10:00 UTC
     * - expires_at around 10:30 UTC
     * - created_at around 11:03 UTC (created_at > expires_at)
     * Verify it is NOT silently dropped, receives status = EXPIRED in paper_signal_states,
     * tracks latency metrics, and does not open positions.
     */
    public function test_delayed_bybit_signal_receives_auditable_expired_state(): void
    {
        $account = $this->createBybitAccount('baseline');

        $decisionTime = new DateTimeImmutable('2026-11-06T10:00:00Z');
        $expiresAt = new DateTimeImmutable('2026-11-06T10:30:00Z');
        $createdAt = new DateTimeImmutable('2026-11-06T11:03:00Z');
        $processingTime = new DateTimeImmutable('2026-11-06T11:05:00Z');
        Carbon::setTestNow($processingTime);

        // Insert a signal with created_at > expires_at (the defect scenario)
        $signalId = Uuid::uuid4()->toString();
        DB::table('signals')->insert([
            'id' => $signalId,
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15',
            'strategy_id' => 'baseline',
            'strategy_version' => app(\App\Strategy\Application\StrategyRegistry::class)->get('baseline')->version(),
            'decision_time' => $decisionTime,
            'candle_close_time' => $decisionTime,
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 85,
            'entry_low' => '68000',
            'entry_high' => '68100',
            'stop_loss' => '67500',
            'take_profit_1' => '69000',
            'take_profit_2' => '69500',
            'risk_reward_1' => '1.5',
            'risk_reward_2' => '2.5',
            'model_risk_percent' => '0.50',
            'expires_at' => $expiresAt,
            'maximum_holding_minutes' => 240,
            'reasons' => json_encode(['trend_aligned'], JSON_THROW_ON_ERROR),
            'risks' => json_encode([], JSON_THROW_ON_ERROR),
            'indicator_snapshot' => json_encode(['1h' => null], JSON_THROW_ON_ERROR),
            'contract' => json_encode(['id' => $signalId], JSON_THROW_ON_ERROR),
            'schema_version' => '1.0.0',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $service = app(PaperProcessingService::class);
        $result = $service->process($account->id, 'BTCUSDT', $processingTime, allowNewEntries: true);

        // 1. Verify paper_signal_states was created with EXPIRED
        $state = PaperSignalStateRecord::query()->where('signal_id', $signalId)->first();
        self::assertNotNull($state, 'paper_signal_states must exist for delayed signal');
        self::assertSame(SignalLifecycle::Expired->value, $state->status);
        self::assertSame('signal_expired_before_ingestion', $state->reason);

        // 2. Verify RiskDecisionRecord was persisted with latency tracking
        $risk = RiskDecisionRecord::query()->where('signal_id', $signalId)->first();
        self::assertNotNull($risk, 'RiskDecisionRecord must be persisted');
        self::assertFalse($risk->allowed);
        self::assertContains('signal_expired_before_ingestion', $risk->reasons);
        self::assertArrayHasKey('decision_to_processing_latency_ms', $risk->snapshot);
        // 10:00 to 11:05 = 65 minutes = 3,900,000 ms
        self::assertSame(3900000, $risk->snapshot['decision_to_processing_latency_ms']);

        // 3. Verify zero positions opened
        self::assertSame(0, DB::table('virtual_positions')->where('paper_account_id', $account->id)->count());
        self::assertSame(0, DB::table('virtual_executions')->count());

        // 4. Exactly-once: repeat invocation does not duplicate states
        $service->process($account->id, 'BTCUSDT', $processingTime, allowNewEntries: true);
        self::assertSame(1, PaperSignalStateRecord::query()->where('signal_id', $signalId)->count());
    }

    /**
     * Verify scheduled PaperProcessingService on expired signals marks them
     * as EXPIRED in paper_signal_states without opening positions.
     */
    public function test_stale_pipeline_batch_safely_expires_pending_signals(): void
    {
        Carbon::setTestNow('2026-11-06T09:00:00Z');
        $account = $this->createBybitAccount('trend_pullback');

        // Event close at 10:00, but now is 11:00 (> 300s freshness window)
        $closeAt = new DateTimeImmutable('2026-11-06T10:00:00Z');
        $expiresAt = new DateTimeImmutable('2026-11-06T10:30:00Z');
        Carbon::setTestNow('2026-11-06T11:00:00Z');

        $signalId = Uuid::uuid4()->toString();
        DB::table('signals')->insert([
            'id' => $signalId,
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => app(\App\Strategy\Application\StrategyRegistry::class)->get('trend_pullback')->version(),
            'decision_time' => $closeAt,
            'candle_close_time' => $closeAt,
            'side' => 'BUY',
            'quality_class' => 'B',
            'score' => 65,
            'entry_low' => '68000',
            'entry_high' => '68100',
            'stop_loss' => '67500',
            'take_profit_1' => '69000',
            'take_profit_2' => '69500',
            'risk_reward_1' => '1.5',
            'risk_reward_2' => '2.5',
            'model_risk_percent' => '0.25',
            'expires_at' => $expiresAt,
            'maximum_holding_minutes' => 240,
            'reasons' => json_encode(['trend_pullback'], JSON_THROW_ON_ERROR),
            'risks' => json_encode([], JSON_THROW_ON_ERROR),
            'indicator_snapshot' => json_encode(['1h' => null], JSON_THROW_ON_ERROR),
            'contract' => json_encode(['id' => $signalId], JSON_THROW_ON_ERROR),
            'schema_version' => '1.0.0',
            'created_at' => $closeAt,
            'updated_at' => $closeAt,
        ]);

        $paperService = app(PaperProcessingService::class);
        $job = new \App\PaperTrading\Jobs\ProcessPaperTradingJob($account->id, 'BTCUSDT');
        $job->handle($paperService);

        // Verify signal received terminal EXPIRED state
        $state = PaperSignalStateRecord::query()->where('signal_id', $signalId)->first();
        self::assertNotNull($state);
        self::assertSame(SignalLifecycle::Expired->value, $state->status);

        // Zero positions opened
        self::assertSame(0, DB::table('virtual_positions')->where('paper_account_id', $account->id)->count());
    }

    private function createBybitAccount(string $strategyId): PaperAccountRecord
    {
        $registry = app(\App\Strategy\Application\StrategyRegistry::class);
        $strategy = $registry->get($strategyId);
        $params = $strategy->parameters();
        $hash = \App\Strategy\Application\StrategyRegistry::hash($params);
        $id = $strategyId === 'baseline'
            ? Uuid::uuid5(Uuid::NAMESPACE_URL, 'traderbybit:paper:default')->toString()
            : Uuid::uuid5(Uuid::NAMESPACE_URL, "traderbybit:paper:primary:{$strategyId}")->toString();

        return PaperAccountRecord::query()->create([
            'id' => $id,
            'name' => "Bybit {$strategyId}",
            'exchange_id' => 'bybit',
            'strategy_id' => $strategyId,
            'primary_strategy_id' => $strategyId,
            'strategy_version' => $strategy->version(),
            'configuration_hash' => $hash,
            'strategy_parameters' => $params,
            'is_primary' => true,
            'mode' => 'STRATEGY_FORWARD_PAPER',
            'status' => 'ACTIVE',
            'starting_balance' => '10000.000000000000',
            'current_equity' => '10000.000000000000',
            'peak_equity' => '10000.000000000000',
            'realized_pnl' => '0.000000000000',
            'unrealized_pnl' => '0.000000000000',
            'reserved_risk' => '0.000000000000',
            'maximum_drawdown_percent' => '0.00000000',
            'current_drawdown_percent' => '0.00000000',
        ]);
    }
}
