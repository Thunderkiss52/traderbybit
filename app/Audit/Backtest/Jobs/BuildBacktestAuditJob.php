<?php

declare(strict_types=1);

namespace App\Audit\Backtest\Jobs;

use App\Audit\Backtest\BacktestAuditArchiveBuilder;
use App\Audit\Backtest\BacktestAuditService;
use App\Backtest\Infrastructure\Persistence\BacktestRunRecord;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class BuildBacktestAuditJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // compose.yaml audit-worker --timeout=1800; leave a shutdown margin.
    // Redis queue retry_after=3700 must remain > both worker and job timeouts.
    public int $timeout = 1700;

    public int $tries = 2;

    public array $backoff = [60, 300];

    public function __construct(public readonly string $auditId) {}

    public function handle(BacktestAuditService $service, BacktestAuditArchiveBuilder $builder): void
    {
        $lock = Cache::lock('backtest-audit:'.$this->auditId, 3700);
        if (! $lock->get()) {
            return;
        }
        try {
            // A retry after a transient failure must actually retry instead of silently
            // returning because the previous attempt left the row FAILED.
            $claimed = DB::table('backtest_audit_jobs')->where('id', $this->auditId)
                ->whereIn('status', ['QUEUED', 'FAILED'])
                ->update(['status' => 'RUNNING', 'error_code' => null, 'updated_at' => now('UTC')]);
            if ($claimed !== 1) {
                return;
            }
            $job = DB::table('backtest_audit_jobs')->where('id', $this->auditId)->first();
            $run = BacktestRunRecord::query()->findOrFail($job->backtest_run_id);
            $result = $builder->build($run, $service->path($this->auditId));
            DB::table('backtest_audit_jobs')->where('id', $this->auditId)->update([
                'status' => 'COMPLETED',
                'row_counts' => json_encode($result['row_counts'], JSON_THROW_ON_ERROR),
                'archive_sha256' => $result['sha256'],
                'archive_bytes' => $result['bytes'],
                'expires_at' => now('UTC')->addHours((int) config('audit.retention_hours', 24)),
                'updated_at' => now('UTC'),
            ]);
        } catch (Throwable $exception) {
            // Never report READY on partial or failed ZIP creation.
            DB::table('backtest_audit_jobs')->where('id', $this->auditId)->update([
                'status' => 'FAILED',
                'error_code' => 'BACKTEST_AUDIT_BUILD_FAILED',
                'updated_at' => now('UTC'),
            ]);

            throw $exception;
        } finally {
            $lock->release();
        }
    }
}
