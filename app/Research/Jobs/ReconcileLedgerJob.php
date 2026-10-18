<?php

declare(strict_types=1);

namespace App\Research\Jobs;

use App\Research\Application\LedgerReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class ReconcileLedgerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue('audit');
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('reconcile-ledger'))
                ->expireAfter(720)
                ->dontRelease(),
        ];
    }

    public function handle(LedgerReconciliationService $service): void
    {
        $results = $service->reconcileAll();

        $discrepancies = 0;
        $failedAccounts = [];
        foreach ($results as $res) {
            $count = (int) ($res['discrepancy_count'] ?? 0);
            $discrepancies += $count;
            if ($count > 0 || ($res['status'] ?? '') !== 'PASSED') {
                $failedAccounts[] = [
                    'account_id' => $res['paper_account_id'] ?? 'unknown',
                    'status' => $res['status'] ?? 'UNKNOWN',
                    'discrepancies' => $count,
                ];
            }
        }

        if (! empty($failedAccounts)) {
            Log::warning('Ledger reconciliation detected discrepancies in paper accounts.', [
                'accounts_count' => count($results),
                'total_discrepancies' => $discrepancies,
                'failed_accounts' => $failedAccounts,
            ]);
        } else {
            Log::info('Ledger reconciliation completed successfully for all accounts.', [
                'accounts_count' => count($results),
                'total_discrepancies' => 0,
            ]);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('Ledger reconciliation job failed on audit queue.', [
            'error' => $exception?->getMessage(),
            'trace' => $exception?->getTraceAsString(),
        ]);
    }
}
