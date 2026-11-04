<?php

declare(strict_types=1);

namespace App\Audit\Backtest;

use App\Audit\Backtest\Jobs\BuildBacktestAuditJob;
use App\Backtest\Infrastructure\Persistence\BacktestRunRecord;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/** Idempotent asynchronous audit request, separate from ordinary 1/7/14/30d PAPER exports. */
final class BacktestAuditService
{
    public function request(string $runId): array
    {
        if (! Uuid::isValid($runId)) {
            throw new InvalidArgumentException('Invalid backtest run ID.');
        }
        $run = BacktestRunRecord::query()->findOrFail($runId);
        if ($run->status !== 'COMPLETED') {
            throw new DomainException('Backtest audit is available only after completed simulation.');
        }
        $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'vector:r8:backtest:audit:'.$run->id)->toString();
        $dispatch = DB::transaction(function () use ($id, $runId): bool {
            $now = now('UTC');
            $inserted = DB::table('backtest_audit_jobs')->insertOrIgnore([
                'id' => $id, 'backtest_run_id' => $runId, 'status' => 'QUEUED',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($inserted === 1) {
                return true;
            }
            $job = DB::table('backtest_audit_jobs')->where('id', $id)->lockForUpdate()->first();
            if ($job === null || $job->backtest_run_id !== $runId) {
                throw new RuntimeException('Backtest audit idempotency conflict.');
            }
            $updatedAt = Carbon::parse($job->updated_at);
            $stale = ($job->status === 'QUEUED' && $updatedAt->lessThan($now->copy()->subMinutes(15)))
                || ($job->status === 'RUNNING' && $updatedAt->lessThan($now->copy()->subHours(2)));
            $missing = $job->status === 'COMPLETED' && (! is_file($this->path($id)) || $job->expires_at === null
                || Carbon::parse($job->expires_at)->lessThan($now));
            if (! $stale && ! $missing && $job->status !== 'FAILED') {
                return false;
            }
            DB::table('backtest_audit_jobs')->where('id', $id)->update([
                'status' => 'QUEUED', 'error_code' => null, 'archive_sha256' => null,
                'archive_bytes' => null, 'row_counts' => null, 'expires_at' => null, 'updated_at' => $now,
            ]);

            return true;
        }, attempts: 3);

        if ($dispatch) {
            BuildBacktestAuditJob::dispatch($id)->onQueue('audit');
        }

        return $this->status($id);
    }

    public function status(string $id): array
    {
        if (! Uuid::isValid($id)) {
            throw new InvalidArgumentException('Invalid audit job ID.');
        }
        $job = DB::table('backtest_audit_jobs')->where('id', $id)->first();
        if ($job === null) {
            abort(404);
        }
        $ready = $job->status === 'COMPLETED' && $job->expires_at !== null
            && now('UTC')->lessThan(Carbon::parse($job->expires_at))
            && is_file($this->path($id));

        return [
            'id' => $id, 'backtest_run_id' => $job->backtest_run_id,
            'status' => $ready ? 'READY' : ($job->status === 'COMPLETED' ? 'EXPIRED' : $job->status),
            'row_counts' => $job->row_counts ? json_decode((string) $job->row_counts, true, flags: JSON_THROW_ON_ERROR) : null,
            'archive_bytes' => $job->archive_bytes, 'archive_sha256' => $job->archive_sha256,
            'error_code' => $job->error_code,
            'download_url' => $ready ? URL::temporarySignedRoute('backtest.audit.download', now()->addMinutes(3), ['id' => $id], absolute: false) : null,
        ];
    }

    public function path(string $id): string
    {
        if (! Uuid::isValid($id)) {
            throw new InvalidArgumentException('Invalid archive ID.');
        }

        return storage_path('app/private/audit_exports/backtests/'.$id.'.zip');
    }
}
