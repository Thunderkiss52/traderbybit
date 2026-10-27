<?php

declare(strict_types=1);

namespace Tests\Feature\Trading;

use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Application\PaperProcessingService;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\Trading\Application\TradingPipelineEventService;
use App\Trading\Application\TradingPipelineRecoveryService;
use App\Trading\Infrastructure\Persistence\TradingPipelineEventRecord;
use App\Trading\Jobs\ProcessPaperPipelineJob;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class TradingPipelineStageATest extends TestCase
{
    use RefreshDatabase;

    public function test_record_paper_processed_terminalizes_signals_evaluated_to_completed(): void
    {
        Queue::fake();
        config(['trading.pipeline_mode' => 'EVENT_DRIVEN']);

        $service = app(TradingPipelineEventService::class);
        $closeAt = new DateTimeImmutable('now');

        $sigRecord = $service->recordSignalsEvaluated('bybit', 'BTCUSDT', $closeAt, 2);
        self::assertSame(TradingPipelineEventRecord::STATUS_DISPATCHED, $sigRecord->status);

        $paperRecord = $service->recordPaperProcessed('bybit', 'BTCUSDT', $closeAt, 5, [
            'failures_count' => 0,
            'eligible_accounts' => 8,
            'processed_accounts' => 8,
        ]);

        self::assertSame(TradingPipelineEventRecord::STATUS_COMPLETED, $paperRecord->status);
        self::assertSame(TradingPipelineEventRecord::STATUS_COMPLETED, $sigRecord->fresh()->status);
        self::assertNotNull($sigRecord->fresh()->completed_at);
    }

    public function test_record_paper_processed_terminalizes_5m_candle_closed_to_completed(): void
    {
        Queue::fake();
        config(['trading.pipeline_mode' => 'EVENT_DRIVEN']);

        $service = app(TradingPipelineEventService::class);
        $closeAt = new DateTimeImmutable('now');

        $candleRecord = $service->recordCandleClosed('bybit', 'BTCUSDT', '5', $closeAt);
        self::assertSame(TradingPipelineEventRecord::STATUS_DISPATCHED, $candleRecord->status);

        $paperRecord = $service->recordPaperProcessed('bybit', 'BTCUSDT', $closeAt, 3, [
            'failures_count' => 0,
            'eligible_accounts' => 8,
            'processed_accounts' => 8,
        ]);

        self::assertSame(TradingPipelineEventRecord::STATUS_COMPLETED, $paperRecord->status);
        self::assertSame(TradingPipelineEventRecord::STATUS_COMPLETED, $candleRecord->fresh()->status);
        self::assertNotNull($candleRecord->fresh()->completed_at);
    }

    public function test_record_paper_processed_marks_partial_on_failures(): void
    {
        Queue::fake();
        config(['trading.pipeline_mode' => 'EVENT_DRIVEN']);

        $service = app(TradingPipelineEventService::class);
        $closeAt = new DateTimeImmutable('now');

        $sigRecord = $service->recordSignalsEvaluated('bybit', 'ETHUSDT', $closeAt, 1);
        self::assertSame(TradingPipelineEventRecord::STATUS_DISPATCHED, $sigRecord->status);

        $paperRecord = $service->recordPaperProcessed('bybit', 'ETHUSDT', $closeAt, 4, [
            'failures_count' => 1,
            'eligible_accounts' => 8,
            'processed_accounts' => 7,
            'failures' => [['account_id' => 'dummy', 'error' => 'Simulated failure']],
        ]);

        self::assertSame(TradingPipelineEventRecord::STATUS_PARTIAL, $paperRecord->status);
        self::assertSame(TradingPipelineEventRecord::STATUS_PARTIAL, $sigRecord->fresh()->status);
    }

    public function test_recovery_reconciles_unacknowledged_upstream_event_from_completed_paper(): void
    {
        Queue::fake();
        config(['trading.pipeline_mode' => 'EVENT_DRIVEN']);

        $service = app(TradingPipelineEventService::class);
        $recovery = app(TradingPipelineRecoveryService::class);
        $closeAt = (new DateTimeImmutable('now'))->modify('-45 seconds');

        $sigRecord = $service->recordSignalsEvaluated('bybit', 'BTCUSDT', $closeAt, 3);
        $sigRecord->update(['created_at' => CarbonImmutable::now('UTC')->subSeconds(25)]);

        // Create a paper processed event first
        $service->recordPaperProcessed('bybit', 'BTCUSDT', $closeAt, 3, [
            'failures_count' => 0,
            'eligible_accounts' => 8,
            'processed_accounts' => 8,
        ]);

        // Now simulate an upstream signals evaluated record that was left unacknowledged (DISPATCHED)
        DB::table('trading_pipeline_events')->where('id', $sigRecord->id)->update([
            'status' => TradingPipelineEventRecord::STATUS_DISPATCHED,
            'completed_at' => null,
            'created_at' => CarbonImmutable::now('UTC')->subSeconds(25),
        ]);

        $report = $recovery->recover();

        self::assertGreaterThanOrEqual(1, $report['recovered_events']);
        // Must NOT re-dispatch ProcessPaperPipelineJob during recovery (count must remain 1 from initial creation)
        Queue::assertPushed(ProcessPaperPipelineJob::class, 1);
        self::assertSame(TradingPipelineEventRecord::STATUS_COMPLETED, $sigRecord->fresh()->status);
    }

    public function test_binance_provenance_failure_on_one_account_does_not_abort_others(): void
    {
        config(['trading.pipeline_mode' => 'EVENT_DRIVEN']);
        config(['exchanges.binance.paper_execution_approved' => true]);
        config(['exchanges.binance.paper_taker_rate' => '0.0005']);
        config(['exchanges.binance.paper_slippage_rate' => '0.0002']);

        // Create 2 primary binance accounts
        $accountService = app(PaperAccountService::class);
        $acc1 = $accountService->default();
        $acc1->update([
            'exchange_id' => 'binance',
            'is_primary' => true,
            'status' => 'ACTIVE',
            'primary_strategy_id' => 'trend_pullback',
            'strategy_id' => 'trend_pullback',
        ]);

        $acc2 = PaperAccountRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'name' => 'Binance Bad Provenance Account',
            'exchange_id' => 'binance',
            'is_primary' => true,
            'status' => 'ACTIVE',
            'primary_strategy_id' => 'invalid_strategy_never_configured',
            'strategy_id' => 'invalid_strategy_never_configured',
            'starting_balance' => '10000',
            'current_equity' => '10000',
            'peak_equity' => '10000',
        ]);

        $closeAt = new DateTimeImmutable('now');
        $job = new ProcessPaperPipelineJob('binance', 'BTCUSDT', $closeAt->format(DATE_ATOM));

        // Execute job directly
        $job->handle(app(TradingPipelineEventService::class), app(PaperProcessingService::class));

        // Check trading pipeline event status
        $closeIso = $closeAt->format(DATE_ATOM);
        $paperEvent = TradingPipelineEventRecord::query()
            ->where('event_key', "binance:BTCUSDT:{$closeIso}:".TradingPipelineEventRecord::STAGE_PAPER_PROCESSED)
            ->first();

        self::assertNotNull($paperEvent);
        // Because acc2 failed provenance check, status should be PARTIAL
        self::assertSame(TradingPipelineEventRecord::STATUS_PARTIAL, $paperEvent->status);
        $payload = is_string($paperEvent->payload) ? json_decode($paperEvent->payload, true) : (array) $paperEvent->payload;
        self::assertSame(1, $payload['failures_count']);
        self::assertSame(1, $payload['processed_accounts']);
    }

    public function test_process_paper_pipeline_job_supports_targeted_retry_with_only_account_ids(): void
    {
        config(['trading.pipeline_mode' => 'EVENT_DRIVEN']);

        $accountService = app(PaperAccountService::class);
        $acc1 = $accountService->default();
        $acc1->update([
            'exchange_id' => 'bybit',
            'is_primary' => true,
            'status' => 'ACTIVE',
        ]);

        $acc2 = PaperAccountRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'name' => 'Bybit Account 2',
            'exchange_id' => 'bybit',
            'is_primary' => true,
            'status' => 'ACTIVE',
            'starting_balance' => '10000',
            'current_equity' => '10000',
            'peak_equity' => '10000',
            'strategy_id' => 'trend_pullback',
        ]);

        $closeAt = new DateTimeImmutable('now');
        // Targeted run for only $acc2
        $job = new ProcessPaperPipelineJob('bybit', 'BTCUSDT', $closeAt->format(DATE_ATOM), [$acc2->id]);
        $job->handle(app(TradingPipelineEventService::class), app(PaperProcessingService::class));

        $closeIso = $closeAt->format(DATE_ATOM);
        $paperEvent = TradingPipelineEventRecord::query()
            ->where('event_key', "bybit:BTCUSDT:{$closeIso}:".TradingPipelineEventRecord::STAGE_PAPER_PROCESSED)
            ->first();

        self::assertNotNull($paperEvent);
        $payload = is_string($paperEvent->payload) ? json_decode($paperEvent->payload, true) : (array) $paperEvent->payload;
        self::assertSame(1, $payload['eligible_accounts']);
        self::assertSame(1, $payload['processed_accounts']);
        self::assertSame(0, $payload['failures_count']);
    }
}
