<?php

declare(strict_types=1);

namespace App\Audit\Backtest;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class BacktestAuditController extends Controller
{
    public function request(string $runId, BacktestAuditService $service): JsonResponse
    {
        try {
            $status = $service->request($runId);
        } catch (DomainException|InvalidArgumentException $e) {
            return response()->json(['error' => ['code' => 'AUDIT_NOT_ELIGIBLE', 'message' => $e->getMessage()]], 422);
        }

        return response()->json(['data' => $status], $status['status'] === 'READY' ? 200 : 202);
    }

    public function status(string $id, BacktestAuditService $service): JsonResponse
    {
        return response()->json(['data' => $service->status($id)]);
    }

    public function download(string $id, BacktestAuditService $service): BinaryFileResponse
    {
        $status = $service->status($id);
        abort_unless($status['status'] === 'READY', 404);

        return response()->download($service->path($id), 'vector-backtest-audit-'.$status['backtest_run_id'].'.zip', [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
