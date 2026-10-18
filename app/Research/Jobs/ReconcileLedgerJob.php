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
        foreach ($results as $res) {
            $discrepancies += (int) ($res['discrepancy_count'] ?? 0);
        }

        Log::info('Ledger reconciliation completed for all accounts.', [
            'accounts_count' => count($results),
            'total_discrepancies' => $discrepancies,
        ]);
    }
}
