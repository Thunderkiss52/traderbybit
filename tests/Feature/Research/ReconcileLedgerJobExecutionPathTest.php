<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\PositionFundingEventRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Research\Application\LedgerReconciliationService;
use App\Research\Infrastructure\Persistence\ReconciliationRunRecord;
use App\Research\Jobs\ReconcileLedgerJob;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class ReconcileLedgerJobExecutionPathTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduler_registers_reconcile_ledger_job_on_expected_cron(): void
    {
        // 1. Resolve schedule and boot console routes
        require base_path('routes/console.php');
        $schedule = app(Schedule::class);

        $events = collect($schedule->events());
        $reconcileEvent = $events->first(function ($event): bool {
            return str_contains((string) $event->description, 'audit:reconcile-ledger')
                || str_contains((string) $event->command, 'ReconcileLedgerJob');
        });

        self::assertNotNull($reconcileEvent, 'Expected audit:reconcile-ledger event to be registered in scheduler.');
        self::assertSame('8,23,38,53 * * * *', $reconcileEvent->expression);
    }

    public function test_reconcile_ledger_job_dispatches_to_audit_queue(): void
    {
        Queue::fake();

        ReconcileLedgerJob::dispatch();

        Queue::assertPushedOn('audit', ReconcileLedgerJob::class);
    }

    public function test_compose_yaml_configures_audit_worker_for_audit_queue(): void
    {
        $composePath = base_path('compose.yaml');
        self::assertFileExists($composePath);

        $composeContent = file_get_contents($composePath);
        self::assertIsString($composeContent);

        self::assertStringContainsString('audit-worker:', $composeContent);
        self::assertStringContainsString('--queue=audit', $composeContent);
    }

    public function test_worker_execution_persists_actual_reconciliation_runs_with_ledger_integrity(): void
    {
        // Seed 2 real paper accounts
        $acc1 = PaperAccountRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'name' => 'trend_pullback',
            'starting_balance' => '10000.00',
            'current_equity' => '10050.00',
            'peak_equity' => '10050.00',
            'status' => 'ACTIVE',
        ]);

        $acc2 = PaperAccountRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'name' => 'bollinger_mean_reversion',
            'starting_balance' => '10000.00',
            'current_equity' => '9980.00',
            'peak_equity' => '10000.00',
            'status' => 'ACTIVE',
        ]);

        $signalId = (string) Uuid::uuid4();
        SignalRecord::query()->create([
            'id' => $signalId,
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'strategy_id' => 'trend_pullback',
            'strategy_version' => '1.0.0',
            'timeframe' => '15',
            'side' => 'BUY',
            'quality_class' => 'A',
            'score' => 85,
            'decision_time' => CarbonImmutable::now()->subHours(2),
            'candle_close_time' => CarbonImmutable::now()->subHours(2),
            'expires_at' => CarbonImmutable::now()->subHours(1),
            'maximum_holding_minutes' => 240,
            'reasons' => ['trend_bullish'],
            'risks' => [],
            'indicator_snapshot' => [],
            'contract' => [],
            'schema_version' => '1.0.0',
        ]);

        $pos = VirtualPositionRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'paper_account_id' => $acc1->id,
            'signal_id' => $signalId,
            'symbol' => 'BTCUSDT',
            'side' => 'BUY',
            'quality_class' => 'A',
            'status' => 'CLOSED',
            'entry_price' => '80000.00',
            'stop_loss' => '79000.00',
            'take_profit_1' => '82000.00',
            'take_profit_2' => '83000.00',
            'initial_quantity' => '0.1',
            'remaining_quantity' => '0.0',
            'qty_step' => '0.001',
            'risk_capital' => '100.00',
            'opened_at' => CarbonImmutable::now()->subHours(2),
            'closed_at' => CarbonImmutable::now()->subHour(),
            'maximum_holding_until' => CarbonImmutable::now()->addHours(2),
            'execution_model' => 'NEXT_CANDLE_OPEN',
            'execution_version' => '1.0.0',
            'strategy_version' => '1.3.0',
            'risk_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);

        VirtualExecutionRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'position_id' => $pos->id,
            'type' => 'ENTRY',
            'side' => 'BUY',
            'price' => '80000.00',
            'quantity' => '0.1',
            'commission' => '4.80',
            'gross_pnl' => '0.00',
            'slippage' => '0.00',
            'market_event_at' => CarbonImmutable::now()->subHours(2),
            'registered_at' => CarbonImmutable::now()->subHours(2),
            'price_source' => 'CANDLE_OPEN',
            'idempotency_key' => 'exec_entry_'.Uuid::uuid4(),
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);

        VirtualExecutionRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'position_id' => $pos->id,
            'type' => 'EXIT_TP1',
            'side' => 'SELL',
            'price' => '82000.00',
            'quantity' => '0.1',
            'commission' => '4.92',
            'gross_pnl' => '200.00',
            'slippage' => '0.00',
            'market_event_at' => CarbonImmutable::now()->subHour(),
            'registered_at' => CarbonImmutable::now()->subHour(),
            'price_source' => 'CANDLE_HIGH',
            'idempotency_key' => 'exec_exit_'.Uuid::uuid4(),
            'execution_version' => '1.0.0',
            'schema_version' => '1.0.0',
        ]);

        $marketFundingId = DB::table('market_funding_events')->insertGetId([
            'exchange' => 'bybit',
            'category' => 'linear',
            'symbol' => 'BTCUSDT',
            'funding_at' => CarbonImmutable::now()->subMinutes(90),
            'rate' => '0.0001',
            'mark_price' => '80000.00',
            'created_at' => CarbonImmutable::now()->subMinutes(90),
            'updated_at' => CarbonImmutable::now()->subMinutes(90),
        ]);

        PositionFundingEventRecord::query()->create([
            'id' => (string) Uuid::uuid4(),
            'position_id' => $pos->id,
            'market_funding_event_id' => $marketFundingId,
            'quantity' => '0.1',
            'mark_price' => '80000.00',
            'rate' => '0.0001',
            'amount' => '-0.80',
            'funding_at' => CarbonImmutable::now()->subMinutes(90),
        ]);

        // Execute job directly as worker would execute it
        $job = new ReconcileLedgerJob;
        $job->handle(app(LedgerReconciliationService::class));

        // Assert 2 real persisted reconciliation runs
        $runs = ReconciliationRunRecord::query()->orderBy('created_at')->get();
        self::assertCount(2, $runs, 'Expected exactly 2 persisted reconciliation runs for 2 paper accounts.');

        $run1 = $runs->firstWhere('paper_account_id', $acc1->id);
        self::assertNotNull($run1);
        self::assertEquals(10000.0, (float) $run1->starting_balance);
        self::assertEquals(9.72, (float) $run1->recalculated_fees);
        self::assertEquals(-0.80, (float) $run1->recalculated_funding);
        self::assertIsArray($run1->findings);
        self::assertSame(2, $run1->findings['total_executions_count']);
        self::assertSame(1, $run1->findings['total_funding_events_count']);
    }

    public function test_reconcile_ledger_job_handles_worker_failure_reporting(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Ledger reconciliation job failed on audit queue.'
                    && str_contains($context['error'] ?? '', 'Simulated worker memory limit or timeout');
            });

        $job = new ReconcileLedgerJob;
        $job->failed(new \RuntimeException('Simulated worker memory limit or timeout'));
    }
}
