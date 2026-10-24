<?php

declare(strict_types=1);

namespace App\Audit\Http;

use App\Audit\Application\AuditExportService;
use App\Audit\Application\StrategyFunnelService;
use App\Audit\Infrastructure\Persistence\AuditExportRecord;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class AuditExportController extends Controller
{
    public function create(Request $request, AuditExportService $service): JsonResponse
    {
        $validated = $request->validate(['days' => ['required', 'integer', 'in:1,7,14,30']]);
        $job = $service->request((int) $validated['days']);
        $status = $service->publicStatus($job);

        return response()->json(['data' => $status], $status['status'] === 'READY' ? 200 : 202);
    }

    public function index(Request $request, AuditExportService $service): JsonResponse
    {
        $jobs = AuditExportRecord::query()->latest('created_at')->paginate(min(50, max(1, $request->integer('per_page', 10))));

        return response()->json(['data' => [
            'data' => $jobs->getCollection()->map(fn (AuditExportRecord $job): array => $service->publicStatus($job))->all(),
            'current_page' => $jobs->currentPage(), 'last_page' => $jobs->lastPage(),
            'total' => $jobs->total(),
        ]]);
    }

    public function show(string $id, AuditExportService $service): JsonResponse
    {
        return response()->json(['data' => $service->publicStatus(AuditExportRecord::query()->findOrFail($id))]);
    }

    public function funnel(Request $request, StrategyFunnelService $funnel): JsonResponse
    {
        $validated = $request->validate(['days' => ['nullable', 'integer', 'in:1,7,14,30']]);
        $exchange = $request->header('X-Exchange') ?: $request->string('exchange', 'bybit')->toString();
        if (! in_array($exchange, ['bybit', 'binance'], true)) {
            $exchange = 'bybit';
        }

        return response()->json(['data' => $funnel->report((int) ($validated['days'] ?? 7), $exchange)]);
    }

    public function download(string $id, AuditExportService $service): BinaryFileResponse
    {
        $job = AuditExportRecord::query()->findOrFail($id);
        abort_unless($job->status === 'COMPLETED' && $job->expires_at?->isFuture(), 404);
        $path = $service->archivePath($job->id);
        abort_unless(is_file($path), 404);

        return response()->download($path, 'vector-audit-'.$job->period_days.'d-'.$job->id.'.zip', [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
