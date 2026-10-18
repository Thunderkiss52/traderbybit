<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\PaperTrading\Application\PaperAccountService;
use App\Research\Application\CounterfactualAnalyzerService;
use App\Research\Application\LedgerReconciliationService;
use App\Research\Infrastructure\Persistence\ReconciliationRunRecord;
use App\Research\Jobs\ProcessCounterfactualAnalysisJob;
use App\Research\Jobs\ReconcileLedgerJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

final class ScheduledResearchJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_process_counterfactual_analysis_job_runs_on_research_queue(): void
    {
        $job = new ProcessCounterfactualAnalysisJob(limit: 25);
        self::assertSame('research', $job->queue);
        self::assertSame(25, $job->limit);

        $analyzer = $this->app->make(CounterfactualAnalyzerService::class);
        $job->handle($analyzer);

        self::assertTrue(true);
    }

    public function test_reconcile_ledger_job_runs_on_audit_queue_and_creates_records(): void
    {
        // Seed default account
        $this->app->make(PaperAccountService::class)->default();

        $job = new ReconcileLedgerJob;
        self::assertSame('audit', $job->queue);

        $reconciler = $this->app->make(LedgerReconciliationService::class);
        $job->handle($reconciler);

        self::assertGreaterThanOrEqual(1, ReconciliationRunRecord::query()->count());
        $run = ReconciliationRunRecord::query()->first();
        self::assertNotNull($run);
        self::assertSame('PASSED', $run->status);
        self::assertSame(0, $run->discrepancy_count);
    }

    public function test_scheduled_events_include_counterfactual_and_ledger_jobs(): void
    {
        $events = collect(Schedule::events());

        $cfEvent = $events->first(fn ($e): bool => str_contains((string) $e->description, 'research:counterfactual-analysis'));
        self::assertNotNull($cfEvent, 'research:counterfactual-analysis must be scheduled');

        $reconcileEvent = $events->first(fn ($e): bool => str_contains((string) $e->description, 'audit:reconcile-ledger'));
        self::assertNotNull($reconcileEvent, 'audit:reconcile-ledger must be scheduled');
    }
}
