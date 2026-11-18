<?php

declare(strict_types=1);

namespace App\Audit\Jobs;

use App\Audit\Application\AuditExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class GenerateAuditExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 1800;

    public array $backoff = [30, 120];

    public function __construct(public readonly string $exportId)
    {
        $this->onQueue('audit');
    }

    public function handle(AuditExportService $service): void
    {
        $service->generate($this->exportId);
    }

    public function failed(Throwable $exception): void
    {
        $export = \App\Audit\Infrastructure\Persistence\AuditExportRecord::query()->find($this->exportId);
        if ($export !== null && $export->error_code !== null && str_starts_with((string) $export->error_code, 'audit_')) {
            return;
        }
        $code = $exception instanceof \RuntimeException && str_starts_with($exception->getMessage(), 'audit_')
            ? $exception->getMessage()
            : 'job_failed';
        app(AuditExportService::class)->failed($this->exportId, $code);
    }
}
