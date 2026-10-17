<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ResearchResource;
use App\Research\Application\LedgerReconciliationService;
use App\Research\Application\StrategyHealthService;
use App\Research\Application\SystemFreshnessService;
use App\Research\Infrastructure\Persistence\ReconciliationRunRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ResearchHealthController extends Controller
{
    private function ok(mixed $data): ResearchResource
    {
        return new ResearchResource($data);
    }

    public function overview(Request $request, StrategyHealthService $health): ResearchResource|JsonResponse
    {
        try {
            $period = $request->string('period', '24h')->toString();

            return $this->ok($health->overview($period));
        } catch (Throwable $e) {
            Log::error('ResearchHealthController overview failed: '.$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve health overview: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function strategyFunnel(Request $request, StrategyHealthService $health): ResearchResource|JsonResponse
    {
        try {
            $period = $request->string('period', '7d')->toString();

            return $this->ok($health->overview($period));
        } catch (Throwable $e) {
            Log::error('ResearchHealthController strategyFunnel failed: '.$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve strategy funnel: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function reconciliationRuns(Request $request): ResearchResource|JsonResponse
    {
        try {
            $perPage = max(1, min(100, $request->integer('per_page', 10)));
            $runs = ReconciliationRunRecord::query()
                ->latest('completed_at')
                ->paginate($perPage);

            return $this->ok($runs);
        } catch (Throwable $e) {
            Log::error('ResearchHealthController reconciliationRuns failed: '.$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve reconciliation runs: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function reconcile(Request $request, LedgerReconciliationService $service): ResearchResource|JsonResponse
    {
        try {
            $accountId = $request->string('account_id')->toString();
            if ($accountId !== '') {
                $result = $service->reconcileAccount($accountId);
            } else {
                $result = $service->reconcileAll();
            }

            return $this->ok($result);
        } catch (Throwable $e) {
            Log::error('ResearchHealthController reconcile failed: '.$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Ledger reconciliation failed: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function dataFreshness(SystemFreshnessService $freshness): ResearchResource|JsonResponse
    {
        try {
            return $this->ok($freshness->check());
        } catch (Throwable $e) {
            Log::error('ResearchHealthController dataFreshness failed: '.$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Data freshness check failed: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
