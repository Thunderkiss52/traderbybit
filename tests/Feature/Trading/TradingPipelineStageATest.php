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
        self::assertSame($acc2->id, $payload['failures'][0]['account_id']);
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

    public function test_record_paper_processed_marks_failed_when_all_accounts_fail_and_terminalizes_upstream(): void
    {
        Queue::fake();
        config(['trading.pipeline_mode' => 'EVENT_DRIVEN']);

        $service = app(TradingPipelineEventService::class);
        $closeAt = new DateTimeImmutable('now');

        $candleRecord = $service->recordCandleClosed('bybit', 'BTCUSDT', '5', $closeAt);
        $sigRecord = $service->recordSignalsEvaluated('bybit', 'BTCUSDT', $closeAt, 2);

        self::assertSame(TradingPipelineEventRecord::STATUS_DISPATCHED, $candleRecord->status);
        self::assertSame(TradingPipelineEventRecord::STATUS_DISPATCHED, $sigRecord->status);

        // All accounts fail: eligible=5, failures=5, processed=0
        $paperRecord = $service->recordPaperProcessed('bybit', 'BTCUSDT', $closeAt, 0, [
            'failures_count' => 5,
            'eligible_accounts' => 5,
            'processed_accounts' => 0,
            'failures' => [['account_id' => 'dummy1', 'error' => 'Fatal risk rejection']],
        ]);

        // Paper event must be STATUS_FAILED
        self::assertSame(TradingPipelineEventRecord::STATUS_FAILED, $paperRecord->status);

        // Upstream SIGNALS_EVALUATED must be terminalized to STATUS_FAILED with completed_at set
        $sigFresh = $sigRecord->fresh();
        self::assertSame(TradingPipelineEventRecord::STATUS_FAILED, $sigFresh->status);
        self::assertNotNull($sigFresh->completed_at);

        // Upstream 5m CANDLE_CLOSED must also be terminalized to STATUS_FAILED with completed_at set
        $candleFresh = $candleRecord->fresh();
        self::assertSame(TradingPipelineEventRecord::STATUS_FAILED, $candleFresh->status);
        self::assertNotNull($candleFresh->completed_at);
    }

    public function test_recovery_retry_only_retries_failed_accounts_without_repeating_successes(): void
    {
        config(['trading.pipeline_mode' => 'EVENT_DRIVEN']);
        config(['exchanges.binance.paper_execution_approved' => true]);
        config(['exchanges.binance.paper_taker_rate' => '0.0005']);
        config(['exchanges.binance.paper_slippage_rate' => '0.0002']);

        // Account 1: valid Binance account with canonical primary strategy
        $accountService = app(PaperAccountService::class);
        $acc1 = $accountService->default();
        $acc1->update([
            'exchange_id' => 'binance',
            'is_primary' => true,
            'status' => 'ACTIVE',
            'primary_strategy_id' => 'trend_pullback',
            'strategy_id' => 'trend_pullback',
            'starting_balance' => '10000',
            'current_equity' => '10000',
            'peak_equity' => '10000',
        ]);

        // Account 2: Binance account with invalid provenance strategy that will fail
        $acc2 = PaperAccountRecord::query()->create([
            'id' => Uuid::uuid4()->toString(),
            'name' => 'Binance Recoverable Account',
            'exchange_id' => 'binance',
            'is_primary' => true,
            'status' => 'ACTIVE',
            'primary_strategy_id' => 'invalid_strategy_failing_provenance',
            'strategy_id' => 'invalid_strategy_failing_provenance',
            'starting_balance' => '10000',
            'current_equity' => '10000',
            'peak_equity' => '10000',
        ]);

        $closeAt = new DateTimeImmutable('now');
        $closeIso = $closeAt->format(DATE_ATOM);

        // Initial run across all accounts
        $job1 = new ProcessPaperPipelineJob('binance', 'BTCUSDT', $closeIso);
        $job1->handle(app(TradingPipelineEventService::class), app(PaperProcessingService::class));

        // Verify initial mixed batch resulted in STATUS_PARTIAL
        $eventKey = "binance:BTCUSDT:{$closeIso}:".TradingPipelineEventRecord::STAGE_PAPER_PROCESSED;
        $paperEvent1 = TradingPipelineEventRecord::query()->where('event_key', $eventKey)->first();
        self::assertNotNull($paperEvent1);
        self::assertSame(TradingPipelineEventRecord::STATUS_PARTIAL, $paperEvent1->status);

        // Snapshot acc1 state and ledger entries
        $acc1Snapshot = $acc1->fresh()->toArray();
        $acc1Journals = DB::table('trading_journal_events')->where('paper_account_id', $acc1->id)->get()->toArray();
        $acc1Positions = DB::table('virtual_positions')->where('paper_account_id', $acc1->id)->get()->toArray();

        // Now fix acc2 by giving it a valid canonical primary strategy
        $acc2->update([
            'primary_strategy_id' => 'donchian_atr_breakout',
            'strategy_id' => 'donchian_atr_breakout',
        ]);

        // Run targeted retry ONLY for the failed account ($acc2->id)
        $job2 = new ProcessPaperPipelineJob('binance', 'BTCUSDT', $closeIso, [$acc2->id]);
        $job2->handle(app(TradingPipelineEventService::class), app(PaperProcessingService::class));

        // 1. Assert successful account (acc1) was untouched (byte-identical ledger, no duplicate executions)
        self::assertEquals($acc1Snapshot, $acc1->fresh()->toArray());
        self::assertEquals($acc1Journals, DB::table('trading_journal_events')->where('paper_account_id', $acc1->id)->get()->toArray());
        self::assertEquals($acc1Positions, DB::table('virtual_positions')->where('paper_account_id', $acc1->id)->get()->toArray());

        // 2. Assert failed account (acc2) executed successfully on targeted retry
        $paperEvent2 = TradingPipelineEventRecord::query()->where('event_key', $eventKey)->first();
        self::assertNotNull($paperEvent2);
        self::assertSame(TradingPipelineEventRecord::STATUS_COMPLETED, $paperEvent2->status);
        $payload2 = is_string($paperEvent2->payload) ? json_decode($paperEvent2->payload, true) : (array) $paperEvent2->payload;
        self::assertSame(2, $payload2['eligible_accounts']);
        self::assertSame(2, $payload2['processed_accounts']);
        self::assertSame(0, $payload2['failures_count']);
    }

    public function test_expired_paper_delivery_acknowledges_upstream_without_fills(): void
    {
        Queue::fake();
        $this->freezeTime();
        $service = app(TradingPipelineEventService::class);
        $at = now()->subMinutes(6)->toDateTimeImmutable();
        $upstream = $service->recordSignalsEvaluated('bybit', 'BTCUSDT', $at, 1);
        (new ProcessPaperPipelineJob('bybit', 'BTCUSDT', $at->format(DATE_ATOM)))
            ->handle($service, app(PaperProcessingService::class));
        self::assertSame('STALE', $upstream->fresh()->status);
        self::assertNotNull($upstream->fresh()->completed_at);
        self::assertSame(0, DB::table('virtual_executions')->count());
    }

    public function test_recovery_dispatches_only_failed_account_identities_for_fresh_partial_batch(): void
    {
        Queue::fake();
        $this->freezeTime();
        $at = now()->subSeconds(40)->toDateTimeImmutable();
        $service = app(TradingPipelineEventService::class);
        $account = app(PaperAccountService::class)->default();
        $event = $service->recordPaperProcessed('bybit', 'BTCUSDT', $at, 0, [
            'failures_count' => 1, 'eligible_accounts' => 2, 'processed_accounts' => 1,
            'failures' => [['account_id' => $account->id, 'error' => 'recoverable']],
            'processed_account_ids' => ['successful-peer'],
        ]);
        $event->update(['created_at' => now()->subSeconds(25)]);
        app(TradingPipelineRecoveryService::class)->recover();
        Queue::assertPushed(ProcessPaperPipelineJob::class, fn ($job) => $job->onlyAccountIds === [$account->id]);
    }

    public function test_duplicate_delivery_preserves_completed_batch_and_never_replays_peer(): void
    {
        $this->freezeTime();
        $account = app(PaperAccountService::class)->default();
        $service = app(TradingPipelineEventService::class);
        $at = now()->toDateTimeImmutable();
        $job = new ProcessPaperPipelineJob('bybit', 'BTCUSDT', $at->format(DATE_ATOM));
        $job->handle($service, app(PaperProcessingService::class));
        $record = TradingPipelineEventRecord::query()->where('stage', 'PAPER_PROCESSED')->sole();
        $before = $record->toArray();
        $accountBefore = $account->refresh()->toArray();
        $job->handle($service, app(PaperProcessingService::class));
        self::assertSame($before, $record->refresh()->toArray());
        self::assertSame($accountBefore, $account->refresh()->toArray());
        self::assertSame(0, DB::table('virtual_executions')->count());
    }

    public function test_crash_checkpoint_recovers_only_unacknowledged_accounts(): void
    {
        Queue::fake();
        $this->freezeTime();
        $first = app(PaperAccountService::class)->default();
        $second = app(PaperAccountService::class)->primaryAccount('trend_pullback');
        $at = now()->subSeconds(40)->toDateTimeImmutable();
        $record = app(TradingPipelineEventService::class)->recordPaperProcessed('bybit', 'BTCUSDT', $at, 0, [
            'eligible_account_ids' => [$first->id, $second->id], 'processed_account_ids' => [$first->id],
            'eligible_accounts' => 2, 'processed_accounts' => 1, 'failures' => [], 'failures_count' => 0,
        ]);
        $record->update(['status' => 'PENDING', 'created_at' => now()->subSeconds(25)]);
        app(TradingPipelineRecoveryService::class)->recover();
        Queue::assertPushed(ProcessPaperPipelineJob::class, fn ($job) => $job->onlyAccountIds === [$second->id]);
        $before = $first->refresh()->toArray();
        (new ProcessPaperPipelineJob('bybit', 'BTCUSDT', $at->format(DATE_ATOM)))
            ->handle(app(TradingPipelineEventService::class), app(PaperProcessingService::class));
        self::assertSame($before, $first->refresh()->toArray());
        self::assertSame('COMPLETED', $record->refresh()->status);
        self::assertSame(2, $record->payload['processed_accounts']);
    }
}
