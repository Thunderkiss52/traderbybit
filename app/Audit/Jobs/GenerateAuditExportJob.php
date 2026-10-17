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
        app(AuditExportService::class)->failed($this->exportId, 'job_failed');
    }
}
